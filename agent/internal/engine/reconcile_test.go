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
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/config"
	"github.com/SavEvens/TeraLab/agent/internal/network"
)

func reconcileTestConfig(t *testing.T) config.Config {
	t.Helper()

	return config.Config{
		RunDir:         t.TempDir(),
		StateDir:       t.TempDir(),
		ImagesDir:      t.TempDir(),
		CgroupRoot:     t.TempDir(),
		DisableCgroups: true,
		VMUser:         "teralab-vm",
	}
}

func TestReconcileKillsOrphansAndRemovesResources(t *testing.T) {
	cfg := reconcileTestConfig(t)
	sleepPath, err := exec.LookPath("sleep")
	if err != nil {
		t.Skip("sleep binary is required for this test")
	}
	cfg.QEMUBinary = sleepPath
	manager := NewManager(cfg, slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelError})))
	defer manager.Shutdown()

	sys := t.TempDir()
	manager.net.SysClassNet = sys
	deleted := []string{}
	manager.net.Run = func(name string, args ...string) ([]byte, error) {
		if name == "ip" && len(args) >= 3 && args[0] == "link" && args[1] == "delete" {
			deleted = append(deleted, args[2])
		}
		return nil, nil
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

	statePath, err := writeTrustedState(t, manager, nodeState{
		ID:         "node1",
		LabID:      "lab1",
		Engine:     "qemu",
		Exe:        sleepPath,
		PID:        orphan.Process.Pid,
		StartTicks: processStartTicks(orphan.Process.Pid),
		Taps:       []string{Spec{LabID: "lab1", ID: "node1"}.TapName(0)},
	})
	if err != nil {
		t.Fatalf("write state: %v", err)
	}
	tap := Spec{LabID: "lab1", ID: "node1"}.TapName(0)
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
	if _, err := os.Stat(statePath); !os.IsNotExist(err) {
		t.Fatalf("state file not removed: %v", err)
	}
	if _, err := os.Stat(statePath); !os.IsNotExist(err) {
		t.Fatalf("state file not removed: %v", err)
	}
	if _, err := os.Stat(filepath.Join(manager.runDirFor("node1", "lab1"))); !os.IsNotExist(err) {
		t.Fatalf("run directory not removed: %v", err)
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
	manager := NewManager(reconcileTestConfig(t),
		slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelError})))
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

	if err := os.MkdirAll(manager.runDirFor(spec.ID, spec.LabID), 0o750); err != nil {
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

// writeTrustedState writes one record through the manager's own writer, so the
// mode and path are exactly what production writes.
func writeTrustedState(t *testing.T, manager *Manager, state nodeState) (string, error) {
	t.Helper()
	state.RunDir = manager.runDirFor(state.ID, state.LabID)
	if err := os.MkdirAll(state.RunDir, 0o750); err != nil {
		t.Fatalf("create run dir: %v", err)
	}
	path := manager.statePathFor(state.ID, state.LabID)
	if err := manager.writeStateFile(path, state); err != nil {
		return path, err
	}

	return path, nil
}

func TestReconcilePrunesStaleLabDirectories(t *testing.T) {
	manager, deleted := newCleanupTestManager(t)
	manager.cfg.StateDir = t.TempDir()
	runRoot := manager.cfg.RunDir

	// A lab id longer than 32 bytes exercises the hashed run-directory
	// component, so the keep-set must use the same derivation as runDir().
	keepLab := strings.Repeat("a", 40)
	keepDir := mkdirLab(t, runRoot, keepLab)

	// This lab is not in the keep-set but still holds a running node.
	liveLab := "lab-live"
	liveDir := mkdirLab(t, runRoot, liveLab)
	liveNodeDir := filepath.Join(liveDir, shortPathComponent("node-live"))
	if err := os.MkdirAll(liveNodeDir, 0o750); err != nil {
		t.Fatal(err)
	}
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

// Legacy run-directory state is untrusted even when it names a live node: the
// lab directory is pruned and the forged file is discarded.
func TestReconcileIgnoresLegacyNodeStateForLabPruning(t *testing.T) {
	manager, _ := newCleanupTestManager(t)
	manager.cfg.StateDir = t.TempDir()
	runRoot := manager.cfg.RunDir

	dir := mkdirLab(t, runRoot, "lab-renamed")
	nodeDir := filepath.Join(dir, shortPathComponent("node-live"))
	if err := os.MkdirAll(nodeDir, 0o750); err != nil {
		t.Fatal(err)
	}
	data, err := json.Marshal(nodeState{ID: "node-live", LabID: "lab-old", Engine: "qemu"})
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(nodeDir, stateFileName), data, 0o640); err != nil {
		t.Fatal(err)
	}

	result, err := manager.Reconcile([]string{})
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if _, err := os.Stat(dir); !os.IsNotExist(err) {
		t.Fatalf("lab directory holding only legacy state survived: %v", err)
	}
	if !contains(result.QuarantinedState, filepath.Join(nodeDir, stateFileName)) {
		t.Fatalf("legacy state file was not reported: %+v", result.QuarantinedState)
	}
}

// A stopped node has no process, taps, cgroup or state file worth protecting:
// keeping it in the manager must not pin its lab directory outside the keep-set.
func TestReconcileRemovesLabDirectoryOfStoppedNode(t *testing.T) {
	manager, _ := newCleanupTestManager(t)
	manager.cfg.StateDir = t.TempDir()
	manager.cfg.DisableCgroups = false
	runRoot := manager.cfg.RunDir

	labDir := mkdirLab(t, runRoot, "lab-deleted")
	nodeDir := filepath.Join(labDir, shortPathComponent("node-stopped"))
	if err := os.MkdirAll(nodeDir, 0o750); err != nil {
		t.Fatal(err)
	}
	cgroupDir := filepath.Join(manager.cfg.CgroupRoot, "lab-"+shortPathComponent("lab-deleted"), "node-node-stopped")
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
	manager.cfg.StateDir = t.TempDir()
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
	manager.cfg.StateDir = t.TempDir()
	runRoot := manager.cfg.RunDir

	keepDir := mkdirLab(t, runRoot, "lab-keep")
	unknownDir := mkdirLab(t, runRoot, "lab-unknown")
	liveDir := mkdirLab(t, runRoot, "lab-live")
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

// TestReconcileRejectsForgedTrustedState is the SEC-02/09 regression for the
// new state directory: even a record the attacker could plant there is
// rejected unless it matches its path, carries a real engine executable, a
// positive PID and a TeraLab-owned tap.
func TestReconcileRejectsForgedTrustedState(t *testing.T) {
	manager, deleted := newCleanupTestManager(t)
	manager.cfg.StateDir = t.TempDir()
	sleepPath, err := exec.LookPath("sleep")
	if err != nil {
		t.Skip("sleep binary is required for this test")
	}
	manager.cfg.QEMUBinary = sleepPath

	victim := exec.Command(sleepPath, "30")
	if err := victim.Start(); err != nil {
		t.Fatalf("start victim: %v", err)
	}
	t.Cleanup(func() {
		_ = victim.Process.Kill()
		_ = victim.Wait()
	})
	_ = os.MkdirAll(filepath.Join(manager.net.SysClassNet, "eth0"), 0o755)

	cases := []struct {
		name       string
		mutate     func(*nodeState)
		quarantine bool
	}{
		{name: "empty exe", mutate: func(state *nodeState) { state.Exe = "" }, quarantine: true},
		{name: "unknown engine", mutate: func(state *nodeState) { state.Engine = "shell" }, quarantine: true},
		{name: "host tap", mutate: func(state *nodeState) { state.Taps = []string{"eth0"} }, quarantine: true},
		{name: "other lab tap", mutate: func(state *nodeState) { state.Taps = []string{network.TapName("other", "node1", 0)} }, quarantine: true},
		{name: "zero pid", mutate: func(state *nodeState) { state.PID = 0 }, quarantine: true},
		{name: "wrong start ticks", mutate: func(state *nodeState) { state.StartTicks++ }},
		{name: "unexpected executable", mutate: func(state *nodeState) { state.Exe = "/bin/sh" }, quarantine: true},
	}
	for index, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			state := nodeState{
				ID:         "node1",
				LabID:      "lab1",
				Engine:     "qemu",
				Exe:        sleepPath,
				PID:        victim.Process.Pid,
				StartTicks: processStartTicks(victim.Process.Pid),
				Taps:       []string{network.TapName("lab1", "node1", 0)},
			}
			testCase.mutate(&state)
			state.RunDir = manager.runDirFor(state.ID, state.LabID)
			path := manager.statePathFor(state.ID, state.LabID)
			if err := manager.writeStateFile(path, state); err != nil {
				t.Fatalf("write forged state: %v", err)
			}
			// Every forged case is written afresh; rejected files are renamed.
			t.Cleanup(func() { _ = os.Remove(path) })

			result, err := manager.Reconcile(nil)
			if err != nil {
				t.Fatalf("reconcile: %v", err)
			}
			if err := syscall.Kill(victim.Process.Pid, 0); err != nil {
				t.Fatalf("case %d: forged state killed an unrelated process: %v", index, err)
			}
			if contains(*deleted, "eth0") {
				t.Fatalf("forged state deleted a host link: %v", *deleted)
			}
			if len(result.KilledProcesses) != 0 {
				t.Fatalf("forged state was trusted: %+v", result)
			}
			if testCase.quarantine && !contains(result.QuarantinedState, path) {
				t.Fatalf("forged state file was not quarantined: %+v", result.QuarantinedState)
			}
		})
	}
}

