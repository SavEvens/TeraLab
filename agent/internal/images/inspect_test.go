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

func TestResolveUnderAny(t *testing.T) {
	base := t.TempDir()
	store := filepath.Join(base, "images")
	extra := filepath.Join(base, "addons")
	outside := filepath.Join(base, "elsewhere")
	for _, dir := range []string{store, extra, outside} {
		if err := os.MkdirAll(dir, 0o750); err != nil {
			t.Fatal(err)
		}
	}
	writeImage := func(dir, name string) string {
		t.Helper()
		path := filepath.Join(dir, name)
		if err := os.WriteFile(path, []byte("qcow2"), 0o600); err != nil {
			t.Fatal(err)
		}

		return path
	}
	storeDisk := writeImage(store, "disk.qcow2")
	extraDisk := writeImage(extra, "hda.qcow2")
	outsideDisk := writeImage(outside, "hda.qcow2")
	if err := os.Symlink(extraDisk, filepath.Join(store, "adopted.qcow2")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(outsideDisk, filepath.Join(store, "escape.qcow2")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(outsideDisk, filepath.Join(extra, "escape.qcow2")); err != nil {
		t.Fatal(err)
	}

	approved := []string{store, extra}
	cases := []struct {
		name string
		path string
		want string
		err  bool
	}{
		{name: "primary store", path: storeDisk, want: storeDisk},
		{name: "extra root", path: extraDisk, want: extraDisk},
		{name: "store link to extra root", path: filepath.Join(store, "adopted.qcow2"), want: extraDisk},
		{name: "outside all roots", path: outsideDisk, err: true},
		{name: "store link escaping all roots", path: filepath.Join(store, "escape.qcow2"), err: true},
		{name: "extra root link escaping all roots", path: filepath.Join(extra, "escape.qcow2"), err: true},
		{name: "missing target", path: filepath.Join(store, "absent.qcow2"), err: true},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			resolved, err := ResolveUnderAny(testCase.path, approved)
			if testCase.err {
				if err == nil {
					t.Fatalf("expected %q to be rejected", testCase.path)
				}

				return
			}
			if err != nil {
				t.Fatalf("resolve failed: %v", err)
			}
			if resolved != testCase.want {
				t.Fatalf("want %q, got %q", testCase.want, resolved)
			}
		})
	}
}

func TestResolveUnderStaysSingleRoot(t *testing.T) {
	base := t.TempDir()
	store := filepath.Join(base, "images")
	extra := filepath.Join(base, "addons")
	for _, dir := range []string{store, extra} {
		if err := os.MkdirAll(dir, 0o750); err != nil {
			t.Fatal(err)
		}
	}
	extraDisk := filepath.Join(extra, "hda.qcow2")
	if err := os.WriteFile(extraDisk, []byte("qcow2"), 0o600); err != nil {
		t.Fatal(err)
	}
	link := filepath.Join(store, "adopted.qcow2")
	if err := os.Symlink(extraDisk, link); err != nil {
		t.Fatal(err)
	}

	if _, err := ResolveUnder(link, store); err == nil {
		t.Fatal("ResolveUnder must keep rejecting targets outside its single root")
	}
	if resolved, err := ResolveUnder(link, extra); err != nil || resolved != extraDisk {
		t.Fatalf("ResolveUnder(extra) failed: %q %v", resolved, err)
	}
}
