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