// A state file that is readable by the engine user is rejected even when the
// record contents look valid.
func TestReconcileRejectsWorldReadableStateFile(t *testing.T) {
	manager, _ := newCleanupTestManager(t)
	manager.cfg.StateDir = t.TempDir()
	path := manager.statePathFor("node1", "lab1")
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	record := nodeState{
		ID:      "node1",
		LabID:   "lab1",
		Engine:  "qemu",
		RunDir:  manager.runDirFor("node1", "lab1"),
		Exe:     "/usr/bin/qemu-system-x86_64",
		PID:     os.Getpid(),
		Started: time.Now().UTC().Format(time.RFC3339),
	}
	data, err := json.Marshal(record)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, data, 0o644); err != nil {
		t.Fatal(err)
	}

	result, err := manager.Reconcile(nil)
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if !contains(result.QuarantinedState, path) {
		t.Fatalf("world-readable state file was accepted: %+v", result)
	}
	if len(result.KilledProcesses) != 0 {
		t.Fatalf("world-readable state killed a process: %+v", result)
	}
}

func TestProcessMatchesRejectsEmptyExeAndReusedPid(t *testing.T) {
	sleepPath, err := exec.LookPath("sleep")
	if err != nil {
		t.Skip("sleep binary is required for this test")
	}
	command := exec.Command(sleepPath, "30")
	if err := command.Start(); err != nil {
		t.Fatalf("start process: %v", err)
	}
	t.Cleanup(func() {
		_ = command.Process.Kill()
		_ = command.Wait()
	})
	ticks := processStartTicks(command.Process.Pid)
	if ticks == 0 {
		t.Fatal("cannot read the process start time")
	}

	cases := []struct {
		name  string
		pid   int
		exe   string
		ticks uint64
		want  bool
	}{
		{name: "matches", pid: command.Process.Pid, exe: sleepPath, ticks: ticks, want: true},
		{name: "empty exe", pid: command.Process.Pid, exe: "", ticks: ticks},
		{name: "whitespace exe", pid: command.Process.Pid, exe: "   ", ticks: ticks},
		{name: "zero pid", pid: 0, exe: sleepPath, ticks: ticks},
		{name: "negative pid", pid: -1, exe: sleepPath, ticks: ticks},
		{name: "reused pid", pid: command.Process.Pid, exe: sleepPath, ticks: ticks + 1},
		{name: "mismatched exe", pid: command.Process.Pid, exe: "/bin/sh", ticks: ticks},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			if got := processMatches(testCase.pid, testCase.exe, testCase.ticks); got != testCase.want {
				t.Fatalf("processMatches(%d, %q, %d) = %t, want %t",
					testCase.pid, testCase.exe, testCase.ticks, got, testCase.want)
			}
		})
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
