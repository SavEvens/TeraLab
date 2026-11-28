// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package images

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
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

func TestInspectRunsQEMUImgAsVMUserAndStagesUnreadableFiles(t *testing.T) {
	path := writeQCOW2(t, t.TempDir())
	qemuImg := filepath.Join(t.TempDir(), "qemu-img")
	if err := os.WriteFile(qemuImg, []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
		t.Fatal(err)
	}

	var inspected string
	var executedCredential *syscall.Credential
	var stagedMode os.FileMode
	var stagedData []byte
	command := Command{
		Credential: &syscall.Credential{Uid: 997, Gid: 989, Groups: []uint32{993}},
		Env:        []string{"PATH=/usr/bin"},
		Run: func(cmd *exec.Cmd) ([]byte, error) {
			executedCredential = cmd.SysProcAttr.Credential
			inspected = cmd.Args[len(cmd.Args)-1]
			info, err := os.Stat(inspected)
			if err != nil {
				return nil, err
			}
			stagedMode = info.Mode().Perm()
			stagedData, _ = os.ReadFile(inspected)

			return []byte(`{"format":"qcow2","virtual-size":1024}`), nil
		},
	}

	if _, err := InspectAs(context.Background(), qemuImg, path, command); err != nil {
		t.Fatalf("inspect: %v", err)
	}
	if executedCredential == nil || executedCredential.Uid != 997 || executedCredential.Gid != 989 {
		t.Fatalf("qemu-img must run as the VM user, got %+v", executedCredential)
	}
	if inspected == path {
		t.Fatal("a 0600 upload must be staged for the VM user")
	}
	if stagedMode != 0o644 {
		t.Fatalf("staging copy mode = %04o, want 0644", stagedMode)
	}
	if len(stagedData) == 0 {
		t.Fatal("staging copy is empty")
	}
	if _, err := os.Stat(inspected); !os.IsNotExist(err) {
		t.Fatalf("staging copy was not removed: %v", err)
	}
	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Fatalf("the live upload mode changed to %04o", info.Mode().Perm())
	}
}

func TestInspectWithoutCredentialInspectsInPlace(t *testing.T) {
	path := writeQCOW2(t, t.TempDir())
	qemuImg := filepath.Join(t.TempDir(), "qemu-img")
	if err := os.WriteFile(qemuImg, []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	var inspected string
	command := Command{Run: func(cmd *exec.Cmd) ([]byte, error) {
		inspected = cmd.Args[len(cmd.Args)-1]
		if cmd.SysProcAttr.Credential != nil {
			t.Fatalf("unexpected credential: %+v", cmd.SysProcAttr.Credential)
		}

		return []byte(`{"format":"qcow2","virtual-size":1024}`), nil
	}}
	if _, err := InspectAs(context.Background(), qemuImg, path, command); err != nil {
		t.Fatalf("inspect: %v", err)
	}
	if inspected != path {
		t.Fatalf("inspection path = %s, want %s", inspected, path)
	}
}
