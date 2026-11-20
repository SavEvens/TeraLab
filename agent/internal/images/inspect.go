// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package images

import (
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

// Inspect validates an image and reports checksum, format, and size.
func Inspect(ctx context.Context, qemuImg, path string) (Info, error) {
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
		if err := fillQEMUInfo(ctx, qemuImg, &info); err != nil {
			return Info{}, err
		}
	}

	return info, nil
}

func fillQEMUInfo(ctx context.Context, qemuImg string, info *Info) error {
	if _, err := os.Stat(qemuImg); err != nil {
		return fmt.Errorf("qemu-img is not available: %w", err)
	}
	output, err := exec.CommandContext(ctx, qemuImg, "info", "--output=json", info.Path).Output()
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
