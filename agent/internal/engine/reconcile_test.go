// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"encoding/json"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"testing"

	"github.com/SavEvens/TeraLab/agent/internal/config"
)

func TestReconcileKillsOrphansAndRemovesResources(t *testing.T) {
	cfg := config.Config{
		RunDir:         t.TempDir(),
		ImagesDir:      t.TempDir(),
		CgroupRoot:     t.TempDir(),
		DisableCgroups: true,
		VMUser:         "teralab-vm",
	}
	manager := NewManager(cfg, slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelError})))
	defer manager.Shutdown()

	sys := t.TempDir()
	manager.net.SysClassNet = sys
	deleted := []string{}
	manager.net.Run = func(name string, args ...string) ([]byte, error) {
		if len(args) >= 3 && args[0] == "link" && args[1] == "delete" {
			deleted = append(deleted, args[2])
		}
		return nil, nil
	}

	sleepPath, err := exec.LookPath("sleep")
	if err != nil {
		t.Skip("sleep binary is required for this test")
	}
	orphan := exec.Command(sleepPath, "30")
	orphan.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	if err := orphan.Start(); err != nil {
		t.Fatalf("start orphan: %v", err)
	}
	t.Cleanup(func() {
		_ = orphan.Process.Kill()
		_ = orphan.Wait()
	})

	nodeDir := filepath.Join(cfg.RunDir, "lab1", "node1")
	if err := os.MkdirAll(nodeDir, 0o750); err != nil {
		t.Fatal(err)
	}
	tap := Spec{LabID: "lab1", ID: "node1"}.TapName(0)
	state := nodeState{
		ID:     "node1",
		LabID:  "lab1",
		Engine: "qemu",
		Exe:    sleepPath,
		PID:    orphan.Process.Pid,
		Taps:   []string{tap},
	}
	data, err := json.Marshal(state)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(nodeDir, stateFileName), data, 0o640); err != nil {
		t.Fatal(err)
	}

	stale := "tlstale000000"
	for _, name := range []string{tap, stale} {
		if err := os.MkdirAll(filepath.Join(sys, name), 0o755); err != nil {
			t.Fatal(err)
		}
	}

	result, err := manager.Reconcile()
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}

	if len(result.KilledProcesses) != 1 || result.KilledProcesses[0] != orphan.Process.Pid {
		t.Fatalf("orphan not killed: %+v", result)
	}
	_ = orphan.Wait()
	if err := syscall.Kill(orphan.Process.Pid, 0); err == nil {
		t.Fatal("orphan process still running")
	}
	if _, err := os.Stat(nodeDir); !os.IsNotExist(err) {
		t.Fatalf("node directory not removed: %v", err)
	}
	if _, err := os.Stat(filepath.Join(cfg.RunDir, "lab1")); !os.IsNotExist(err) {
		t.Fatalf("empty lab directory not removed: %v", err)
	}
	if !contains(result.RemovedLinks, tap) || !contains(result.RemovedLinks, stale) {
		t.Fatalf("stale links not removed: %+v", result.RemovedLinks)
	}
	if !contains(deleted, tap) && !contains(deleted, stale) {
		t.Fatalf("ip link delete was not invoked: %v", deleted)
	}
}

func TestReconcileLeavesLiveNodesAlone(t *testing.T) {
	cfg := config.Config{
		RunDir:         t.TempDir(),
		ImagesDir:      t.TempDir(),
		CgroupRoot:     t.TempDir(),
		DisableCgroups: true,
		VMUser:         "teralab-vm",
	}
	manager := NewManager(cfg, slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelError})))
	manager.net.SysClassNet = t.TempDir()
	manager.net.Run = func(name string, args ...string) ([]byte, error) { return nil, nil }

	tap := Spec{LabID: "lab1", ID: "node1"}.TapName(0)
	spec := Spec{ID: "node1", LabID: "lab1", Name: "R1", Engine: "qemu"}
	spec.Normalize()
	manager.nodes[spec.ID] = &Node{
		Spec:  spec,
		Taps:  []string{tap},
		State: "running",
	}

	if err := os.MkdirAll(filepath.Join(cfg.RunDir, "lab1", "node1"), 0o750); err != nil {
		t.Fatal(err)
	}
	result, err := manager.Reconcile()
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if len(result.KilledProcesses) != 0 {
		t.Fatalf("live node was killed: %+v", result)
	}
	if len(result.RemovedDirs) != 0 {
		t.Fatalf("live node directory was removed: %+v", result)
	}
}

func contains(values []string, target string) bool {
	for _, value := range values {
		if value == target {
			return true
		}
	}

	return false
}
