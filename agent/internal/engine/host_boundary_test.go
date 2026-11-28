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

// TestReconcileIgnoresLegacyRunDirState is the SEC-02 regression: node state in
// the VM-writable run directory is not trusted, so a forged record cannot make
// reconcile kill an unrelated process or remove its links.
func TestReconcileIgnoresLegacyRunDirState(t *testing.T) {
	cfg := config.Config{
		RunDir:         t.TempDir(),
		ImagesDir:      t.TempDir(),
		CgroupRoot:     t.TempDir(),
		DisableCgroups: true,
		VMUser:         "teralab-vm",
	}
	manager := NewManager(cfg, slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelError})))
	t.Cleanup(manager.Shutdown)
	sys := t.TempDir()
	manager.net.SysClassNet = sys
	deleted := []string{}
	manager.net.Run = func(name string, args ...string) ([]byte, error) {
		if name == "ip" && len(args) >= 3 && args[0] == "link" && args[1] == "delete" {
			deleted = append(deleted, args[2])
		}

		return nil, nil
	}

	sleepPath, err := exec.LookPath("sleep")
	if err != nil {
		t.Skip("sleep binary is required for this test")
	}
	victim := exec.Command(sleepPath, "30")
	if err := victim.Start(); err != nil {
		t.Fatalf("start victim: %v", err)
	}
	t.Cleanup(func() {
		_ = victim.Process.Kill()
		_ = victim.Wait()
	})

	nodeDir := filepath.Join(cfg.RunDir, "lab1", "node1")
	if err := os.MkdirAll(nodeDir, 0o750); err != nil {
		t.Fatal(err)
	}
	// The forged record points at a real process and a host link.
	tap := network.TapName("lab1", "node1", 0)
	record := map[string]any{
		"id":     "node1",
		"lab_id": "lab1",
		"engine": "qemu",
		"exe":    sleepPath,
		"pid":    victim.Process.Pid,
		"taps":   []string{"eth0"},
	}
	data, err := json.Marshal(record)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(nodeDir, "node.json"), data, 0o640); err != nil {
		t.Fatal(err)
	}
	_ = os.MkdirAll(filepath.Join(sys, "eth0"), 0o755)

	result, err := manager.Reconcile(nil)
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if err := syscall.Kill(victim.Process.Pid, 0); err != nil {
		t.Fatalf("forged run-dir state killed an unrelated process: %v", err)
	}
	if contains(deleted, "eth0") {
		t.Fatalf("forged run-dir state deleted a host link: %v", deleted)
	}
	if len(result.KilledProcesses) != 0 {
		t.Fatalf("forged run-dir state was trusted: %+v", result)
	}
	_ = tap
}

// TestSpecRejectsHostBridges pins the SEC-10 bridge boundary: only lab-owned
// tl* bridges may be placed in a node spec.
func TestSpecRejectsHostBridges(t *testing.T) {
	for _, bridge := range []string{"docker0", "br0", "eth0", "tlfffffffffffff0"} {
		t.Run(bridge, func(t *testing.T) {
			spec := Spec{
				ID:       "node-1",
				LabID:    "lab-1",
				Name:     "R1",
				Engine:   "vpcs",
				Ethernet: 1,
				Networks: []string{bridge},
			}
			spec.Normalize()
			if err := spec.Validate(nil); err == nil {
				t.Fatalf("bridge %q must be rejected", bridge)
			}
		})
	}
	spec := Spec{
		ID:       "node-1",
		LabID:    "lab-1",
		Name:     "R1",
		Engine:   "vpcs",
		Ethernet: 1,
		Networks: []string{network.BridgeName("lab-1", "0")},
	}
	spec.Normalize()
	if err := spec.Validate(nil); err != nil {
		t.Fatalf("a lab bridge must validate: %v", err)
	}
}

// TestDynamipsConsoleUsesLoopback pins SEC-20: -T carries the console port and
// the console binds loopback, never 0.0.0.0.
func TestDynamipsConsoleUsesLoopback(t *testing.T) {
	spec := Spec{
		ID:       "r1",
		LabID:    "lab1",
		Name:     "R1",
		Engine:   "dynamips",
		Platform: "c7200",
		Ethernet: 1,
		Slots:    []string{"0:0:tap"},
	}
	args, err := BuildDynamipsArgs(spec, []string{"tl1"}, 20000)
	if err != nil {
		t.Fatalf("build args: %v", err)
	}
	joined := strings.Join(args, " ")
	if !strings.Contains(joined, "-T 20000") {
		t.Fatalf("dynamips console must use -T: %s", joined)
	}
	if strings.Contains(joined, "-p 20000") {
		t.Fatalf("dynamips must not pass the console port through -p: %s", joined)
	}
	if !strings.Contains(joined, "--console-binding-addr 127.0.0.1") &&
		!strings.Contains(joined, "--console-binding-addr=127.0.0.1") {
		t.Fatalf("dynamips console must bind loopback: %s", joined)
	}
}

