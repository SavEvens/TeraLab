// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/config"
	"github.com/SavEvens/TeraLab/agent/internal/network"
)

func perLabTestConfig(t *testing.T, slots int) config.Config {
	t.Helper()

	return config.Config{
		RunDir:         t.TempDir(),
		StateDir:       t.TempDir(),
		ImagesDir:      t.TempDir(),
		CgroupRoot:     t.TempDir(),
		DisableCgroups: true,
		DisableKVM:     true,
		NetworkBackend: network.BackendLinux,
		PerLabUID:      true,
		LabUIDFirst:    200000,
		LabUIDSlots:    slots,
		ImageGID:       4242,
		AllowRootVM:    true,
		VMUser:         "teralab-vm",
	}
}

func newPerLabTestManager(t *testing.T, slots int) *Manager {
	t.Helper()
	manager := NewManager(perLabTestConfig(t, slots), slog.New(slog.NewTextHandler(io.Discard, nil)))
	manager.net.SysClassNet = t.TempDir()
	manager.net.Run = func(name string, args ...string) ([]byte, error) { return nil, nil }
	t.Cleanup(manager.Shutdown)

	return manager
}

func allocateForTest(t *testing.T, manager *Manager, labID, nodeID string) labIdentity {
	t.Helper()
	identity, err := manager.labIdentityFor(Spec{ID: nodeID, LabID: labID})
	if err != nil {
		t.Fatalf("allocate %s/%s: %v", labID, nodeID, err)
	}

	return identity
}

func TestUIDRegistryAllocatesStableDistinctSlots(t *testing.T) {
	directory := t.TempDir()
	allocator := newUIDAllocator(directory, 200000, 4)
	now := time.Now()

	first, err := allocator.allocate("lab-a", "lab-a", now)
	if err != nil {
		t.Fatalf("allocate lab-a: %v", err)
	}
	if first.Slot != 0 || first.UID != 200000 || first.GID != 200000 {
		t.Fatalf("unexpected first allocation: %+v", first)
	}
	again, err := allocator.allocate("lab-a", "lab-a", now.Add(time.Hour))
	if err != nil {
		t.Fatalf("reallocate lab-a: %v", err)
	}
	if again.Slot != first.Slot || again.UID != first.UID || again.Created != first.Created {
		t.Fatalf("allocation is not stable: %+v vs %+v", first, again)
	}
	second, err := allocator.allocate("lab-b", "lab-b", now)
	if err != nil {
		t.Fatalf("allocate lab-b: %v", err)
	}
	if second.UID == first.UID {
		t.Fatalf("two labs share uid %d", first.UID)
	}
	if second.Slot != 1 || second.UID != 200001 {
		t.Fatalf("unexpected second allocation: %+v", second)
	}

	info, err := os.Stat(filepath.Join(directory, uidMapFileName))
	if err != nil {
		t.Fatalf("stat registry: %v", err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Fatalf("registry mode = %04o, want 0600", info.Mode().Perm())
	}
	var raw map[string]any
	data, err := os.ReadFile(filepath.Join(directory, uidMapFileName))
	if err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(data, &raw); err != nil {
		t.Fatalf("registry is not valid json: %v", err)
	}
	if raw["version"] != float64(uidMapVersion) {
		t.Fatalf("registry version = %v, want %d", raw["version"], uidMapVersion)
	}

	// A new allocator (agent restart) reuses the persisted slot.
	restarted := newUIDAllocator(directory, 200000, 4)
	reused, err := restarted.allocate("lab-a", "lab-a", now)
	if err != nil {
		t.Fatalf("allocate after restart: %v", err)
	}
	if reused.UID != first.UID || reused.Slot != first.Slot {
		t.Fatalf("allocation did not survive a restart: %+v", reused)
	}
}

func TestUIDRegistryReusesReleasedSlot(t *testing.T) {
	directory := t.TempDir()
	allocator := newUIDAllocator(directory, 200000, 2)
	now := time.Now()
	if _, err := allocator.allocate("lab-a", "lab-a", now); err != nil {
		t.Fatal(err)
	}
	if _, err := allocator.allocate("lab-b", "lab-b", now); err != nil {
		t.Fatal(err)
	}
	if err := allocator.release("lab-a"); err != nil {
		t.Fatalf("release: %v", err)
	}
	replacement, err := allocator.allocate("lab-c", "lab-c", now)
	if err != nil {
		t.Fatalf("allocate after release: %v", err)
	}
	if replacement.Slot != 0 || replacement.UID != 200000 {
		t.Fatalf("released slot was not reused: %+v", replacement)
	}
}

func TestUIDRegistryExhaustionFailsClosed(t *testing.T) {
	directory := t.TempDir()
	allocator := newUIDAllocator(directory, 200000, 2)
	now := time.Now()
	for _, key := range []string{"lab-a", "lab-b"} {
		if _, err := allocator.allocate(key, key, now); err != nil {
			t.Fatalf("allocate %s: %v", key, err)
		}
	}
	entry, err := allocator.allocate("lab-c", "lab-c", now)
	if err == nil {
		t.Fatalf("expected exhaustion, got %+v", entry)
	}
	if !errors.Is(err, ErrEngineUnavailable) {
		t.Fatalf("exhaustion must be engine_unavailable, got %v", err)
	}
	if !strings.Contains(err.Error(), "exhausted") {
		t.Fatalf("unexpected exhaustion error: %v", err)
	}

	// Manager level: the second lab fails and never falls back to VMUID.
	manager := newPerLabTestManager(t, 1)
	manager.cfg.VMUID = 997
	allocateForTest(t, manager, "lab-a", "node-a")
	identity, err := manager.labIdentityFor(Spec{ID: "node-b", LabID: "lab-b"})
	if err == nil || !errors.Is(err, ErrEngineUnavailable) {
		t.Fatalf("expected engine_unavailable when the pool is exhausted, got %+v, %v", identity, err)
	}
	if identity.UID != 0 {
		t.Fatalf("exhaustion fell back to uid %d", identity.UID)
	}
}

func TestUIDRegistryCorruptionFailsClosed(t *testing.T) {
	cases := []struct {
		name    string
		content string
		mode    os.FileMode
		want    string
	}{
		{name: "invalid json", content: "{not json", mode: 0o600, want: "decode"},
		{name: "wrong version", content: `{"version":2,"labs":{}}`, mode: 0o600, want: "version"},
		{
			name:    "foreign uid",
			content: `{"version":1,"labs":{"lab-a":{"lab_id":"lab-a","slot":0,"uid":4242,"gid":4242}}}`,
			mode:    0o600,
			want:    "want 200000",
		},
		{
			name: "duplicate slot",
			content: `{"version":1,"labs":{` +
				`"lab-a":{"lab_id":"lab-a","slot":1,"uid":200001,"gid":200001},` +
				`"lab-b":{"lab_id":"lab-b","slot":1,"uid":200001,"gid":200001}}}`,
			mode: 0o600,
			want: "shared",
		},
		{name: "world readable", content: `{"version":1,"labs":{}}`, mode: 0o644, want: "0600"},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			directory := t.TempDir()
			path := filepath.Join(directory, uidMapFileName)
			if err := os.WriteFile(path, []byte(testCase.content), testCase.mode); err != nil {
				t.Fatal(err)
			}
			allocator := newUIDAllocator(directory, 200000, 4)
			_, err := allocator.allocate("lab-new", "lab-new", time.Now())
			if err == nil || !strings.Contains(err.Error(), testCase.want) {
				t.Fatalf("expected %q, got %v", testCase.want, err)
			}
			if !errors.Is(err, ErrEngineUnavailable) {
				t.Fatalf("corruption must be engine_unavailable, got %v", err)
			}
			// The corrupt file must be left in place for the operator.
			if _, statErr := os.Stat(path); statErr != nil {
				t.Fatalf("registry was removed on corruption: %v", statErr)
			}
		})
	}
}

