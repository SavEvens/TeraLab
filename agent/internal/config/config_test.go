// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package config

import (
	"strings"
	"testing"
)

func TestLoadFromEnvironment(t *testing.T) {
	t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
	t.Setenv("TERALAB_AGENT_ALLOWED_UIDS", "1000,33")
	t.Setenv("TERALAB_AGENT_SOCKET", "/tmp/teralab-agent.sock")
	t.Setenv("TERALAB_AGENT_CONSOLE", "127.0.0.1:18091")
	t.Setenv("TERALAB_RUN_DIR", "/tmp/teralab-run")
	t.Setenv("TERALAB_IMAGES_DIR", "/tmp/teralab-images")
	t.Setenv("TERALAB_IMAGE_EXTRA_ROOTS", "")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("load failed: %v", err)
	}
	if cfg.SocketPath != "/tmp/teralab-agent.sock" {
		t.Fatalf("unexpected socket: %s", cfg.SocketPath)
	}
	if cfg.ConsoleAddr != "127.0.0.1:18091" {
		t.Fatalf("unexpected console address: %s", cfg.ConsoleAddr)
	}
	if !cfg.AllowedUIDs[1000] || !cfg.AllowedUIDs[33] {
		t.Fatalf("allowed UIDs not parsed: %#v", cfg.AllowedUIDs)
	}
	if len(cfg.ImageExtraRoots) != 0 {
		t.Fatalf("expected no extra image roots, got %#v", cfg.ImageExtraRoots)
	}
}

func TestLoadParsesImageExtraRoots(t *testing.T) {
	t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
	t.Setenv("TERALAB_IMAGE_EXTRA_ROOTS", "/opt/unetlab/addons::/srv/teralab/extra/")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("load failed: %v", err)
	}
	want := []string{"/opt/unetlab/addons", "/srv/teralab/extra"}
	if len(cfg.ImageExtraRoots) != len(want) {
		t.Fatalf("unexpected extra roots: %#v", cfg.ImageExtraRoots)
	}
	for index, root := range want {
		if cfg.ImageExtraRoots[index] != root {
			t.Fatalf("extra root %d: want %q, got %q", index, root, cfg.ImageExtraRoots[index])
		}
	}
	roots := cfg.ImageRoots()
	if len(roots) != len(want)+1 || roots[0] != cfg.ImagesDir {
		t.Fatalf("unexpected image roots: %#v", roots)
	}
	for index, root := range want {
		if roots[index+1] != root {
			t.Fatalf("image root %d: want %q, got %q", index+1, root, roots[index+1])
		}
	}
}

func TestLoadRejectsRelativeImageExtraRoot(t *testing.T) {
	t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
	t.Setenv("TERALAB_IMAGE_EXTRA_ROOTS", "/opt/unetlab/addons:relative/addons")

	_, err := Load()
	if err == nil {
		t.Fatal("expected a relative image extra root to be rejected")
	}
	if !strings.Contains(err.Error(), "absolute path") {
		t.Fatalf("unexpected error: %v", err)
	}
}

func TestLoadRejectsShortSecret(t *testing.T) {
	t.Setenv("TERALAB_AGENT_SECRET", "short")
	if _, err := Load(); err == nil {
		t.Fatal("expected short secret to be rejected")
	}
}

func TestLoadRejectsInvalidUID(t *testing.T) {
	t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
	t.Setenv("TERALAB_AGENT_ALLOWED_UIDS", "not-a-number")
	if _, err := Load(); err == nil {
		t.Fatal("expected invalid UID to be rejected")
	}
}

func TestLoadBridgeGroupFwdMask(t *testing.T) {
	t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
	cases := []struct {
		raw  string
		want uint16
	}{
		{raw: "", want: 0x5},
		{raw: "0x5", want: 0x5},
		{raw: "5", want: 0x5},
		{raw: "0", want: 0},
		{raw: "0x4", want: 0x4},
		{raw: "0xfff8", want: 0xfff8},
	}
	for _, testCase := range cases {
		t.Run(testCase.raw, func(t *testing.T) {
			t.Setenv("TERALAB_BRIDGE_GROUP_FWD_MASK", testCase.raw)
			cfg, err := Load()
			if err != nil {
				t.Fatalf("load: %v", err)
			}
			if cfg.BridgeGroupFwdMask != testCase.want {
				t.Fatalf("mask %q: want %#x, got %#x", testCase.raw, testCase.want, cfg.BridgeGroupFwdMask)
			}
		})
	}
}

func TestLoadRejectsInvalidBridgeGroupFwdMask(t *testing.T) {
	t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
	for _, raw := range []string{"bogus", "65536", "-1", "0x", "1,5"} {
		t.Run(raw, func(t *testing.T) {
			t.Setenv("TERALAB_BRIDGE_GROUP_FWD_MASK", raw)
			if _, err := Load(); err == nil || !strings.Contains(err.Error(), "TERALAB_BRIDGE_GROUP_FWD_MASK") {
				t.Fatalf("expected %q to be rejected, got %v", raw, err)
			}
		})
	}
}
