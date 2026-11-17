// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func testSpec(t *testing.T) (Spec, string) {
	t.Helper()
	images := t.TempDir()
	disk := filepath.Join(images, "disk.qcow2")
	if err := os.WriteFile(disk, []byte("qcow2"), 0o600); err != nil {
		t.Fatal(err)
	}

	spec := Spec{
		ID:       "node-1",
		LabID:    "lab-1",
		Name:     "R1",
		Image:    disk,
		Ethernet: 1,
		Networks: []string{"br0"},
	}
	spec.Normalize()

	return spec, images
}

func TestSpecNormalizeAndValidate(t *testing.T) {
	spec, images := testSpec(t)
	if err := spec.Validate(images); err != nil {
		t.Fatalf("validation failed: %v", err)
	}
	if spec.Console != "telnet" {
		t.Fatalf("unexpected console: %s", spec.Console)
	}
	if spec.TapName(0) == spec.TapName(1) {
		t.Fatal("tap names must be unique per interface")
	}
	if !strings.HasPrefix(spec.TapName(0), "tl") || len(spec.TapName(0)) > 15 {
		t.Fatalf("invalid tap name: %s", spec.TapName(0))
	}
	if !strings.HasPrefix(spec.MAC(0), "52:54:") {
		t.Fatalf("invalid MAC: %s", spec.MAC(0))
	}
}

func TestSpecRejectsImageOutsideRoot(t *testing.T) {
	_, images := testSpec(t)
	outside := filepath.Join(t.TempDir(), "outside.qcow2")
	if err := os.WriteFile(outside, []byte("qcow2"), 0o600); err != nil {
		t.Fatal(err)
	}

	spec := Spec{
		ID:    "node-1",
		Name:  "R1",
		Image: outside,
	}
	spec.Normalize()

	if err := spec.Validate(images); err == nil {
		t.Fatal("expected image outside root to be rejected")
	}
}

func TestSpecRejectsInvalidBridgeAndConsole(t *testing.T) {
	spec, images := testSpec(t)
	spec.Networks[0] = "this-bridge-name-is-too-long"
	if err := spec.Validate(images); err == nil {
		t.Fatal("expected invalid bridge to be rejected")
	}

	spec, images = testSpec(t)
	spec.Console = "spice"
	if err := spec.Validate(images); err == nil {
		t.Fatal("expected unsupported console to be rejected")
	}

	spec, images = testSpec(t)
	spec.Console = "vnc"
	if err := spec.Validate(images); err != nil {
		t.Fatalf("expected vnc console to be accepted: %v", err)
	}
}

func TestBuildQEMUArgs(t *testing.T) {
	spec, _ := testSpec(t)
	taps := []string{spec.TapName(0)}

	args, err := BuildQEMUArgs(spec, taps, "/tmp/overlay.qcow2", nil)
	if err != nil {
		t.Fatalf("build args failed: %v", err)
	}
	joined := strings.Join(args, " ")
	for _, expected := range []string{
		"-machine pc",
		"-serial stdio",
		"-display none",
		"-monitor none",
		"-netdev tap,id=net0,ifname=" + taps[0],
		"-drive file=/tmp/overlay.qcow2,if=virtio,format=qcow2,cache=none",
		"-accel",
	} {
		if !strings.Contains(joined, expected) {
			t.Fatalf("expected %q in %s", expected, joined)
		}
	}
	if strings.Contains(joined, "-nodefconfig") {
		t.Fatal("removed QEMU option must not be present")
	}
}

func TestBuildQEMUArgsRejectsTapMismatch(t *testing.T) {
	spec, _ := testSpec(t)
	spec.Ethernet = 2
	spec.Networks = []string{"br0", "br1"}

	if _, err := BuildQEMUArgs(spec, []string{"tl1"}, "", nil); err == nil {
		t.Fatal("expected tap mismatch to be rejected")
	}
}

func TestBuildQEMUArgsUsesISO(t *testing.T) {
	images := t.TempDir()
	iso := filepath.Join(images, "test.iso")
	if err := os.WriteFile(iso, []byte("iso"), 0o600); err != nil {
		t.Fatal(err)
	}
	spec := Spec{ID: "node-iso", Name: "ISO", ISO: iso}
	spec.Normalize()
	if err := spec.Validate(images); err != nil {
		t.Fatalf("validation failed: %v", err)
	}

	args, err := BuildQEMUArgs(spec, nil, "", nil)
	if err != nil {
		t.Fatalf("build args failed: %v", err)
	}
	joined := strings.Join(args, " ")
	if !strings.Contains(joined, "-cdrom "+iso) || !strings.Contains(joined, "-boot order=d") {
		t.Fatalf("expected ISO boot flags in %s", joined)
	}
}

func TestBuildQEMUArgsForVNC(t *testing.T) {
	spec, _ := testSpec(t)
	spec.Console = "vnc"
	vnc := &VNCConfig{Display: 7, MonitorPath: "/tmp/monitor.sock"}

	args, err := BuildQEMUArgs(spec, []string{spec.TapName(0)}, "/tmp/overlay.qcow2", vnc)
	if err != nil {
		t.Fatalf("build args failed: %v", err)
	}
	joined := strings.Join(args, " ")
	for _, expected := range []string{
		"-vnc 127.0.0.1:7,password=on",
		"-monitor unix:/tmp/monitor.sock,server=on,wait=off",
		"-serial none",
	} {
		if !strings.Contains(joined, expected) {
			t.Fatalf("expected %q in %s", expected, joined)
		}
	}
	if strings.Contains(joined, "-serial stdio") {
		t.Fatal("vnc nodes must not attach a serial stdio console")
	}
	if strings.Contains(joined, "-monitor none") {
		t.Fatal("vnc nodes need the monitor socket")
	}
}

func TestBuildQEMUArgsRequiresVNCConfigForVNC(t *testing.T) {
	spec, _ := testSpec(t)
	spec.Console = "vnc"

	if _, err := BuildQEMUArgs(spec, []string{spec.TapName(0)}, "", nil); err == nil {
		t.Fatal("expected missing vnc configuration to be rejected")
	}
}

func TestBuildQEMUArgsRejectsVNCConfigForSerial(t *testing.T) {
	spec, _ := testSpec(t)

	if _, err := BuildQEMUArgs(spec, []string{spec.TapName(0)}, "", &VNCConfig{Display: 1}); err == nil {
		t.Fatal("expected vnc configuration on a serial node to be rejected")
	}
}

func TestFreeVNCDisplayIsUnique(t *testing.T) {
	first, err := freeVNCDisplay()
	if err != nil {
		t.Fatalf("free display failed: %v", err)
	}
	if first < 1 || first > 99 {
		t.Fatalf("unexpected display: %d", first)
	}
	if len(randomHex(8)) != 16 {
		t.Fatal("expected a 16-character vnc password")
	}
}