func TestManagerCorruptRegistryFailsClosedAtStartup(t *testing.T) {
	manager := newPerLabTestManager(t, 4)
	path := filepath.Join(manager.cfg.StateDir, uidMapFileName)
	if err := os.WriteFile(path, []byte("{broken"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := manager.PrepareLabUIDs(); err == nil {
		t.Fatal("startup validation must reject a corrupt registry")
	}
	identity, err := manager.labIdentityFor(Spec{ID: "node-a", LabID: "lab-a"})
	if err == nil || !errors.Is(err, ErrEngineUnavailable) {
		t.Fatalf("start after corrupt registry must fail closed, got %+v, %v", identity, err)
	}
}

func TestPerLabUIDsDifferForTwoLabs(t *testing.T) {
	manager := newPerLabTestManager(t, 4)
	first := allocateForTest(t, manager, "lab-a", "node-1")
	second := allocateForTest(t, manager, "lab-b", "node-1")
	if first.UID == second.UID || first.GID == second.GID {
		t.Fatalf("two labs share an identity: %+v vs %+v", first, second)
	}
	if !manager.labUIDInPool(first.UID) || !manager.labUIDInPool(second.UID) {
		t.Fatalf("allocated uid outside the pool: %+v %+v", first, second)
	}
	for _, identity := range []labIdentity{first, second} {
		found := false
		for _, gid := range identity.Groups {
			if gid == manager.cfg.ImageGID {
				found = true
			}
		}
		if !found {
			t.Fatalf("image gid %d missing from supplementary groups %v", manager.cfg.ImageGID, identity.Groups)
		}
		if len(dedupeGIDs(identity.Groups)) != len(identity.Groups) {
			t.Fatalf("supplementary groups are not deduplicated: %v", identity.Groups)
		}
	}
}

func TestRollbackModeUsesVMUser(t *testing.T) {
	cfg := perLabTestConfig(t, 4)
	cfg.PerLabUID = false
	cfg.VMUID = 997
	cfg.VMGID = 989
	cfg.VMGroups = []uint32{993}
	manager := NewManager(cfg, slog.New(slog.NewTextHandler(io.Discard, nil)))
	t.Cleanup(manager.Shutdown)

	identity, err := manager.labIdentityFor(Spec{ID: "node-1", LabID: "lab-a"})
	if err != nil {
		t.Fatalf("rollback identity: %v", err)
	}
	if identity.PerLab {
		t.Fatal("rollback mode must not use per-lab uids")
	}
	if identity.UID != 997 || identity.GID != 989 || len(identity.Groups) != 1 || identity.Groups[0] != 993 {
		t.Fatalf("unexpected rollback identity: %+v", identity)
	}
	if _, err := os.Stat(filepath.Join(cfg.StateDir, uidMapFileName)); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("rollback mode must not create a uid registry: %v", err)
	}
}

// TestEngineSpawnAppliesLabCredential pins SEC-24: every engine child gets the
// lab credential and the supplementary image/kvm groups. The launcher hook
// keeps the test root-free.
func TestEngineSpawnAppliesLabCredential(t *testing.T) {
	original := engineSpawn
	t.Cleanup(func() { engineSpawn = original })
	originalTap := openTapFile
	t.Cleanup(func() { openTapFile = originalTap })
	originalSocketRoot := iolSocketRoot
	t.Cleanup(func() { iolSocketRoot = originalSocketRoot })

	fakeTap := func(name string) (*os.File, error) {
		return os.OpenFile(os.DevNull, os.O_RDWR, 0)
	}
	sentinel := errors.New("credential captured")

	type engineCase struct {
		name  string
		start func(*Manager, Spec, string) (NodeInfo, error)
		build func(*testing.T, *Manager) Spec
	}
	cases := []engineCase{
		{
			name: "qemu",
			start: func(manager *Manager, spec Spec, runDir string) (NodeInfo, error) {
				return manager.startQEMU(spec, runDir)
			},
			build: func(t *testing.T, manager *Manager) Spec {
				directory := t.TempDir()
				binary := filepath.Join(directory, "qemu-system-x86_64")
				if err := os.WriteFile(binary, []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
					t.Fatal(err)
				}
				manager.cfg.QEMUBinary = binary

				return Spec{ID: "node-qemu", LabID: "lab-cred", Name: "Q"}
			},
		},
		{
			name: "vpcs",
			start: func(manager *Manager, spec Spec, runDir string) (NodeInfo, error) {
				return manager.startVPCS(spec, runDir)
			},
			build: func(t *testing.T, manager *Manager) Spec {
				directory := t.TempDir()
				binary := filepath.Join(directory, "vpcs")
				if err := os.WriteFile(binary, []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
					t.Fatal(err)
				}
				manager.cfg.VPCSBinary = binary
				openTapFile = fakeTap

				return Spec{
					ID:       "node-vpcs",
					LabID:    "lab-cred",
					Name:     "V",
					Engine:   "vpcs",
					Ethernet: 1,
					Networks: []string{network.BridgeName("lab-cred", "0")},
				}
			},
		},
		{
			name: "dynamips",
			start: func(manager *Manager, spec Spec, runDir string) (NodeInfo, error) {
				return manager.startDynamips(spec, runDir)
			},
			build: func(t *testing.T, manager *Manager) Spec {
				directory := t.TempDir()
				binary := filepath.Join(directory, "dynamips")
				if err := os.WriteFile(binary, []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
					t.Fatal(err)
				}
				manager.cfg.DynamipsBinary = binary

				return Spec{
					ID:       "node-dynamips",
					LabID:    "lab-cred",
					Name:     "D",
					Engine:   "dynamips",
					Platform: "c7200",
					Ethernet: 1,
					Slots:    []string{"0:0:tap"},
					Networks: []string{network.BridgeName("lab-cred", "0")},
					Image:    "/images/ios.bin",
				}
			},
		},
		{
			name: "iol",
			start: func(manager *Manager, spec Spec, runDir string) (NodeInfo, error) {
				return manager.startIOL(spec, runDir)
			},
			build: func(t *testing.T, manager *Manager) Spec {
				directory := t.TempDir()
				image := filepath.Join(directory, "iol.bin")
				if err := os.WriteFile(image, []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
					t.Fatal(err)
				}
				iourc := filepath.Join(directory, "iourc")
				if err := os.WriteFile(iourc, []byte("licence"), 0o600); err != nil {
					t.Fatal(err)
				}
				manager.cfg.IOLIourc = iourc
				iolSocketRoot = t.TempDir()
				openTapFile = fakeTap

				return Spec{
					ID:       "node-iol",
					LabID:    "lab-cred",
					Name:     "I",
					Engine:   "iol",
					Ethernet: 1,
					Networks: []string{network.BridgeName("lab-cred", "0")},
					Image:    image,
				}
			},
		},
	}

	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			manager := newPerLabTestManager(t, 4)
			spec := testCase.build(t, manager)
			spec.Normalize()
			var captured *exec.Cmd
			engineSpawn = func(command *exec.Cmd) error {
				captured = command
				return sentinel
			}
			identity, err := manager.labIdentityFor(spec)
			if err != nil {
				t.Fatalf("identity: %v", err)
			}
			runDir, err := manager.prepareRunDir(spec)
			if err != nil {
				t.Fatalf("prepare run dir: %v", err)
			}
			if _, err := testCase.start(manager, spec, runDir); !errors.Is(err, sentinel) {
				t.Fatalf("expected the launcher sentinel, got %v", err)
			}
			if captured == nil {
				t.Fatal("the engine launcher was not reached")
			}
			if captured.SysProcAttr == nil || !captured.SysProcAttr.Setpgid {
				t.Fatal("engine must stay in its own process group")
			}
			credential := captured.SysProcAttr.Credential
			if credential == nil {
				t.Fatal("engine spawn has no credential")
			}
			if credential.Uid != identity.UID || credential.Gid != identity.GID {
				t.Fatalf("engine credential = %d/%d, want %d/%d",
					credential.Uid, credential.Gid, identity.UID, identity.GID)
			}
			found := false
			for _, gid := range credential.Groups {
				if gid == manager.cfg.ImageGID {
					found = true
				}
			}
			if !found {
				t.Fatalf("engine groups %v miss the image gid %d", credential.Groups, manager.cfg.ImageGID)
			}
			environment := strings.Join(captured.Env, "\n")
			tmpDir := manager.privateTmpDir(spec)
			if tmpDir == "" {
				t.Fatal("per-lab mode has no private tmp")
			}
			for _, wanted := range []string{"HOME=" + tmpDir, "TMPDIR=" + tmpDir} {
				if !strings.Contains(environment, wanted) {
					t.Fatalf("engine environment missing %q: %v", wanted, captured.Env)
				}
			}
		})
	}
}

