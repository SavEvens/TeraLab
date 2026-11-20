// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"testing"

	"github.com/SavEvens/TeraLab/agent/internal/config"
	"github.com/SavEvens/TeraLab/agent/internal/network"
)

// newCleanupTestManager builds a manager whose network layer runs against a
// temporary sysfs tree and records every link deletion.
func newCleanupTestManager(t *testing.T) (*Manager, *[]string) {
	t.Helper()
	cfg := config.Config{
		RunDir:         t.TempDir(),
		ImagesDir:      t.TempDir(),
		CgroupRoot:     t.TempDir(),
		DisableCgroups: true,
		NetworkBackend: network.BackendLinux,
		VMUser:         "teralab-vm",
	}
	manager := NewManager(cfg, slog.New(slog.NewTextHandler(io.Discard, nil)))
	deleted := []string{}
	manager.net = &network.Manager{
		VMUser:      cfg.VMUser,
		SysClassNet: t.TempDir(),
		Backend:     network.BackendLinux,
		Run: func(name string, args ...string) ([]byte, error) {
			if name == "ip" && len(args) >= 3 && args[0] == "link" && args[1] == "delete" {
				deleted = append(deleted, args[2])
			}

			return nil, nil
		},
	}

	return manager, &deleted
}

func createTestLinks(t *testing.T, sys string, names ...string) {
	t.Helper()
	for _, name := range names {
		if err := os.MkdirAll(filepath.Join(sys, name), 0o755); err != nil {
			t.Fatal(err)
		}
	}
}

func TestCleanupLabSparesRunningNodeLinks(t *testing.T) {
	manager, deleted := newCleanupTestManager(t)
	bridge := network.BridgeName("lab-1", "0")
	tap := network.TapName("lab-1", "node-1", 0)
	stale := network.BridgeName("lab-1", "1")
	other := network.BridgeName("lab-2", "0")
	createTestLinks(t, manager.net.SysClassNet, bridge, tap, stale, other)

	manager.nodes["node-1"] = &Node{
		Spec:  Spec{ID: "node-1", LabID: "lab-1", Networks: []string{bridge, ""}},
		Taps:  []string{tap},
		State: "running",
	}

	removed, err := manager.CleanupLab("lab-1")
	if err != nil {
		t.Fatalf("cleanup lab: %v", err)
	}
	if len(removed) != 1 || removed[0] != stale {
		t.Fatalf("unexpected removed links: %v", removed)
	}
	for _, name := range []string{bridge, tap, other} {
		if contains(*deleted, name) {
			t.Fatalf("link %s must not be removed: %v", name, *deleted)
		}
	}
	if !contains(*deleted, stale) {
		t.Fatalf("stale lab link was not removed: %v", *deleted)
	}
}

func TestReconcileSparesRunningNodeLinks(t *testing.T) {
	manager, deleted := newCleanupTestManager(t)
	bridge := network.BridgeName("lab-1", "0")
	tap := network.TapName("lab-1", "node-1", 0)
	stale := network.BridgeName("lab-1", "1")
	createTestLinks(t, manager.net.SysClassNet, bridge, tap, stale)

	manager.nodes["node-1"] = &Node{
		Spec:  Spec{ID: "node-1", LabID: "lab-1", Networks: []string{bridge}},
		Taps:  []string{tap},
		State: "running",
	}

	result, err := manager.Reconcile()
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	for _, name := range []string{bridge, tap} {
		if contains(*deleted, name) {
			t.Fatalf("running node link %s must not be removed: %v", name, *deleted)
		}
	}
	if !contains(*deleted, stale) {
		t.Fatalf("stale link was not removed: %v", *deleted)
	}
	if !contains(result.RemovedLinks, stale) {
		t.Fatalf("stale link missing from reconcile result: %+v", result)
	}
}
