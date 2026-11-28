// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"bytes"
	"encoding/binary"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestIOLPortEncoding(t *testing.T) {
	for index := 0; index < 32; index++ {
		port := iolPortByte(index)
		if got := iolInterfaceIndex(port); got != index {
			t.Fatalf("port round-trip failed for %d: %d", index, got)
		}
	}
	if iolPortByte(0) != 0x00 || iolPortByte(1) != 0x10 || iolPortByte(3) != 0x30 {
		t.Fatalf("unexpected port bytes: %x %x %x", iolPortByte(0), iolPortByte(1), iolPortByte(3))
	}
	if iolPortByte(4) != 0x01 || iolPortByte(5) != 0x11 || iolPortByte(15) != 0x33 {
		t.Fatalf("unexpected adapter mapping: %x %x %x", iolPortByte(4), iolPortByte(5), iolPortByte(15))
	}
}

func TestIOUHeader(t *testing.T) {
	header := buildIOUHeader(846, int(iolPortByte(1)), 42, int(iolPortByte(1)))
	if len(header) != iouHeaderSize {
		t.Fatalf("unexpected header size: %d", len(header))
	}
	if got := int(binary.BigEndian.Uint16(header[0:2]) & 0x7ff); got != 846 {
		t.Fatalf("unexpected destination id: %d", got)
	}
	if got := int(binary.BigEndian.Uint16(header[2:4]) & 0x7ff); got != 42 {
		t.Fatalf("unexpected source id: %d", got)
	}
	if header[4] != 0x10 || header[5] != 0x10 {
		t.Fatalf("unexpected ports: %x %x", header[4], header[5])
	}
	if header[iouMsgType] != iouMsgData {
		t.Fatalf("unexpected message type: %d", header[iouMsgType])
	}
}

func TestIOLPeerIDIsStableAndDistinct(t *testing.T) {
	for appID := 1; appID <= 1023; appID++ {
		peer := iolPeerID(appID)
		if peer < 1 || peer > 1023 {
			t.Fatalf("peer id out of range for %d: %d", appID, peer)
		}
		if peer == appID {
			t.Fatalf("peer id collides with app id %d", appID)
		}
		if peer != iolPeerID(appID) {
			t.Fatal("peer id must be deterministic")
		}
	}
}

func TestIOLNetmapLine(t *testing.T) {
	line := iolNetmapLine(846, 134, 3)
	if !strings.HasPrefix(line, "846:0/3") || !strings.Contains(line, "134:0/3") {
		t.Fatalf("unexpected netmap line: %s", line)
	}
	line = iolNetmapLine(846, 134, 4)
	if !strings.HasPrefix(line, "846:1/0") || !strings.Contains(line, "134:1/0") {
		t.Fatalf("unexpected second-adapter netmap line: %s", line)
	}
}

func useIOLSocketRoot(t *testing.T) string {
	t.Helper()
	root := t.TempDir()
	original := iolSocketRoot
	iolSocketRoot = root
	t.Cleanup(func() { iolSocketRoot = original })

	return root
}

// TestPrepareIOSocketDirRejectsSymlinkedDirectory pins that a compromised lab
// engine cannot make the root agent chown an attacker-chosen directory: the
// planted symlink is rejected and its target is left untouched.
func TestPrepareIOSocketDirRejectsSymlinkedDirectory(t *testing.T) {
	useIOLSocketRoot(t)
	const uid = 200000
	identity := labIdentity{UID: uid, GID: uid}

	cases := []struct {
		name      string
		directory string
	}{
		{name: "netio", directory: iolNetioDirectory(uid)},
		{name: "layer1", directory: iolL1Directory(uid)},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			target := t.TempDir()
			sentinel := filepath.Join(target, "sentinel")
			if err := os.WriteFile(sentinel, []byte("untouched"), 0o640); err != nil {
				t.Fatal(err)
			}
			link := testCase.directory
			if err := os.Symlink(target, link); err != nil {
				t.Fatalf("plant symlink: %v", err)
			}

			err := prepareIOSocketDir(link, link, identity)
			if err == nil {
				t.Fatal("a symlinked iol socket directory must be rejected")
			}
			if !strings.Contains(err.Error(), "not a real directory") {
				t.Fatalf("unexpected rejection: %v", err)
			}

			// The symlink must not have been repaired, removed or followed.
			info, statErr := os.Lstat(link)
			if statErr != nil {
				t.Fatalf("symlink was removed: %v", statErr)
			}
			if info.Mode()&os.ModeSymlink == 0 {
				t.Fatalf("symlink was replaced by %v", info.Mode())
			}
			data, readErr := os.ReadFile(sentinel)
			if readErr != nil || string(data) != "untouched" {
				t.Fatalf("symlink target was modified: %q %v", data, readErr)
			}
		})
	}
}