func TestQEMUImgRunsAsVMUser(t *testing.T) {
	cfg := config.Config{
		RunDir:         t.TempDir(),
		StateDir:       t.TempDir(),
		ImagesDir:      t.TempDir(),
		CgroupRoot:     t.TempDir(),
		DisableCgroups: true,
		VMUID:          997,
		VMGID:          989,
		VMGroups:       []uint32{993},
		AllowRootVM:    true,
		QEMUImg:        "/usr/bin/qemu-img",
	}
	manager := NewManager(cfg, slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelError})))
	original := qemuImgRunner
	t.Cleanup(func() { qemuImgRunner = original })
	var captured *exec.Cmd
	qemuImgRunner = func(command *exec.Cmd) ([]byte, error) {
		captured = command

		return nil, nil
	}
	t.Setenv("TERALAB_SENTINEL_SECRET", "must-not-be-inherited")

	overlay := filepath.Join(t.TempDir(), "overlay.qcow2")
	if err := manager.createOverlay(manager.legacyIdentity(), filepath.Join(t.TempDir(), "base.qcow2"), overlay); err != nil {
		t.Fatalf("create overlay: %v", err)
	}
	if captured == nil {
		t.Fatal("qemu-img was not invoked")
	}
	credential := captured.SysProcAttr.Credential
	if credential == nil || credential.Uid != 997 || credential.Gid != 989 || len(credential.Groups) != 1 || credential.Groups[0] != 993 {
		t.Fatalf("qemu-img must run as the VM user, got %+v", credential)
	}
	if !captured.SysProcAttr.Setpgid {
		t.Fatal("qemu-img must run in its own process group")
	}
	environment := strings.Join(captured.Env, "\n")
	for _, wanted := range []string{"PATH=/usr/local/bin:/usr/bin:/bin", "HOME=" + filepath.Dir(overlay), "LC_ALL=C"} {
		if !strings.Contains(environment, wanted) {
			t.Fatalf("qemu-img environment missing %q: %v", wanted, captured.Env)
		}
	}
	if strings.Contains(environment, "TERALAB_SENTINEL_SECRET") {
		t.Fatalf("qemu-img inherited the agent environment: %v", captured.Env)
	}
	if len(captured.Args) < 3 || captured.Args[1] != "create" {
		t.Fatalf("unexpected qemu-img argv: %v", captured.Args)
	}
}

func TestQEMUSandboxAndMinimalEnvironment(t *testing.T) {
	dir := t.TempDir()
	envLog := filepath.Join(dir, "env.log")
	argvLog := filepath.Join(dir, "argv.log")
	script := "#!/bin/sh\nprintf '%s\\n' \"$*\" > '" + argvLog + "'\nenv > '" + envLog + "'\nexec sleep 30\n"
	qemu := filepath.Join(dir, "qemu-system-x86_64")
	if err := os.WriteFile(qemu, []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}
	manager := newDiskTestManager(t, filepath.Join(dir, "qemu-img"))
	manager.cfg.QEMUBinary = qemu
	manager.cfg.StateDir = t.TempDir()
	manager.cfg.QEMUSandbox = true
	t.Setenv("TERALAB_SENTINEL_SECRET", "must-not-be-inherited")

	spec := Spec{
		ID:       "node-1",
		LabID:    "lab-1",
		Name:     "R1",
		Ethernet: 1,
		Networks: []string{network.BridgeName("lab-1", "0")},
	}
	spec.Normalize()
	if _, err := manager.Start(spec); err != nil {
		t.Fatalf("start qemu: %v", err)
	}
	t.Cleanup(func() { _ = manager.Stop(spec.ID) })

	line := waitForLogLine(t, argvLog, "-sandbox")
	if !strings.Contains(line, "-sandbox "+qemuSandboxProfile) {
		t.Fatalf("sandbox profile missing from qemu argv: %s", line)
	}
	environment := waitForLogText(t, envLog)
	for _, wanted := range []string{"PATH=/usr/local/bin:/usr/bin:/bin", "TERM=vt100", "LC_ALL=C"} {
		if !strings.Contains(environment, wanted) {
			t.Fatalf("qemu environment missing %q:\n%s", wanted, environment)
		}
	}
	if strings.Contains(environment, "TERALAB_SENTINEL_SECRET") {
		t.Fatalf("qemu inherited the agent environment:\n%s", environment)
	}
}

func waitForLogText(t *testing.T, path string) string {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for {
		data, err := os.ReadFile(path)
		if err == nil && len(data) > 0 {
			return string(data)
		}
		if time.Now().After(deadline) {
			t.Fatalf("timed out waiting for %s: %v", path, err)
		}
		time.Sleep(10 * time.Millisecond)
	}
}

