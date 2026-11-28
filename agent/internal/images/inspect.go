// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package images

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"time"
)

const (
	FormatQCOW2 = "qcow2"
	FormatISO   = "iso"
)

type Info struct {
	Path        string `json:"path"`
	Format      string `json:"format"`
	Size        int64  `json:"size"`
	VirtualSize int64  `json:"virtual_size,omitempty"`
	BackingFile string `json:"backing_file,omitempty"`
	SHA256      string `json:"sha256"`
}

// DetectFormat sniffs qcow2 and ISO 9660 signatures without execution.
func DetectFormat(path string) (string, error) {
	file, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer file.Close()

	header := make([]byte, 4)
	if _, err := io.ReadFull(file, header); err == nil && string(header) == "QFI\xfb" {
		return FormatQCOW2, nil
	}

	sector := make([]byte, 5)
	if _, err := file.ReadAt(sector, 0x8001); err == nil && string(sector) == "CD001" {
		return FormatISO, nil
	}
	if _, err := file.ReadAt(sector, 0x8801); err == nil && string(sector) == "CD001" {
		return FormatISO, nil
	}

	return "", errors.New("unsupported image format")
}

const (
	// qemuImgTimeout bounds every qemu-img invocation.
	qemuImgTimeout = 60 * time.Second
	// maxQEMUImgOutput caps what a failed qemu-img can return.
	maxQEMUImgOutput = 64 << 10
)

// Command describes how qemu-img runs. The zero value runs as the agent user
// with the inherited environment; the agent always supplies a credential for
// the unprivileged VM user in production.
type Command struct {
	Credential *syscall.Credential
	Env        []string
	// Run is a test hook. Nil runs the prepared command with a capped output
	// buffer.
	Run func(command *exec.Cmd) ([]byte, error)
}

// Inspect validates an image and reports checksum, format, and size.
func Inspect(ctx context.Context, qemuImg, path string) (Info, error) {
	return InspectAs(ctx, qemuImg, path, Command{})
}

// InspectAs is Inspect with an explicit credential and environment. The image
// is read by the agent for the magic/checksum; qemu-img runs with the supplied
// credential so an untrusted qcow2 parser never executes as root. When the
// image is not readable by that user (for example a 0600 temporary upload), a
// root-owned 0644 staging copy is inspected instead and removed afterwards.
func InspectAs(ctx context.Context, qemuImg, path string, command Command) (Info, error) {
	stat, err := os.Stat(path)
	if err != nil {
		return Info{}, fmt.Errorf("stat image: %w", err)
	}
	if !stat.Mode().IsRegular() {
		return Info{}, errors.New("image is not a regular file")
	}

	format, err := DetectFormat(path)
	if err != nil {
		return Info{}, err
	}
	checksum, err := checksum(path)
	if err != nil {
		return Info{}, err
	}

	info := Info{
		Path:   path,
		Format: format,
		Size:   stat.Size(),
		SHA256: checksum,
	}

	if format == FormatQCOW2 {
		if err := fillQEMUInfo(ctx, qemuImg, &info, command); err != nil {
			return Info{}, err
		}
	}

	return info, nil
}

func fillQEMUInfo(ctx context.Context, qemuImg string, info *Info, command Command) error {
	if _, err := os.Stat(qemuImg); err != nil {
		return fmt.Errorf("qemu-img is not available: %w", err)
	}
	inspectPath := info.Path
	if command.Credential != nil {
		stat, err := os.Stat(info.Path)
		if err != nil {
			return fmt.Errorf("stat image for inspection: %w", err)
		}
		if !readableByCredential(stat, command.Credential) {
			staged, cleanup, stageErr := stageReadable(info.Path)
			if stageErr != nil {
				return fmt.Errorf("stage image for the VM user: %w", stageErr)
			}
			defer cleanup()
			inspectPath = staged
		}
	}

	ctx, cancel := context.WithTimeout(ctx, qemuImgTimeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, qemuImg, "info", "--output=json", inspectPath)
	if len(command.Env) > 0 {
		cmd.Env = command.Env
	}
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true, Credential: command.Credential}
	output, err := runQEMUImg(cmd, command.Run)
	if errors.Is(ctx.Err(), context.DeadlineExceeded) {
		return fmt.Errorf("qemu-img info timed out after %s", qemuImgTimeout)
	}
	if err != nil {
		return fmt.Errorf("qemu-img info: %w", err)
	}

	var parsed struct {
		Format      string `json:"format"`
		VirtualSize int64  `json:"virtual-size"`
		BackingFile string `json:"backing-filename"`
		ActualSize  int64  `json:"actual-size"`
	}
	if err := json.Unmarshal(output, &parsed); err != nil {
		return fmt.Errorf("parse qemu-img info: %w", err)
	}
	if parsed.Format != FormatQCOW2 {
		return errors.New("qemu-img reported an unexpected format")
	}
	if parsed.BackingFile != "" {
		return errors.New("base images must not have a backing file")
	}
	info.VirtualSize = parsed.VirtualSize

	return nil
}