// TestPrepareIOSocketDirHappyPath pins that a missing directory is created as
// a real directory with the private socket mode.
func TestPrepareIOSocketDirHappyPath(t *testing.T) {
	useIOLSocketRoot(t)
	const uid = 200000
	identity := labIdentity{UID: uid, GID: uid}
	directory := iolNetioDirectory(uid)

	if err := prepareIOSocketDir(directory, directory, identity); err != nil {
		t.Fatalf("prepare socket dir: %v", err)
	}
	info, err := os.Lstat(directory)
	if err != nil {
		t.Fatalf("stat socket dir: %v", err)
	}
	if info.Mode()&os.ModeSymlink != 0 || !info.IsDir() {
		t.Fatalf("socket dir is not a real directory: %v", info.Mode())
	}
	if info.Mode().Perm() != 0o770 {
		t.Fatalf("socket dir mode = %04o, want 0770", info.Mode().Perm())
	}
	// A second call must validate the existing directory, not recreate it.
	if err := prepareIOSocketDir(directory, directory, identity); err != nil {
		t.Fatalf("re-prepare socket dir: %v", err)
	}
}

func TestPrepareIOSocketDirRejectsUnexpectedPath(t *testing.T) {
	useIOLSocketRoot(t)
	const uid = 200000
	identity := labIdentity{UID: uid, GID: uid}
	path := filepath.Join(iolSocketRoot, "netio"+strings.Repeat("9", 6))
	if err := prepareIOSocketDir(path, iolNetioDirectory(uid), identity); err == nil {
		t.Fatal("a path that is not netio<uid> must be rejected")
	}
}

// TestSecureIOLSocketFilePinsFilesystemInode pins that a symlink swapped in
// after binding is rejected and its target is never touched, while the real
// socket inode gets the private mode.
func TestSecureIOLSocketFilePinsFilesystemInode(t *testing.T) {
	directory := t.TempDir()
	local := filepath.Join(directory, "relay")
	victim := filepath.Join(directory, "victim")
	if err := os.WriteFile(victim, []byte("untouched"), 0o600); err != nil {
		t.Fatal(err)
	}
	conn, err := net.ListenUnixgram("unixgram", &net.UnixAddr{Name: local, Net: "unixgram"})
	if err != nil {
		t.Fatalf("bind socket: %v", err)
	}
	t.Cleanup(func() { _ = conn.Close() })

	// Move the bound socket aside and plant a symlink at the old name. The
	// helper opens O_PATH|O_NOFOLLOW, so it pins the link itself and rejects a
	// non-socket instead of following it to victim.
	bound := local + ".bound"
	if err := os.Rename(local, bound); err != nil {
		t.Fatalf("move socket: %v", err)
	}
	if err := os.Symlink(victim, local); err != nil {
		t.Fatalf("plant symlink: %v", err)
	}

	if err := secureIOLSocketFile(local, 0, 0); err == nil {
		t.Fatal("a symlinked socket path must be rejected")
	}
	victimInfo, err := os.Stat(victim)
	if err != nil {
		t.Fatalf("stat symlink target: %v", err)
	}
	if victimInfo.Mode().Perm() != 0o600 {
		t.Fatalf("symlink target mode changed to %04o", victimInfo.Mode().Perm())
	}

	if err := secureIOLSocketFile(bound, 0, 0); err != nil {
		t.Fatalf("secure socket: %v", err)
	}
	info, err := os.Stat(bound)
	if err != nil {
		t.Fatalf("stat bound socket: %v", err)
	}
	if info.Mode().Perm() != 0o660 {
		t.Fatalf("bound socket mode = %04o, want 0660", info.Mode().Perm())
	}
}

