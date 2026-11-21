// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"encoding/json"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
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

	result, err := manager.Reconcile(nil)
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
	result, err := manager.Reconcile(nil)
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

// mkdirLab creates one lab run directory the way runDir() names it.
func mkdirLab(t *testing.T, runRoot, labID string) string {
	t.Helper()
	dir := filepath.Join(runRoot, shortPathComponent(labID))
	if err := os.MkdirAll(dir, 0o750); err != nil {
		t.Fatalf("create lab directory: %v", err)
	}

	return dir
}

// writeNodeStateFile writes one node.json under a lab directory.
func writeNodeStateFile(t *testing.T, labDir, nodeID, labID string) string {
	t.Helper()
	nodeDir := filepath.Join(labDir, shortPathComponent(nodeID))
	if err := os.MkdirAll(nodeDir, 0o750); err != nil {
		t.Fatalf("create node directory: %v", err)
	}
	data, err := json.Marshal(nodeState{ID: nodeID, LabID: labID, Engine: "qemu"})
	if err != nil {
		t.Fatalf("marshal node state: %v", err)
	}
	if err := os.WriteFile(filepath.Join(nodeDir, stateFileName), data, 0o640); err != nil {
		t.Fatalf("write node state: %v", err)
	}

	return nodeDir
}

func TestReconcilePrunesStaleLabDirectories(t *testing.T) {
	manager, deleted := newCleanupTestManager(t)
	runRoot := manager.cfg.RunDir

	// A lab id longer than 32 bytes exercises the hashed run-directory
	// component, so the keep-set must use the same derivation as runDir().
	keepLab := strings.Repeat("a", 40)
	keepDir := mkdirLab(t, runRoot, keepLab)

	// This lab is not in the keep-set but still holds a live node marker.
	liveLab := "lab-live"
	liveDir := mkdirLab(t, runRoot, liveLab)
	liveNodeDir := writeNodeStateFile(t, liveDir, "node-live", liveLab)
	manager.nodes["node-live"] = &Node{
		Spec:  Spec{ID: "node-live", LabID: liveLab},
		State: "running",
	}

	staleDir := mkdirLab(t, runRoot, "lab-stale")

	result, err := manager.Reconcile([]string{keepLab})
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if _, err := os.Stat(staleDir); !os.IsNotExist(err) {
		t.Fatalf("stale lab directory not removed: %v", err)
	}
	kept := map[string]string{
		"keep lab":  keepDir,
		"live lab":  liveDir,
		"live node": liveNodeDir,
	}
	for name, dir := range kept {
		if _, err := os.Stat(dir); err != nil {
			t.Fatalf("%s directory was removed: %v", name, err)
		}
	}
	if len(result.RemovedDirs) != 1 || result.RemovedDirs[0] != staleDir {
		t.Fatalf("removed dirs = %v, want [%s]", result.RemovedDirs, staleDir)
	}
	if len(*deleted) != 0 {
		t.Fatalf("unexpected link deletions: %v", *deleted)
	}
}

// A tracked node whose state file still names an older lab must keep the lab
// directory it lives in, even when that directory is not in the keep-set.
func TestReconcileKeepsLabDirectoryWithLiveNodeState(t *testing.T) {
	manager, _ := newCleanupTestManager(t)
	runRoot := manager.cfg.RunDir

	dir := mkdirLab(t, runRoot, "lab-renamed")
	writeNodeStateFile(t, dir, "node-live", "lab-old")
	manager.nodes["node-live"] = &Node{
		Spec:  Spec{ID: "node-live"},
		State: "running",
	}

	result, err := manager.Reconcile([]string{})
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if _, err := os.Stat(dir); err != nil {
		t.Fatalf("lab directory holding live node state was removed: %v", err)
	}
	if len(result.RemovedDirs) != 0 {
		t.Fatalf("removed dirs = %v, want none", result.RemovedDirs)
	}
}

// A stopped node has no process, taps, cgroup or state file worth protecting:
// keeping it in the manager must not pin its lab directory outside the keep-set.
func TestReconcileRemovesLabDirectoryOfStoppedNode(t *testing.T) {
	manager, _ := newCleanupTestManager(t)
	manager.cfg.DisableCgroups = false
	runRoot := manager.cfg.RunDir

	labDir := mkdirLab(t, runRoot, "lab-deleted")
	nodeDir := writeNodeStateFile(t, labDir, "node-stopped", "lab-deleted")
	cgroupDir := filepath.Join(manager.cfg.CgroupRoot, "node-node-stopped")
	if err := os.MkdirAll(cgroupDir, 0o755); err != nil {
		t.Fatal(err)
	}

	spec := Spec{ID: "node-stopped", LabID: "lab-deleted", Name: "R1", Engine: "qemu"}
	spec.Normalize()
	manager.nodes[spec.ID] = &Node{Spec: spec, State: "stopped"}

	result, err := manager.Reconcile([]string{})
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if _, err := os.Stat(labDir); !os.IsNotExist(err) {
		t.Fatalf("lab directory of a stopped node survived: %v", err)
	}
	if _, err := os.Stat(nodeDir); !os.IsNotExist(err) {
		t.Fatalf("node directory of a stopped node survived: %v", err)
	}
	if _, err := os.Stat(cgroupDir); !os.IsNotExist(err) {
		t.Fatalf("cgroup of a stopped node survived: %v", err)
	}
	if !contains(result.RemovedCgroups, cgroupDir) {
		t.Fatalf("cgroup sweep skipped a stopped node: %+v", result.RemovedCgroups)
	}
}

// A running node keeps its lab directory even when the lab is not in the
// keep-set and the node has no state file on disk.
func TestReconcileKeepsRunningNodeLabDirectory(t *testing.T) {
	manager, _ := newCleanupTestManager(t)
	runRoot := manager.cfg.RunDir

	labDir := mkdirLab(t, runRoot, "lab-running")
	spec := Spec{ID: "node-running", LabID: "lab-running", Name: "R1", Engine: "qemu"}
	spec.Normalize()
	manager.nodes[spec.ID] = &Node{Spec: spec, State: "running"}

	result, err := manager.Reconcile([]string{})
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if _, err := os.Stat(labDir); err != nil {
		t.Fatalf("running node's lab directory was removed: %v", err)
	}
	if len(result.RemovedDirs) != 0 {
		t.Fatalf("removed dirs = %v, want none", result.RemovedDirs)
	}
}

func TestReconcileLeavesLabDirectoriesWithoutKeepSet(t *testing.T) {
	manager, _ := newCleanupTestManager(t)
	runRoot := manager.cfg.RunDir

	keepDir := mkdirLab(t, runRoot, "lab-keep")
	unknownDir := mkdirLab(t, runRoot, "lab-unknown")
	liveDir := mkdirLab(t, runRoot, "lab-live")
	writeNodeStateFile(t, liveDir, "node-live", "lab-live")
	manager.nodes["node-live"] = &Node{
		Spec:  Spec{ID: "node-live", LabID: "lab-live"},
		State: "running",
	}

	result, err := manager.Reconcile(nil)
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	for _, dir := range []string{keepDir, unknownDir, liveDir} {
		if _, err := os.Stat(dir); err != nil {
			t.Fatalf("lab directory %s must survive a nil keep-set: %v", dir, err)
		}
	}
	if len(result.RemovedDirs) != 0 {
		t.Fatalf("nil keep-set removed directories: %v", result.RemovedDirs)
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
