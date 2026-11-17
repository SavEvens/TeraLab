// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package images

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
)

func writeISO(t *testing.T, dir string) string {
	t.Helper()
	path := filepath.Join(dir, "test.iso")
	data := make([]byte, 0x9000)
	copy(data[0x8001:], "CD001")
	if err := os.WriteFile(path, data, 0o600); err != nil {
		t.Fatal(err)
	}

	return path
}

func writeQCOW2(t *testing.T, dir string) string {
	t.Helper()
	path := filepath.Join(dir, "test.qcow2")
	header := make([]byte, 1024)
	copy(header, "QFI\xfb")
	if err := os.WriteFile(path, header, 0o600); err != nil {
		t.Fatal(err)
	}

	return path
}

func TestDetectFormat(t *testing.T) {
	dir := t.TempDir()
	format, err := DetectFormat(writeISO(t, dir))
	if err != nil || format != FormatISO {
		t.Fatalf("iso detection failed: %q %v", format, err)
	}
	format, err = DetectFormat(writeQCOW2(t, dir))
	if err != nil || format != FormatQCOW2 {
		t.Fatalf("qcow2 detection failed: %q %v", format, err)
	}

	invalid := filepath.Join(dir, "invalid.img")
	if err := os.WriteFile(invalid, []byte("not an image"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := DetectFormat(invalid); err == nil {
		t.Fatal("expected invalid image to be rejected")
	}
}

func TestInspectISO(t *testing.T) {
	dir := t.TempDir()
	path := writeISO(t, dir)

	info, err := Inspect(context.Background(), "/nonexistent/qemu-img", path)
	if err != nil {
		t.Fatalf("inspect iso: %v", err)
	}
	if info.Format != FormatISO {
		t.Fatalf("unexpected format: %s", info.Format)
	}
	if len(info.SHA256) != 64 {
		t.Fatalf("unexpected checksum: %s", info.SHA256)
	}
	if info.Size != 0x9000 {
		t.Fatalf("unexpected size: %d", info.Size)
	}
}

func TestInspectQCOW2RequiresQEMUImg(t *testing.T) {
	dir := t.TempDir()
	path := writeQCOW2(t, dir)

	if _, err := Inspect(context.Background(), "/nonexistent/qemu-img", path); err == nil {
		t.Fatal("expected missing qemu-img to be reported")
	}
}

func TestInspectRealQCOW2(t *testing.T) {
	qemuImg, err := exec.LookPath("qemu-img")
	if err != nil {
		t.Skip("qemu-img is not installed")
	}
	dir := t.TempDir()
	path := filepath.Join(dir, "disk.qcow2")
	if output, err := exec.Command(qemuImg, "create", "-f", "qcow2", path, "8M").CombinedOutput(); err != nil {
		t.Fatalf("create image: %s: %v", output, err)
	}

	info, err := Inspect(context.Background(), qemuImg, path)
	if err != nil {
		t.Fatalf("inspect: %v", err)
	}
	if info.VirtualSize != 8*1024*1024 {
		t.Fatalf("unexpected virtual size: %d", info.VirtualSize)
	}
	if info.Format != FormatQCOW2 || info.BackingFile != "" {
		t.Fatalf("unexpected qcow2 info: %+v", info)
	}
}

func TestResolveUnderRejectsEscape(t *testing.T) {
	root := t.TempDir()
	inside := filepath.Join(root, "disk.qcow2")
	if err := os.WriteFile(inside, []byte("x"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := ResolveUnder(inside, root); err != nil {
		t.Fatalf("inside path rejected: %v", err)
	}
	outside := filepath.Join(t.TempDir(), "disk.qcow2")
	if err := os.WriteFile(outside, []byte("x"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := ResolveUnder(outside, root); err == nil {
		t.Fatal("outside path accepted")
	}
}
