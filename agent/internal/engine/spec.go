// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"unicode/utf8"
)

var (
	idPattern      = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$`)
	nicPattern     = regexp.MustCompile(`^[A-Za-z0-9_.-]+$`)
	machinePattern = regexp.MustCompile(`^(pc|q35|pc-i440fx-[0-9.]+|pc-q35-[0-9.]+)$`)
	bridgePattern  = regexp.MustCompile(`^[A-Za-z0-9_.:-]{1,15}$`)
	uuidPattern    = regexp.MustCompile(`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`)
)

type Spec struct {
	ID       string   `json:"id"`
	LabID    string   `json:"lab_id"`
	Name     string   `json:"name"`
	Image    string   `json:"image,omitempty"`
	ISO      string   `json:"iso,omitempty"`
	Kernel   string   `json:"kernel,omitempty"`
	Initrd   string   `json:"initrd,omitempty"`
	Append   string   `json:"append,omitempty"`
	CPU      int      `json:"cpu"`
	RAM      int      `json:"ram"`
	Ethernet int      `json:"ethernet"`
	Networks []string `json:"networks,omitempty"`
	NIC      string   `json:"nic,omitempty"`
	Machine  string   `json:"machine,omitempty"`
	Console  string   `json:"console,omitempty"`
	UUID     string   `json:"uuid,omitempty"`
}

func (s *Spec) Normalize() {
	if s.CPU == 0 {
		s.CPU = 1
	}
	if s.RAM == 0 {
		s.RAM = 512
	}
	if s.NIC == "" {
		s.NIC = "e1000"
	}
	if s.Machine == "" {
		s.Machine = "pc"
	}
	if s.Console == "" {
		s.Console = "telnet"
	}
	if s.UUID == "" {
		s.UUID = randomUUID()
	}
	for len(s.Networks) < s.Ethernet {
		s.Networks = append(s.Networks, "")
	}
}

func (s Spec) Validate(imagesDir string) error {
	if !idPattern.MatchString(s.ID) {
		return errors.New("invalid node id")
	}
	if s.LabID != "" && !idPattern.MatchString(s.LabID) {
		return errors.New("invalid lab id")
	}
	if strings.TrimSpace(s.Name) == "" || utf8.RuneCountInString(s.Name) > 128 {
		return errors.New("invalid node name")
	}
	if s.CPU < 1 || s.CPU > 64 {
		return errors.New("cpu must be between 1 and 64")
	}
	if s.RAM < 64 || s.RAM > 131072 {
		return errors.New("ram must be between 64 and 131072 MiB")
	}
	if s.Ethernet < 0 || s.Ethernet > 16 {
		return errors.New("ethernet must be between 0 and 16")
	}
	if len(s.Networks) != s.Ethernet {
		return errors.New("networks length must match ethernet")
	}
	for _, bridge := range s.Networks {
		if bridge != "" && !bridgePattern.MatchString(bridge) {
			return fmt.Errorf("invalid bridge name %q", bridge)
		}
	}
	if !nicPattern.MatchString(s.NIC) {
		return errors.New("invalid nic driver")
	}
	if !machinePattern.MatchString(s.Machine) {
		return errors.New("invalid machine type")
	}
	if s.Console != "telnet" && s.Console != "vnc" {
		return errors.New("console must be either telnet or vnc")
	}
	if !uuidPattern.MatchString(s.UUID) {
		return errors.New("invalid uuid")
	}
	if s.Image != "" {
		if err := validateImagePath(s.Image, imagesDir, ".qcow2"); err != nil {
			return err
		}
	}
	if s.ISO != "" {
		if err := validateImagePath(s.ISO, imagesDir, ".iso"); err != nil {
			return err
		}
	}
	if s.Kernel != "" {
		if err := validateFilePath(s.Kernel, imagesDir); err != nil {
			return err
		}
	}
	if s.Initrd != "" {
		if err := validateFilePath(s.Initrd, imagesDir); err != nil {
			return err
		}
	}
	if len(s.Append) > 256 || strings.ContainsAny(s.Append, "\r\n\x00") {
		return errors.New("invalid kernel append string")
	}

	return nil
}

func (s Spec) TapName(index int) string {
	sum := sha256.Sum256([]byte(s.ID + ":tap:" + fmt.Sprint(index)))

	return "tl" + hex.EncodeToString(sum[:4])
}

func (s Spec) MAC(index int) string {
	sum := sha256.Sum256([]byte(s.ID + ":mac:" + fmt.Sprint(index)))

	return fmt.Sprintf("52:54:%02x:%02x:%02x:%02x", sum[0], sum[1], sum[2], sum[3])
}

func validateImagePath(path, root, extension string) error {
	resolved, err := resolveFile(path, root)
	if err != nil {
		return err
	}
	if strings.ToLower(filepath.Ext(resolved)) != extension {
		return fmt.Errorf("image must have %s extension", extension)
	}

	return nil
}

func validateFilePath(path, root string) error {
	_, err := resolveFile(path, root)

	return err
}

func resolveFile(path, root string) (string, error) {
	absolute, err := filepath.Abs(path)
	if err != nil {
		return "", errors.New("invalid file path")
	}
	resolved, err := filepath.EvalSymlinks(absolute)
	if err != nil {
		return "", fmt.Errorf("file does not exist: %w", err)
	}
	resolvedRoot, err := filepath.EvalSymlinks(root)
	if err != nil {
		return "", fmt.Errorf("image root does not exist: %w", err)
	}
	if resolved != resolvedRoot && !strings.HasPrefix(resolved, resolvedRoot+string(os.PathSeparator)) {
		return "", errors.New("file path is outside the image root")
	}
	info, err := os.Stat(resolved)
	if err != nil || info.IsDir() {
		return "", errors.New("file path is not a regular file")
	}

	return resolved, nil
}

func randomUUID() string {
	raw := make([]byte, 16)
	if _, err := rand.Read(raw); err != nil {
		return ""
	}
	raw[6] = (raw[6] & 0x0f) | 0x40
	raw[8] = (raw[8] & 0x3f) | 0x80

	return fmt.Sprintf(
		"%x-%x-%x-%x-%x",
		raw[0:4],
		raw[4:6],
		raw[6:8],
		raw[8:10],
		raw[10:16],
	)
}