func TestQEMUImgUsesLabCredential(t *testing.T) {
	manager := newPerLabTestManager(t, 4)
	manager.cfg.QEMUImg = "/usr/bin/qemu-img"
	identity := allocateForTest(t, manager, "lab-overlay", "node-1")

	original := qemuImgRunner
	t.Cleanup(func() { qemuImgRunner = original })
	var captured *exec.Cmd
	qemuImgRunner = func(command *exec.Cmd) ([]byte, error) {
		captured = command

		return nil, nil
	}

	overlay := filepath.Join(t.TempDir(), "overlay.qcow2")
	if err := manager.createOverlay(identity, filepath.Join(t.TempDir(), "base.qcow2"), overlay); err != nil {
		t.Fatalf("create overlay: %v", err)
	}
	if captured == nil || captured.SysProcAttr == nil || captured.SysProcAttr.Credential == nil {
		t.Fatal("qemu-img did not receive a credential")
	}
	credential := captured.SysProcAttr.Credential
	if credential.Uid != identity.UID || credential.Gid != identity.GID {
		t.Fatalf("qemu-img credential = %d/%d, want %d/%d",
			credential.Uid, credential.Gid, identity.UID, identity.GID)
	}
	if !captured.SysProcAttr.Setpgid {
		t.Fatal("qemu-img must run in its own process group")
	}
}

