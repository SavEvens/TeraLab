// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package config

import (
	"os"
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

// TestLoadRejectsEmptyAllowedUIDs pins the Phase 04 fail-closed contract: the
// shipped unit sets the variable empty until the identity drop-in supplies the
// API UID, so an empty value must stop the agent instead of falling back to
// www-data (33).
func TestLoadRejectsEmptyAllowedUIDs(t *testing.T) {
	for _, value := range []string{"", "   ", ",", " , ", "\t\n"} {
		t.Run(value, func(t *testing.T) {
			t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
			t.Setenv("TERALAB_AGENT_ALLOWED_UIDS", value)
			cfg, err := Load()
			if err == nil {
				t.Fatalf("value %q was accepted with UIDs %#v", value, cfg.AllowedUIDs)
			}
			if !strings.Contains(err.Error(), "TERALAB_AGENT_ALLOWED_UIDS") {
				t.Fatalf("error should name the variable: %v", err)
			}
			if strings.Contains(err.Error(), "invalid allowed UID") {
				t.Fatalf("empty value should report an empty list, not a parse error: %v", err)
			}
		})
	}
}

// TestLoadDefaultsAllowedUIDsWhenUnset keeps the documented default for direct
// invocations that never set the variable. The shipped unit always sets it, so
// this default cannot mask a missing identity drop-in.
func TestLoadDefaultsAllowedUIDsWhenUnset(t *testing.T) {
	t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
	unsetEnv(t, "TERALAB_AGENT_ALLOWED_UIDS")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("load failed: %v", err)
	}
	if len(cfg.AllowedUIDs) != 1 || !cfg.AllowedUIDs[33] {
		t.Fatalf("unset variable must keep the documented uid 33 default, got %#v", cfg.AllowedUIDs)
	}
}

func TestLoadParsesAllowedUIDList(t *testing.T) {
	t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
	t.Setenv("TERALAB_AGENT_ALLOWED_UIDS", " 1000, 33 ,1000,")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("load failed: %v", err)
	}
	if len(cfg.AllowedUIDs) != 2 || !cfg.AllowedUIDs[1000] || !cfg.AllowedUIDs[33] {
		t.Fatalf("allowed UIDs not parsed and deduplicated: %#v", cfg.AllowedUIDs)
	}
}

// unsetEnv removes key for the duration of the test. t.Setenv cannot express
// "absent", which is exactly the distinction allowedAgentUIDs has to make.
func unsetEnv(t *testing.T, key string) {
	t.Helper()
	previous, present := os.LookupEnv(key)
	if err := os.Unsetenv(key); err != nil {
		t.Fatalf("unset %s: %v", key, err)
	}
	t.Cleanup(func() {
		if present {
			_ = os.Setenv(key, previous)
			return
		}
		_ = os.Unsetenv(key)
	})
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

func TestLoadDockerBinary(t *testing.T) {
	t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
	t.Setenv("TERALAB_DOCKER", "/opt/teralab/bin/docker")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("load failed: %v", err)
	}
	if cfg.DockerBinary != "/opt/teralab/bin/docker" {
		t.Fatalf("unexpected docker binary: %s", cfg.DockerBinary)
	}
}

func TestLoadDockerBinaryDefaultsToUsrBinDocker(t *testing.T) {
	t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
	t.Setenv("TERALAB_DOCKER", "")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("load failed: %v", err)
	}
	if cfg.DockerBinary != "/usr/bin/docker" {
		t.Fatalf("unexpected docker binary default: %s", cfg.DockerBinary)
	}
}

func TestLoadStateDirDefaultAndOverride(t *testing.T) {
	t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
	cfg, err := Load()
	if err != nil {
		t.Fatalf("load failed: %v", err)
	}
	if cfg.StateDir != "/var/lib/teralab/state" {
		t.Fatalf("unexpected default StateDir: %q", cfg.StateDir)
	}

	t.Setenv("TERALAB_STATE_DIR", "/srv/teralab/state")
	cfg, err = Load()
	if err != nil {
		t.Fatalf("load failed: %v", err)
	}
	if cfg.StateDir != "/srv/teralab/state" {
		t.Fatalf("unexpected StateDir override: %q", cfg.StateDir)
	}
}