func checksum(path string) (string, error) {
	file, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer file.Close()

	hash := sha256.New()
	if _, err := io.Copy(hash, file); err != nil {
		return "", err
	}

	return hex.EncodeToString(hash.Sum(nil)), nil
}

// ResolveUnder canonicalizes a path and confines it to root.
func ResolveUnder(path, root string) (string, error) {
	return ResolveUnderAny(path, []string{root})
}

// ResolveUnderAny canonicalizes a path and confines it to any of roots. A root
// that cannot be resolved is inert as long as another root resolves; a resolved
// target must land under one of them, so symlinks escaping every root fail.
func ResolveUnderAny(path string, roots []string) (string, error) {
	absolute, err := filepath.Abs(path)
	if err != nil {
		return "", errors.New("invalid image path")
	}
	resolved, err := filepath.EvalSymlinks(absolute)
	if err != nil {
		return "", fmt.Errorf("image does not exist: %w", err)
	}
	var rootErr error
	resolvedRoots := 0
	for _, root := range roots {
		if root == "" {
			continue
		}
		resolvedRoot, err := filepath.EvalSymlinks(root)
		if err != nil {
			if rootErr == nil {
				rootErr = fmt.Errorf("image root does not exist: %w", err)
			}
			continue
		}
		resolvedRoots++
		if pathWithinRoot(resolvedRoot, resolved) {
			return resolved, nil
		}
	}
	if resolvedRoots == 0 && rootErr != nil {
		return "", rootErr
	}

	return "", errors.New("image path is outside the image root")
}

func pathWithinRoot(root, target string) bool {
	relative, err := filepath.Rel(root, target)
	if err != nil {
		return false
	}

	return relative != ".." && !strings.HasPrefix(relative, ".."+string(os.PathSeparator))
}

// runQEMUImg executes the prepared command. Tests inject Run to inspect the
// credential and argv without requiring root.
func runQEMUImg(cmd *exec.Cmd, run func(*exec.Cmd) ([]byte, error)) ([]byte, error) {
	if run != nil {
		return run(cmd)
	}
	output := &cappedBuffer{limit: maxQEMUImgOutput}
	cmd.Stdout = output
	cmd.Stderr = output
	err := cmd.Run()

	return output.Bytes(), err
}

// cappedBuffer keeps the first limit bytes and discards the rest.
type cappedBuffer struct {
	buffer bytes.Buffer
	limit  int
}

func (b *cappedBuffer) Write(data []byte) (int, error) {
	remaining := b.limit - b.buffer.Len()
	if remaining > 0 {
		if len(data) > remaining {
			_, _ = b.buffer.Write(data[:remaining])
		} else {
			_, _ = b.buffer.Write(data)
		}
	}

	return len(data), nil
}

func (b *cappedBuffer) Bytes() []byte {
	return b.buffer.Bytes()
}

// readableByCredential reports whether the credential could open the file for
// reading, using the standard owner/group/other rules.
func readableByCredential(info os.FileInfo, credential *syscall.Credential) bool {
	if credential == nil || credential.Uid == 0 {
		return true
	}
	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		return false
	}
	mode := info.Mode().Perm()
	if uint32(stat.Uid) == credential.Uid {
		return mode&0o400 != 0
	}
	if uint32(stat.Gid) == credential.Gid {
		return mode&0o040 != 0
	}
	for _, group := range credential.Groups {
		if uint32(stat.Gid) == group {
			return mode&0o040 != 0
		}
	}

	return mode&0o004 != 0
}

// stageReadable copies a source that the VM user cannot read into a temporary
// 0644 file. Only the inspection copy is exposed; the live image store keeps
// its ownership and mode, and the caller removes the copy immediately.
func stageReadable(source string) (string, func(), error) {
	src, err := os.Open(source)
	if err != nil {
		return "", nil, err
	}
	defer src.Close()

	staged, err := os.CreateTemp("", "teralab-inspect-*")
	if err != nil {
		return "", nil, err
	}
	cleanup := func() {
		_ = os.Remove(staged.Name())
	}
	if _, err := io.Copy(staged, src); err != nil {
		_ = staged.Close()
		cleanup()

		return "", nil, err
	}
	if err := staged.Chmod(0o644); err != nil {
		_ = staged.Close()
		cleanup()

		return "", nil, err
	}
	if err := staged.Close(); err != nil {
		cleanup()

		return "", nil, err
	}

	return staged.Name(), cleanup, nil
}