func TestOwnershipStaysInsideTheLabSubtree(t *testing.T) {
	manager := newPerLabTestManager(t, 4)
	specA := Spec{ID: "node-a", LabID: "lab-a", Name: "A"}
	specB := Spec{ID: "node-b", LabID: "lab-b", Name: "B"}
	identityA := allocateForTest(t, manager, specA.LabID, specA.ID)
	runDirB, err := manager.prepareRunDir(specB)
	if err != nil {
		t.Fatalf("prepare lab-b: %v", err)
	}
	if err := os.WriteFile(filepath.Join(runDirB, "sentinel"), []byte("lab-b"), 0o640); err != nil {
		t.Fatal(err)
	}
	stateSentinel := filepath.Join(manager.cfg.StateDir, "sentinel")
	if err := os.WriteFile(stateSentinel, []byte("state"), 0o600); err != nil {
		t.Fatal(err)
	}

	var mu sync.Mutex
	var chowned []string
	originalChown := lchownFile
	t.Cleanup(func() { lchownFile = originalChown })
	lchownFile = func(path string, uid, gid int) error {
		mu.Lock()
		chowned = append(chowned, filepath.Clean(path))
		mu.Unlock()

		return nil
	}

	runDirA, err := manager.prepareRunDir(specA)
	if err != nil {
		t.Fatalf("prepare lab-a: %v", err)
	}
	file := filepath.Join(runDirA, "overlay.qcow2")
	if err := os.WriteFile(file, []byte("overlay"), 0o644); err != nil {
		t.Fatal(err)
	}
	socketPath := filepath.Join(runDirA, "monitor.sock")
	listener, err := net.ListenUnixgram("unixgram", &net.UnixAddr{Name: socketPath, Net: "unixgram"})
	if err != nil {
		t.Fatalf("create socket: %v", err)
	}
	defer listener.Close()

	if err := manager.ownLabRunTree(specA, identityA); err != nil {
		t.Fatalf("own lab-a: %v", err)
	}

	labRootA := manager.labRunRoot(specA)
	if err := manager.ownLabRunTree(specA, identityA); err != nil {
		t.Fatal(err)
	}
	mu.Lock()
	paths := append([]string(nil), chowned...)
	mu.Unlock()
	if len(paths) == 0 {
		t.Fatal("no ownership was applied")
	}
	for _, path := range paths {
		if !pathWithinRoot(labRootA, path) {
			t.Fatalf("chowned %s outside the lab-a subtree %s", path, labRootA)
		}
		if pathWithinRoot(filepath.Clean(runDirB), path) {
			t.Fatalf("ownership reached another lab: %s", path)
		}
		if path == filepath.Clean(stateSentinel) || pathWithinRoot(manager.cfg.StateDir, path) {
			t.Fatalf("ownership reached the state directory: %s", path)
		}
	}

	modes := map[string]os.FileMode{
		filepath.Join(runDirA, "overlay.qcow2"): 0o640,
		socketPath:                              0o660,
		filepath.Join(labRootA, "tmp"):          0o700,
		runDirA:                                 0o750,
	}
	for path, want := range modes {
		info, err := os.Lstat(path)
		if err != nil {
			t.Fatalf("stat %s: %v", path, err)
		}
		if info.Mode().Perm() != want {
			t.Fatalf("%s mode = %04o, want %04o", path, info.Mode().Perm(), want)
		}
	}
}

