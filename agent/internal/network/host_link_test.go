// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package network

import (
	"os"
	"path/filepath"
	"testing"
)

// TestDeleteLinkRefusesHostLinks is the SEC-10 regression: the agent only
// removes links TeraLab created.
func TestDeleteLinkRefusesHostLinks(t *testing.T) {
	manager, calls := testManager(t)
	for _, name := range []string{"eth0", "docker0", "br0", "lo"} {
		if err := manager.DeleteLink(name); err == nil {
			t.Fatalf("DeleteLink(%q) must be refused", name)
		}
	}
	for _, call := range *calls {
		if call.name == "ip" && len(call.args) >= 3 && call.args[0] == "link" && call.args[1] == "delete" {
			t.Fatalf("refused link still reached ip: %+v", call)
		}
	}

	tap := TapName("lab-1", "node-1", 0)
	if err := manager.DeleteLink(tap); err != nil {
		t.Fatalf("TeraLab link must be deletable: %v", err)
	}
	if !hasCall(*calls, "ip", "link", "delete", tap) {
		t.Fatalf("TeraLab link was not deleted: %+v", *calls)
	}
}

func TestCreateTapRefusesHostBridge(t *testing.T) {
	manager, calls := testManager(t)
	tap := TapName("lab-1", "node-1", 0)
	if err := manager.CreateTap(tap, "eth0"); err == nil {
		t.Fatal("CreateTap must refuse a host bridge")
	}
	for _, call := range *calls {
		if call.name == "ip" && len(call.args) >= 2 && call.args[0] == "tuntap" {
			t.Fatalf("tap was created for a refused bridge: %+v", call)
		}
	}
	if err := os.MkdirAll(filepath.Join(manager.SysClassNet, "eth0"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := manager.AttachInterface(tap, "eth0"); err == nil {
		t.Fatal("AttachInterface must refuse a host bridge")
	}
	if hasCall(*calls, "ip", "link", "set", "dev", tap, "master", "eth0") {
		t.Fatalf("host bridge attach reached ip: %+v", *calls)
	}
}