func TestStateFileIsPrivateAndDerivedFromTheRecord(t *testing.T) {
	cfg := config.Config{
		RunDir:         t.TempDir(),
		StateDir:       t.TempDir(),
		ImagesDir:      t.TempDir(),
		CgroupRoot:     t.TempDir(),
		DisableCgroups: true,
	}
	manager := NewManager(cfg, slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelError})))
	path := manager.statePathFor("node-1", "lab-1")
	want := filepath.Join(cfg.StateDir, shortPathComponent("lab-1"), shortPathComponent("node-1")+".json")
	if path != want {
		t.Fatalf("state path = %s, want %s", path, want)
	}
	if err := manager.writeStateFile(path, nodeState{ID: "node-1", LabID: "lab-1", Engine: "qemu", RunDir: manager.runDirFor("node-1", "lab-1")}); err != nil {
		t.Fatalf("write state: %v", err)
	}
	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Fatalf("state file mode = %04o, want 0600", info.Mode().Perm())
	}
	dirInfo, err := os.Stat(filepath.Dir(path))
	if err != nil {
		t.Fatal(err)
	}
	if dirInfo.Mode().Perm() != 0o700 {
		t.Fatalf("state directory mode = %04o, want 0700", dirInfo.Mode().Perm())
	}
	if !strings.Contains(mustReadFile(t, path), `"run_dir"`) {
		t.Fatal("state record must carry its run directory")
	}
	if err := stateFileTrusted(path); err != nil {
		t.Fatalf("a freshly written record must be trusted: %v", err)
	}
	// A symlink is never a state file.
	link := filepath.Join(filepath.Dir(path), "link.json")
	if err := os.Symlink(path, link); err != nil {
		t.Fatal(err)
	}
	if err := stateFileTrusted(link); err == nil {
		t.Fatal("a symlinked state file must be rejected")
	}
}

func mustReadFile(t *testing.T, path string) string {
	t.Helper()
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	return string(data)
}

func TestDockerConfigPathRejectsDangerousContainerPaths(t *testing.T) {
	for _, path := range []string{
		"/proc/self/environ",
		"/sys/kernel/notes",
		"/dev/mem",
		"/run/docker.sock",
		"/etc/passwd",
		"/usr/local/bin/evil",
		"/etc/frr/../etc/passwd",
	} {
		t.Run(path, func(t *testing.T) {
			spec := dockerValidationSpec("alpine:3.20")
			spec.Docker = &DockerOptions{ConfigPath: path}
			if err := spec.Validate(nil); err == nil {
				t.Fatalf("config_path %q must be rejected", path)
			}
		})
	}
}

func TestDockerPullDisabledByConfig(t *testing.T) {
	binary, logPath := fakeDocker(t, `if [ "$1" = image ]; then exit 1; fi
if [ "$1" = pull ]; then exit 0; fi
exit 0`)
	manager := newDockerTestManager(t, binary)
	manager.cfg.DisableDockerPull = true
	spec := Spec{ID: "node-pull", Name: "Pull", Engine: "docker", Image: "alpine:3.20"}
	spec.Normalize()
	spec.Docker = &DockerOptions{Pull: boolPointer(true)}

	err := manager.ensureDockerImage(spec)
	if err == nil || !strings.Contains(err.Error(), "not available locally") {
		t.Fatalf("expected a local-image error, got %v", err)
	}
	for _, call := range dockerCalls(t, logPath) {
		if strings.HasPrefix(call, "pull ") {
			t.Fatalf("pull must be disabled by the host profile: %v", call)
		}
	}
}

// TestReconcileDoesNotKillWhenCgroupDoesNotMatch pins the cgroup part of the
// kill gate: a matching executable and PID are not enough when the process is
// not in the recorded TeraLab cgroup.
func TestReconcileDoesNotKillWhenCgroupDoesNotMatch(t *testing.T) {
	manager, _ := newCleanupTestManager(t)
	manager.cfg.StateDir = t.TempDir()
	manager.cfg.DisableCgroups = false
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

	statePath, err := writeTrustedState(t, manager, nodeState{
		ID:         "node1",
		LabID:      "lab1",
		Engine:     "qemu",
		Exe:        sleepPath,
		PID:        victim.Process.Pid,
		StartTicks: processStartTicks(victim.Process.Pid),
		Cgroup:     filepath.Join(manager.cfg.CgroupRoot, "lab-"+shortPathComponent("lab1"), "node-node1"),
		Taps:       []string{Spec{LabID: "lab1", ID: "node1"}.TapName(0)},
	})
	if err != nil {
		t.Fatalf("write state: %v", err)
	}
	result, err := manager.Reconcile(nil)
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if err := syscall.Kill(victim.Process.Pid, 0); err != nil {
		t.Fatalf("a process outside the recorded cgroup was killed: %v", err)
	}
	if len(result.KilledProcesses) != 0 {
		t.Fatalf("unexpected kill: %+v", result)
	}
	_ = statePath
}