func TestPerLabCgroupPathIncludesLabComponent(t *testing.T) {
	manager := newPerLabTestManager(t, 4)
	path := manager.cgroupNodePath(Spec{ID: "node-1", LabID: "lab-1"})
	want := filepath.Join(manager.cfg.CgroupRoot, "lab-"+shortPathComponent("lab-1"), "node-node-1")
	if path != want {
		t.Fatalf("cgroup path = %s, want %s", path, want)
	}
}

func TestReconcileSweepsLabCgroups(t *testing.T) {
	manager := newPerLabTestManager(t, 4)
	manager.cfg.DisableCgroups = false
	live := manager.cgroupNodePath(Spec{ID: "node-live", LabID: "lab-live"})
	stale := manager.cgroupNodePath(Spec{ID: "node-stale", LabID: "lab-stale"})
	legacy := filepath.Join(manager.cfg.CgroupRoot, "node-legacy")
	for _, dir := range []string{live, stale, legacy} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	manager.nodes["node-live"] = &Node{
		Spec:       Spec{ID: "node-live", LabID: "lab-live"},
		State:      "running",
		CgroupPath: live,
	}

	result, err := manager.Reconcile(nil)
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if _, err := os.Stat(live); err != nil {
		t.Fatalf("live cgroup was removed: %v", err)
	}
	for _, dir := range []string{stale, legacy} {
		if _, err := os.Stat(dir); !errors.Is(err, os.ErrNotExist) {
			t.Fatalf("stale cgroup %s survived: %v", dir, err)
		}
	}
	if !contains(result.RemovedCgroups, stale) || !contains(result.RemovedCgroups, legacy) {
		t.Fatalf("stale cgroups not reported: %v", result.RemovedCgroups)
	}
	if _, err := os.Stat(filepath.Dir(stale)); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("empty lab cgroup directory survived: %v", err)
	}
}

func TestReconcileKeepsUIDRegistry(t *testing.T) {
	manager := newPerLabTestManager(t, 4)
	identity := allocateForTest(t, manager, "lab-a", "node-a")
	path := filepath.Join(manager.cfg.StateDir, uidMapFileName)

	result, err := manager.Reconcile(nil)
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if contains(result.QuarantinedState, path) {
		t.Fatalf("the uid registry was quarantined: %v", result.QuarantinedState)
	}
	again := allocateForTest(t, manager, "lab-a", "node-a")
	if again.UID != identity.UID {
		t.Fatalf("uid changed across reconcile: %d -> %d", identity.UID, again.UID)
	}
}

