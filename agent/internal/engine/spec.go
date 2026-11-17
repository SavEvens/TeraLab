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
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/SavEvens/TeraLab/agent/internal/network"
)

var (
	idPattern       = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$`)
	nicPattern      = regexp.MustCompile(`^[A-Za-z0-9_.-]+$`)
	machinePattern  = regexp.MustCompile(`^(pc|q35|pc-i440fx-[0-9.]+|pc-q35-[0-9.]+)$`)
	bridgePattern   = regexp.MustCompile(`^[A-Za-z0-9_.:-]{1,15}$`)
	uuidPattern     = regexp.MustCompile(`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`)
	platformPattern = regexp.MustCompile(`^(c7200|c3600|c2691|c3725|c3745|c1700|c2600)$`)
	slotPattern     = regexp.MustCompile(`^[0-9]{1,2}:[0-9]{1,2}:(tap|linux|unix)$`)
)

// Engines lists the supported node engines.
var Engines = []string{"qemu", "vpcs", "dynamips", "iol"}

type Spec struct {
	ID       string   `json:"id"`
	LabID    string   `json:"lab_id"`
	Name     string   `json:"name"`
	Engine   string   `json:"engine,omitempty"`
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
	Platform string   `json:"platform,omitempty"`
	NVRAM    int      `json:"nvram,omitempty"`
	Slots    []string `json:"slots,omitempty"`
	Iourc    string   `json:"iourc,omitempty"`
}

func (s *Spec) Normalize() {
	if s.Engine == "" {
		s.Engine = "qemu"
	}
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
	if s.Platform == "" {
		s.Platform = "c7200"
	}
	if s.NVRAM == 0 {
		s.NVRAM = 512
	}
	if s.UUID == "" {
		s.UUID = randomUUID()
	}
	for len(s.Networks) < s.Ethernet {
		s.Networks = append(s.Networks, "")
	}
	if s.Engine == "dynamips" && len(s.Slots) == 0 {
		for index := 0; index < s.Ethernet; index++ {
			s.Slots = append(s.Slots, "0:"+strconv.Itoa(index)+":tap")
		}
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
	if s.Console != "telnet" && s.Console != "vnc" {
		return errors.New("console must be either telnet or vnc")
	}
	if !uuidPattern.MatchString(s.UUID) {
		return errors.New("invalid uuid")
	}

	switch s.Engine {
	case "qemu", "":
		return s.validateQEMU(imagesDir)
	case "vpcs":
		return s.validateVPCS()
	case "dynamips":
		return s.validateDynamips(imagesDir)
	case "iol":
		return s.validateIOL(imagesDir)
	default:
		return fmt.Errorf("unsupported engine %q", s.Engine)
	}
}

func (s Spec) validateQEMU(imagesDir string) error {
	if !nicPattern.MatchString(s.NIC) {
		return errors.New("invalid nic driver")
	}
	if !machinePattern.MatchString(s.Machine) {
		return errors.New("invalid machine type")
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

func (s Spec) validateVPCS() error {
	if s.Ethernet != 1 {
		return errors.New("vpcs nodes require exactly one ethernet interface")
	}
	if s.Console != "telnet" {
		return errors.New("vpcs supports only a telnet console")
	}
	if s.Image != "" || s.ISO != "" || s.Kernel != "" {
		return errors.New("vpcs does not accept disk or kernel images")
	}

	return nil
}

func (s Spec) validateDynamips(imagesDir string) error {
	if !platformPattern.MatchString(s.Platform) {
		return errors.New("invalid dynamips platform")
	}
	if s.Console != "telnet" {
		return errors.New("dynamips supports only a telnet console")
	}
	if s.Image == "" {
		return errors.New("dynamips requires an IOS image")
	}
	if err := validateImagePath(s.Image, imagesDir, ".bin"); err != nil {
		return err
	}
	if s.RAM < 64 || s.RAM > 8192 {
		return errors.New("dynamips ram must be between 64 and 8192 MiB")
	}
	if s.NVRAM < 64 || s.NVRAM > 4096 {
		return errors.New("dynamips nvram must be between 64 and 4096 KiB")
	}
	for _, slot := range s.Slots {
		if !slotPattern.MatchString(slot) {
			return fmt.Errorf("invalid dynamips slot %q", slot)
		}
	}

	return nil
}

func (s Spec) validateIOL(imagesDir string) error {
	if s.Console != "telnet" {
		return errors.New("iol supports only a telnet console")
	}
	if s.Image == "" {
		return errors.New("iol requires an image")
	}
	if err := validateImagePath(s.Image, imagesDir, ".bin"); err != nil {
		return err
	}
	if s.Ethernet != 0 {
		return errors.New("iol ethernet bridging is not implemented; set ethernet to 0")
	}
	if s.Iourc != "" {
		if err := validateFilePath(s.Iourc, imagesDir); err != nil {
			return err
		}
	}

	return nil
}

func (s Spec) TapName(index int) string {
	return network.TapName(s.LabID, s.ID, index)
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

// RandomHex returns a hex string of n random bytes.
func RandomHex(n int) string {
	raw := make([]byte, n)
	if _, err := rand.Read(raw); err != nil {
		return ""
	}

	return hex.EncodeToString(raw)
}