// TestSecureIOSocketDirUsesOpenedDescriptor pins that directory ownership and
// mode are applied to the opened inode. A symlink planted at the old path after
// the open cannot redirect the change to its target.
func TestSecureIOSocketDirUsesOpenedDescriptor(t *testing.T) {
	root := t.TempDir()
	directory := filepath.Join(root, "netio200000")
	if err := os.Mkdir(directory, 0o755); err != nil {
		t.Fatal(err)
	}
	victim := filepath.Join(root, "victim")
	if err := os.Mkdir(victim, 0o755); err != nil {
		t.Fatal(err)
	}
	sentinel := filepath.Join(victim, "sentinel")
	if err := os.WriteFile(sentinel, []byte("untouched"), 0o600); err != nil {
		t.Fatal(err)
	}

	file, err := openIOSocketDir(directory)
	if err != nil {
		t.Fatalf("open socket dir: %v", err)
	}
	defer file.Close()

	moved := directory + ".moved"
	if err := os.Rename(directory, moved); err != nil {
		t.Fatalf("move socket dir: %v", err)
	}
	if err := os.Symlink(victim, directory); err != nil {
		t.Fatalf("plant symlink: %v", err)
	}

	// Match the real euid/egid so the pre-existing-owner check (only enforced
	// as root) accepts the directory in either test environment.
	identity := labIdentity{UID: uint32(os.Geteuid()), GID: uint32(os.Getegid())}
	if err := secureIOSocketDir(directory, file, identity, false); err != nil {
		t.Fatalf("secure socket dir: %v", err)
	}

	info, err := os.Lstat(moved)
	if err != nil {
		t.Fatalf("stat moved dir: %v", err)
	}
	if info.Mode().Perm() != 0o770 {
		t.Fatalf("opened dir mode = %04o, want 0770", info.Mode().Perm())
	}
	victimInfo, err := os.Lstat(victim)
	if err != nil {
		t.Fatalf("stat symlink target: %v", err)
	}
	if victimInfo.Mode().Perm() != 0o755 {
		t.Fatalf("symlink target mode changed to %04o", victimInfo.Mode().Perm())
	}
	data, err := os.ReadFile(sentinel)
	if err != nil || string(data) != "untouched" {
		t.Fatalf("symlink target was modified: %q %v", data, err)
	}
}

// TestIOLSocketSecurityHasNoPathBasedOwnership guards against regressing the
// /tmp socket boundary back to path-based chmod/chown. It checks the source
// that owns netio<uid>/netl1<uid> and their socket files.
func TestIOLSocketSecurityHasNoPathBasedOwnership(t *testing.T) {
	cases := []struct {
		file    string
		banned  []string
		require string
	}{
		{
			file: "iol_netio.go",
			banned: []string{
				"os.Chmod(local", "os.Chown(local", "os.Lchown(local",
				"chownFile(local", "lchownFile(local",
				"os.Chmod(path", "os.Chown(path", "os.Lchown(path",
			},
			require: "secureIOLSocketFile(",
		},
		{
			file:    "iol_l1.go",
			banned:  []string{"os.Chmod(local", "os.Chown(local", "os.Lchown(local"},
			require: "secureIOLSocketFile(",
		},
	}
	for _, testCase := range cases {
		data, err := os.ReadFile(testCase.file)
		if err != nil {
			t.Fatalf("read %s: %v", testCase.file, err)
		}
		for _, pattern := range testCase.banned {
			if bytes.Contains(data, []byte(pattern)) {
				t.Errorf("%s still contains path-based socket change %q", testCase.file, pattern)
			}
		}
		if !bytes.Contains(data, []byte(testCase.require)) {
			t.Errorf("%s does not use the descriptor-based helper %q", testCase.file, testCase.require)
		}
	}
}