func TestReconcilePruneReleasesLabSlot(t *testing.T) {
	manager := newPerLabTestManager(t, 1)
	allocateForTest(t, manager, "lab-stale", "node-a")
	labDir := filepath.Join(manager.runRoot(), shortPathComponent("lab-stale"))
	if err := os.MkdirAll(labDir, 0o750); err != nil {
		t.Fatal(err)
	}

	// A second lab cannot start while the single slot is held.
	if _, err := manager.labIdentityFor(Spec{ID: "node-b", LabID: "lab-new"}); !errors.Is(err, ErrEngineUnavailable) {
		t.Fatalf("expected exhaustion before the prune, got %v", err)
	}
	if _, err := manager.Reconcile([]string{}); err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	identity, err := manager.labIdentityFor(Spec{ID: "node-b", LabID: "lab-new"})
	if err != nil {
		t.Fatalf("slot was not released by the prune: %v", err)
	}
	if identity.UID != 200000 {
		t.Fatalf("unexpected reused uid: %d", identity.UID)
	}
}

func TestUIDMismatchPreventsKill(t *testing.T) {
	manager := newPerLabTestManager(t, 4)
	sleepPath, err := exec.LookPath("sleep")
	if err != nil {
		t.Skip("sleep binary is required for this test")
	}
	manager.cfg.QEMUBinary = sleepPath

	identity := allocateForTest(t, manager, "lab1", "node1")
	if os.Geteuid() == int(identity.UID) {
		t.Skip("test process runs as the lab uid")
	}
	victim := exec.Command(sleepPath, "30")
	if err := victim.Start(); err != nil {
		t.Fatalf("start victim: %v", err)
	}
	t.Cleanup(func() {
		_ = victim.Process.Kill()
		_ = victim.Wait()
	})

	spec := Spec{ID: "node1", LabID: "lab1"}
	if _, err := writeTrustedState(t, manager, nodeState{
		ID:         "node1",
		LabID:      "lab1",
		Engine:     "qemu",
		Exe:        sleepPath,
		PID:        victim.Process.Pid,
		StartTicks: processStartTicks(victim.Process.Pid),
		UID:        identity.UID,
		GID:        identity.GID,
		Taps:       []string{spec.TapName(0)},
	}); err != nil {
		t.Fatalf("write state: %v", err)
	}

	result, err := manager.Reconcile(nil)
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if err := syscall.Kill(victim.Process.Pid, 0); err != nil {
		t.Fatalf("a process with a mismatched uid was killed: %v", err)
	}
	if len(result.KilledProcesses) != 0 {
		t.Fatalf("uid mismatch still produced a kill: %+v", result)
	}
}

func TestForeignUIDStateRecordIsRejected(t *testing.T) {
	manager := newPerLabTestManager(t, 4)
	sleepPath, err := exec.LookPath("sleep")
	if err != nil {
		t.Skip("sleep binary is required for this test")
	}
	manager.cfg.QEMUBinary = sleepPath
	identity := allocateForTest(t, manager, "lab1", "node1")

	victim := exec.Command(sleepPath, "30")
	if err := victim.Start(); err != nil {
		t.Fatalf("start victim: %v", err)
	}
	t.Cleanup(func() {
		_ = victim.Process.Kill()
		_ = victim.Wait()
	})

	spec := Spec{ID: "node1", LabID: "lab1"}
	path, err := writeTrustedState(t, manager, nodeState{
		ID:         "node1",
		LabID:      "lab1",
		Engine:     "qemu",
		Exe:        sleepPath,
		PID:        victim.Process.Pid,
		StartTicks: processStartTicks(victim.Process.Pid),
		UID:        identity.UID + 1,
		GID:        identity.UID + 1,
		Taps:       []string{spec.TapName(0)},
	})
	if err != nil {
		t.Fatalf("write state: %v", err)
	}
	result, err := manager.Reconcile(nil)
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if !contains(result.QuarantinedState, path) {
		t.Fatalf("foreign uid record was trusted: %+v", result.QuarantinedState)
	}
	if err := syscall.Kill(victim.Process.Pid, 0); err != nil {
		t.Fatalf("foreign uid record caused a kill: %v", err)
	}
}

func TestRollbackModeQuarantinesPerLabState(t *testing.T) {
	cfg := perLabTestConfig(t, 4)
	cfg.PerLabUID = false
	sleepPath, err := exec.LookPath("sleep")
	if err != nil {
		t.Skip("sleep binary is required for this test")
	}
	cfg.QEMUBinary = sleepPath
	manager := NewManager(cfg, slog.New(slog.NewTextHandler(io.Discard, nil)))
	manager.net.SysClassNet = t.TempDir()
	manager.net.Run = func(name string, args ...string) ([]byte, error) { return nil, nil }
	t.Cleanup(manager.Shutdown)

	victim := exec.Command(sleepPath, "30")
	if err := victim.Start(); err != nil {
		t.Fatalf("start victim: %v", err)
	}
	t.Cleanup(func() {
		_ = victim.Process.Kill()
		_ = victim.Wait()
	})
	path, err := writeTrustedState(t, manager, nodeState{
		ID:         "node1",
		LabID:      "lab1",
		Engine:     "qemu",
		Exe:        sleepPath,
		PID:        victim.Process.Pid,
		StartTicks: processStartTicks(victim.Process.Pid),
		UID:        200000,
		GID:        200000,
		Taps:       []string{Spec{ID: "node1", LabID: "lab1"}.TapName(0)},
	})
	if err != nil {
		t.Fatalf("write state: %v", err)
	}
	result, err := manager.Reconcile(nil)
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if !contains(result.QuarantinedState, path) {
		t.Fatalf("per-lab record was trusted in rollback mode: %+v", result.QuarantinedState)
	}
	if err := syscall.Kill(victim.Process.Pid, 0); err != nil {
		t.Fatalf("rollback mode killed on a per-lab record: %v", err)
	}
}