func TestLoadPerLabUIDDefaultsAndOverrides(t *testing.T) {
	t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
	cfg, err := Load()
	if err != nil {
		t.Fatalf("load failed: %v", err)
	}
	if !cfg.PerLabUID {
		t.Fatal("per-lab uids must be enabled by default")
	}
	if cfg.LabUIDFirst != 200000 || cfg.LabUIDSlots != 64 || cfg.ImageGID != 0 {
		t.Fatalf("unexpected per-lab uid defaults: first=%d slots=%d image_gid=%d",
			cfg.LabUIDFirst, cfg.LabUIDSlots, cfg.ImageGID)
	}

	t.Setenv("TERALAB_PER_LAB_UID", "0")
	t.Setenv("TERALAB_LAB_UID_FIRST", "210000")
	t.Setenv("TERALAB_LAB_UID_SLOTS", "8")
	t.Setenv("TERALAB_IMAGE_GID", "4242")
	cfg, err = Load()
	if err != nil {
		t.Fatalf("load failed: %v", err)
	}
	if cfg.PerLabUID {
		t.Fatal("TERALAB_PER_LAB_UID=0 must disable per-lab uids")
	}
	if cfg.LabUIDFirst != 210000 || cfg.LabUIDSlots != 8 || cfg.ImageGID != 4242 {
		t.Fatalf("override not applied: first=%d slots=%d image_gid=%d",
			cfg.LabUIDFirst, cfg.LabUIDSlots, cfg.ImageGID)
	}
}

func TestLoadRejectsInvalidLabUIDPool(t *testing.T) {
	cases := []struct {
		name  string
		first string
		slots string
		want  string
	}{
		{name: "below reserved range", first: "1000", slots: "4", want: "TERALAB_LAB_UID_FIRST"},
		{name: "zero slots", first: "200000", slots: "0", want: "TERALAB_LAB_UID_SLOTS"},
		{name: "u32 overflow", first: "4294967290", slots: "16", want: "uid range"},
		{name: "not a number", first: "abc", slots: "4", want: "TERALAB_LAB_UID_FIRST"},
		{name: "negative slots", first: "200000", slots: "-1", want: "TERALAB_LAB_UID_SLOTS"},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
			t.Setenv("TERALAB_LAB_UID_FIRST", testCase.first)
			t.Setenv("TERALAB_LAB_UID_SLOTS", testCase.slots)
			_, err := Load()
			if err == nil || !strings.Contains(err.Error(), testCase.want) {
				t.Fatalf("expected %q to be rejected, got %v", testCase.name, err)
			}
		})
	}
}

func TestLoadRejectsInvalidImageGID(t *testing.T) {
	t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
	t.Setenv("TERALAB_IMAGE_GID", "not-a-gid")
	if _, err := Load(); err == nil || !strings.Contains(err.Error(), "TERALAB_IMAGE_GID") {
		t.Fatalf("expected an invalid image gid to be rejected, got %v", err)
	}
}

func TestLoadDockerPullAndQEMUSandboxFlags(t *testing.T) {
	t.Setenv("TERALAB_AGENT_SECRET", strings.Repeat("s", 32))
	cfg, err := Load()
	if err != nil {
		t.Fatalf("load failed: %v", err)
	}
	if cfg.DisableDockerPull {
		t.Fatal("docker pull must stay enabled by default for admin deployments")
	}
	if !cfg.QEMUSandbox {
		t.Fatal("the QEMU sandbox must be enabled by default")
	}

	t.Setenv("TERALAB_DISABLE_DOCKER_PULL", "true")
	t.Setenv("TERALAB_QEMU_SANDBOX", "false")
	cfg, err = Load()
	if err != nil {
		t.Fatalf("load failed: %v", err)
	}
	if !cfg.DisableDockerPull {
		t.Fatal("TERALAB_DISABLE_DOCKER_PULL was not applied")
	}
	if cfg.QEMUSandbox {
		t.Fatal("TERALAB_QEMU_SANDBOX was not applied")
	}
}