func TestProcessUIDReadsRealUID(t *testing.T) {
	uid, ok := processUID(os.Getpid())
	if !ok {
		t.Fatal("cannot read the current process uid")
	}
	if uid != uint32(os.Geteuid()) {
		t.Fatalf("processUID = %d, want %d", uid, os.Geteuid())
	}
}

// TestReconcileKeepsValidRecordWhenRegistryCorrupt pins that a registry failure
// is not a record failure: an otherwise-valid record stays in place and is
// neither signalled nor quarantined, while the error is reported.
func TestReconcileKeepsValidRecordWhenRegistryCorrupt(t *testing.T) {
	manager := newPerLabTestManager(t, 4)
	sleepPath, err := exec.LookPath("sleep")
	if err != nil {
		t.Skip("sleep binary is required for this test")
	}
	manager.cfg.QEMUBinary = sleepPath
	identity := allocateForTest(t, manager, "lab1", "node1")

	victim := exec.Command(sleepPath, "30")
	if err := victim.Start(); err != nil {
		t.Fatalf("start victim: %v", err)
	}
	t.Cleanup(func() {
		_ = victim.Process.Kill()
		_ = victim.Wait()
	})

	spec := Spec{ID: "node1", LabID: "lab1"}
	path, err := writeTrustedState(t, manager, nodeState{
		ID:         "node1",
		LabID:      "lab1",
		Engine:     "qemu",
		Exe:        sleepPath,
		PID:        victim.Process.Pid,
		StartTicks: processStartTicks(victim.Process.Pid),
		UID:        identity.UID,
		GID:        identity.GID,
		Taps:       []string{spec.TapName(0)},
	})
	if err != nil {
		t.Fatalf("write state: %v", err)
	}

	// The registry is corrupt even though the record is valid.
	if err := os.WriteFile(filepath.Join(manager.cfg.StateDir, uidMapFileName), []byte("{broken"), 0o600); err != nil {
		t.Fatal(err)
	}

	result, err := manager.Reconcile(nil)
	if err == nil {
		t.Fatal("a corrupt registry must be reported")
	}
	if !errors.Is(err, errStateRegistryUnavailable) {
		t.Fatalf("unexpected reconcile error: %v", err)
	}
	if _, statErr := os.Stat(path); statErr != nil {
		t.Fatalf("valid record was removed: %v", statErr)
	}
	if contains(result.QuarantinedState, path) {
		t.Fatalf("valid record was quarantined: %+v", result.QuarantinedState)
	}
	if len(result.KilledProcesses) != 0 {
		t.Fatalf("registry failure produced a kill: %+v", result)
	}
	if err := syscall.Kill(victim.Process.Pid, 0); err != nil {
		t.Fatalf("registry failure caused a kill: %v", err)
	}
}

// TestReconcilePruneKeepsSlotWhenUIDInUse pins that a slot is not released while
// a process still holds the uid (the run directory being gone is not enough).
func TestReconcilePruneKeepsSlotWhenUIDInUse(t *testing.T) {
	manager := newPerLabTestManager(t, 1)
	identity := allocateForTest(t, manager, "lab-stale", "node-a")
	labDir := filepath.Join(manager.runRoot(), shortPathComponent("lab-stale"))
	if err := os.MkdirAll(labDir, 0o750); err != nil {
		t.Fatal(err)
	}
	manager.uidProbe = func(uid uint32) bool { return uid == identity.UID }

	if _, err := manager.Reconcile([]string{}); err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if _, err := os.Stat(labDir); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("stale lab directory was not pruned: %v", err)
	}
	// The slot is leaked: a second lab cannot reuse a uid that may still run.
	if _, err := manager.labIdentityFor(Spec{ID: "node-b", LabID: "lab-new"}); !errors.Is(err, ErrEngineUnavailable) {
		t.Fatalf("slot was reused while the uid was in use, got %v", err)
	}
	// The original lab keeps its allocation.
	again := allocateForTest(t, manager, "lab-stale", "node-a")
	if again.UID != identity.UID {
		t.Fatalf("allocation changed: %d -> %d", identity.UID, again.UID)
	}
}

// TestReconcilePruneReleasesSlotWhenUIDFree pins that the slot is released as
// soon as no process holds the uid.
func TestReconcilePruneReleasesSlotWhenUIDFree(t *testing.T) {
	manager := newPerLabTestManager(t, 1)
	allocateForTest(t, manager, "lab-stale", "node-a")
	labDir := filepath.Join(manager.runRoot(), shortPathComponent("lab-stale"))
	if err := os.MkdirAll(labDir, 0o750); err != nil {
		t.Fatal(err)
	}
	manager.uidProbe = func(uint32) bool { return false }

	if _, err := manager.Reconcile([]string{}); err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	identity, err := manager.labIdentityFor(Spec{ID: "node-b", LabID: "lab-new"})
	if err != nil {
		t.Fatalf("free slot was not released: %v", err)
	}
	if identity.UID != 200000 {
		t.Fatalf("unexpected reused uid: %d", identity.UID)
	}
}

// TestPrepareRunDirRejectsSymlinkedNodeDir pins that an engine-planted symlink
// at RunDir/<lab>/<node> aborts the start and that the target is untouched.
func TestPrepareRunDirRejectsSymlinkedNodeDir(t *testing.T) {
	manager := newPerLabTestManager(t, 4)
	spec := Spec{ID: "node-a", LabID: "lab-a", Name: "A"}
	labRoot := manager.labRunRoot(spec)
	if err := os.MkdirAll(labRoot, 0o750); err != nil {
		t.Fatal(err)
	}
	target := t.TempDir()
	sentinel := filepath.Join(target, "sentinel")
	if err := os.WriteFile(sentinel, []byte("untouched"), 0o640); err != nil {
		t.Fatal(err)
	}
	runDir := manager.runDirFor(spec.ID, spec.LabID)
	if err := os.Symlink(target, runDir); err != nil {
		t.Fatalf("plant symlink: %v", err)
	}

	if _, err := manager.prepareRunDir(spec); err == nil {
		t.Fatal("a symlinked run directory must abort the start")
	}
	info, err := os.Lstat(runDir)
	if err != nil {
		t.Fatalf("symlink was removed: %v", err)
	}
	if info.Mode()&os.ModeSymlink == 0 {
		t.Fatalf("symlink was replaced by %v", info.Mode())
	}
	data, err := os.ReadFile(sentinel)
	if err != nil || string(data) != "untouched" {
		t.Fatalf("symlink target was modified: %q %v", data, err)
	}
}

// TestPrepareRunDirRejectsSymlinkedLabRoot covers a symlink one level higher:
// the lab directory itself must resolve inside the run root.
func TestPrepareRunDirRejectsSymlinkedLabRoot(t *testing.T) {
	manager := newPerLabTestManager(t, 4)
	spec := Spec{ID: "node-a", LabID: "lab-a", Name: "A"}
	target := t.TempDir()
	sentinel := filepath.Join(target, "sentinel")
	if err := os.WriteFile(sentinel, []byte("untouched"), 0o640); err != nil {
		t.Fatal(err)
	}
	labRoot := manager.labRunRoot(spec)
	if err := os.Symlink(target, labRoot); err != nil {
		t.Fatalf("plant symlink: %v", err)
	}

	if _, err := manager.prepareRunDir(spec); err == nil {
		t.Fatal("a symlinked lab run root must abort the start")
	}
	data, err := os.ReadFile(sentinel)
	if err != nil || string(data) != "untouched" {
		t.Fatalf("symlink target was modified: %q %v", data, err)
	}
}

// TestPrepareRunDirCreatesRealDirectories pins the happy path: the lab root,
// node directory and private tmp are real directories below the run root.
func TestPrepareRunDirCreatesRealDirectories(t *testing.T) {
	manager := newPerLabTestManager(t, 4)
	spec := Spec{ID: "node-a", LabID: "lab-a", Name: "A"}

	runDir, err := manager.prepareRunDir(spec)
	if err != nil {
		t.Fatalf("prepare run dir: %v", err)
	}
	labRoot := manager.labRunRoot(spec)
	tmp := manager.privateTmpDir(spec)
	for _, path := range []string{labRoot, runDir, tmp} {
		info, statErr := os.Lstat(path)
		if statErr != nil {
			t.Fatalf("stat %s: %v", path, statErr)
		}
		if info.Mode()&os.ModeSymlink != 0 || !info.IsDir() {
			t.Fatalf("%s is not a real directory: %v", path, info.Mode())
		}
	}
	resolvedRun, err := filepath.EvalSymlinks(runDir)
	if err != nil {
		t.Fatal(err)
	}
	resolvedLab, err := filepath.EvalSymlinks(labRoot)
	if err != nil {
		t.Fatal(err)
	}
	if resolvedRun == resolvedLab || !pathWithinRoot(resolvedLab, resolvedRun) {
		t.Fatalf("run directory %q does not resolve inside %q", resolvedRun, resolvedLab)
	}
}
