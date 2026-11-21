// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package network

import (
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
)

type recorded struct {
	name string
	args []string
}

func testManager(t *testing.T) (*Manager, *[]recorded) {
	t.Helper()
	sys := t.TempDir()
	calls := []recorded{}
	manager := New("teralab-vm", BackendLinux)
	manager.SysClassNet = sys
	manager.Run = func(name string, args ...string) ([]byte, error) {
		calls = append(calls, recorded{name: name, args: append([]string(nil), args...)})
		return nil, nil
	}

	return manager, &calls
}

func TestNamesAreDeterministicAndBounded(t *testing.T) {
	bridge := BridgeName("lab-1", "0")
	if bridge != BridgeName("lab-1", "0") {
		t.Fatal("bridge name must be deterministic")
	}
	if bridge == BridgeName("lab-2", "0") {
		t.Fatal("different labs must not share a bridge name")
	}
	if len(bridge) > 15 {
		t.Fatalf("bridge name too long: %s", bridge)
	}

	tap := TapName("lab-1", "node-1", 3)
	if len(tap) > 15 {
		t.Fatalf("tap name too long: %s", tap)
	}
	if tap == TapName("lab-1", "node-2", 3) {
		t.Fatal("different nodes must not share a tap name")
	}
	if tap == TapName("lab-1", "node-1", 4) {
		t.Fatal("different interfaces must not share a tap name")
	}
	if err := ValidateLinkName(tap); err != nil {
		t.Fatalf("generated tap name is invalid: %v", err)
	}
}

func TestValidateLinkName(t *testing.T) {
	for _, name := range []string{"tlabcdb123456", "tlx", "eth0.100"} {
		if err := ValidateLinkName(name); err != nil {
			t.Fatalf("expected %q to be valid: %v", name, err)
		}
	}
	for _, name := range []string{"", "tl/evil", "a-very-long-interface-name", "-leading"} {
		if err := ValidateLinkName(name); err == nil {
			t.Fatalf("expected %q to be rejected", name)
		}
	}
}

func TestEnsureBridgeEnablesLLDPForwarding(t *testing.T) {
	manager, calls := testManager(t)
	bridge := BridgeName("lab-1", "net-0")
	if err := os.MkdirAll(filepath.Join(manager.SysClassNet, bridge, "bridge"), 0o755); err != nil {
		t.Fatal(err)
	}
	// Pretend the bridge already exists; only configuration is needed.
	if err := manager.EnsureBridge(bridge); err != nil {
		t.Fatalf("ensure bridge: %v", err)
	}

	mask, err := os.ReadFile(filepath.Join(manager.SysClassNet, bridge, "bridge", "group_fwd_mask"))
	if err != nil || string(mask) != "65535" {
		t.Fatalf("group_fwd_mask not set: %q %v", mask, err)
	}
	snooping, err := os.ReadFile(filepath.Join(manager.SysClassNet, bridge, "bridge", "multicast_snooping"))
	if err != nil || string(snooping) != "0" {
		t.Fatalf("multicast_snooping not set: %q %v", snooping, err)
	}
	if !hasCall(*calls, "ip", "link", "set", "dev", bridge, "up") {
		t.Fatalf("bridge not brought up: %+v", *calls)
	}
}

func TestCreateTapUsesVMUserAndBridge(t *testing.T) {
	manager, calls := testManager(t)
	tap := TapName("lab-1", "node-1", 0)
	bridge := BridgeName("lab-1", "0")

	if err := manager.CreateTap(tap, bridge); err != nil {
		t.Fatalf("create tap: %v", err)
	}
	if !hasCall(*calls, "ip", "tuntap", "add", "dev", tap, "mode", "tap", "user", "teralab-vm") {
		t.Fatalf("tap not created with vm user: %+v", *calls)
	}
	if !hasCall(*calls, "ip", "link", "set", "dev", tap, "master", bridge) {
		t.Fatalf("tap not attached to bridge: %+v", *calls)
	}
}

func TestCleanupStaleKeepsLiveLinks(t *testing.T) {
	manager, calls := testManager(t)
	for _, name := range []string{"tlkeep0000000", "tlstale000000", "eth0"} {
		if err := os.MkdirAll(filepath.Join(manager.SysClassNet, name), 0o755); err != nil {
			t.Fatal(err)
		}
	}

	removed, err := manager.CleanupStale(map[string]bool{"tlkeep0000000": true})
	if err != nil {
		t.Fatalf("cleanup: %v", err)
	}
	if len(removed) != 1 || removed[0] != "tlstale000000" {
		t.Fatalf("unexpected removed links: %v", removed)
	}
	if hasCall(*calls, "ip", "link", "delete", "tlkeep0000000") {
		t.Fatal("live link must not be removed")
	}
	if hasCall(*calls, "ip", "link", "delete", "eth0") {
		t.Fatal("non-TeraLab link must not be removed")
	}
}

func TestCleanupLabOnlyTouchesThatLab(t *testing.T) {
	manager, calls := testManager(t)
	first := BridgeName("lab-1", "0")
	second := BridgeName("lab-2", "0")
	for _, name := range []string{first, second} {
		if err := os.MkdirAll(filepath.Join(manager.SysClassNet, name), 0o755); err != nil {
			t.Fatal(err)
		}
	}

	removed, err := manager.CleanupLab("lab-1", nil)
	if err != nil {
		t.Fatalf("cleanup lab: %v", err)
	}
	if len(removed) != 1 || removed[0] != first {
		t.Fatalf("unexpected removed links: %v", removed)
	}
	if hasCall(*calls, "ip", "link", "delete", second) {
		t.Fatal("another lab's bridge must not be removed")
	}
}

func TestCleanupLabKeepsRequestedLinks(t *testing.T) {
	manager, calls := testManager(t)
	bridge := BridgeName("lab-1", "0")
	tap := TapName("lab-1", "node-1", 0)
	stale := BridgeName("lab-1", "1")
	other := BridgeName("lab-2", "0")
	for _, name := range []string{bridge, tap, stale, other} {
		if err := os.MkdirAll(filepath.Join(manager.SysClassNet, name), 0o755); err != nil {
			t.Fatal(err)
		}
	}

	keep := map[string]bool{bridge: true, tap: true}
	removed, err := manager.CleanupLab("lab-1", keep)
	if err != nil {
		t.Fatalf("cleanup lab: %v", err)
	}
	if len(removed) != 1 || removed[0] != stale {
		t.Fatalf("unexpected removed links: %v", removed)
	}
	for _, name := range []string{bridge, tap, other} {
		if hasCall(*calls, "ip", "link", "delete", name) {
			t.Fatalf("link %s must not be removed: %+v", name, *calls)
		}
	}
	if !hasCall(*calls, "ip", "link", "delete", stale) {
		t.Fatalf("unkept lab link was not removed: %+v", *calls)
	}
}

func TestEnsureLabCreatesBridges(t *testing.T) {
	manager, _ := testManager(t)
	for _, id := range []string{"0", "1"} {
		name := BridgeName("lab-1", id)
		if err := os.MkdirAll(filepath.Join(manager.SysClassNet, name, "bridge"), 0o755); err != nil {
			t.Fatal(err)
		}
	}

	bridges, err := manager.EnsureLab("lab-1", []string{"0", "1"})
	if err != nil {
		t.Fatalf("ensure lab: %v", err)
	}
	if bridges["0"] != BridgeName("lab-1", "0") || bridges["1"] != BridgeName("lab-1", "1") {
		t.Fatalf("unexpected bridge map: %v", bridges)
	}
}

func hasCall(calls []recorded, name string, args ...string) bool {
	for _, call := range calls {
		if call.name != name || len(call.args) != len(args) {
			continue
		}
		match := true
		for index := range args {
			if call.args[index] != args[index] {
				match = false
				break
			}
		}
		if match {
			return true
		}
	}

	return false
}

func TestDeleteLinkToleratesMissingDevice(t *testing.T) {
	manager := New("", BackendLinux)
	manager.Run = func(name string, args ...string) ([]byte, error) {
		return []byte("Cannot find device \"tlmissing\""), os.ErrNotExist
	}
	if err := manager.DeleteLink("tlmissing0000"); err != nil {
		t.Fatalf("missing device must be tolerated: %v", err)
	}
}

func TestOVSBackend(t *testing.T) {
	manager, calls := testManager(t)
	manager.Backend = BackendOVS
	bridge := BridgeName("lab-1", "0")
	if err := os.MkdirAll(filepath.Join(manager.SysClassNet, bridge), 0o755); err != nil {
		t.Fatal(err)
	}

	if err := manager.EnsureBridge(bridge); err != nil {
		t.Fatalf("ensure ovs bridge: %v", err)
	}
	if !hasCall(*calls, "ovs-vsctl", "--may-exist", "add-br", bridge) {
		t.Fatalf("ovs bridge not created: %+v", *calls)
	}
	if !hasCall(*calls, "ovs-vsctl", "set", "bridge", bridge, "other_config:forward-bpdu=true") {
		t.Fatalf("ovs forwarding not enabled: %+v", *calls)
	}

	tap := TapName("lab-1", "node-1", 0)
	if err := manager.CreateTap(tap, bridge); err != nil {
		t.Fatalf("create ovs tap: %v", err)
	}
	if !hasCall(*calls, "ovs-vsctl", "--may-exist", "add-port", bridge, tap) {
		t.Fatalf("tap not added as ovs port: %+v", *calls)
	}
	if hasCall(*calls, "ip", "link", "set", "dev", tap, "master", bridge) {
		t.Fatal("ovs taps must not use the linux bridge master")
	}
}

func TestBridgeNamesStayShort(t *testing.T) {
	for index := 0; index < 100; index++ {
		name := BridgeName("lab-"+strings.Repeat("x", index%20), string(rune('a'+index%26)))
		if len(name) > 15 {
			t.Fatalf("bridge name too long: %s", name)
		}
	}
}

func TestEnsureBridgeAppliesPerPortGroupForwardMask(t *testing.T) {
	manager, _ := testManager(t)
	bridge := BridgeName("lab-1", "0")
	taps := []string{TapName("lab-1", "node-1", 0), TapName("lab-1", "node-2", 0)}
	if err := os.MkdirAll(filepath.Join(manager.SysClassNet, bridge, "bridge"), 0o755); err != nil {
		t.Fatal(err)
	}
	for _, tap := range taps {
		if err := os.MkdirAll(filepath.Join(manager.SysClassNet, bridge, "brif", tap), 0o755); err != nil {
			t.Fatal(err)
		}
	}

	if err := manager.EnsureBridge(bridge); err != nil {
		t.Fatalf("ensure bridge: %v", err)
	}
	for _, tap := range taps {
		mask, err := os.ReadFile(filepath.Join(manager.SysClassNet, bridge, "brif", tap, "group_fwd_mask"))
		if err != nil || string(mask) != "0x5" {
			t.Fatalf("per-port group_fwd_mask for %s: %q %v", tap, mask, err)
		}
	}
}

func TestCreateTapAppliesPerPortGroupForwardMask(t *testing.T) {
	manager, _ := testManager(t)
	tap := TapName("lab-1", "node-1", 0)
	bridge := BridgeName("lab-1", "0")
	if err := os.MkdirAll(filepath.Join(manager.SysClassNet, bridge, "brif", tap), 0o755); err != nil {
		t.Fatal(err)
	}

	if err := manager.CreateTap(tap, bridge); err != nil {
		t.Fatalf("create tap: %v", err)
	}
	mask, err := os.ReadFile(filepath.Join(manager.SysClassNet, bridge, "brif", tap, "group_fwd_mask"))
	if err != nil || string(mask) != "0x5" {
		t.Fatalf("per-port group_fwd_mask: %q %v", mask, err)
	}
}

func TestPerPortGroupForwardMaskDisabledByZero(t *testing.T) {
	manager, _ := testManager(t)
	manager.BridgeGroupFwdMask = 0
	bridge := BridgeName("lab-1", "0")
	tap := TapName("lab-1", "node-1", 0)
	if err := os.MkdirAll(filepath.Join(manager.SysClassNet, bridge, "bridge"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(manager.SysClassNet, bridge, "brif", tap), 0o755); err != nil {
		t.Fatal(err)
	}

	if err := manager.EnsureBridge(bridge); err != nil {
		t.Fatalf("ensure bridge: %v", err)
	}
	if err := manager.CreateTap(tap, bridge); err != nil {
		t.Fatalf("create tap: %v", err)
	}
	if _, err := os.Stat(filepath.Join(manager.SysClassNet, bridge, "brif", tap, "group_fwd_mask")); !os.IsNotExist(err) {
		t.Fatalf("mask 0 must disable the per-port write, got %v", err)
	}
}

func TestEnsureBridgeToleratesPerPortMaskErrors(t *testing.T) {
	manager, _ := testManager(t)
	bridge := BridgeName("lab-1", "0")
	tap := TapName("lab-1", "node-1", 0)
	if err := os.MkdirAll(filepath.Join(manager.SysClassNet, bridge, "bridge"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(manager.SysClassNet, bridge, "brif", tap), 0o755); err != nil {
		t.Fatal(err)
	}
	warnings := 0
	manager.Logf = func(string, ...any) { warnings++ }
	manager.WriteFile = func(string, []byte, os.FileMode) error { return syscall.EINVAL }

	for attempt := 0; attempt < 3; attempt++ {
		if err := manager.EnsureBridge(bridge); err != nil {
			t.Fatalf("per-port EINVAL must not fail the bridge: %v", err)
		}
	}
	if warnings != 1 {
		t.Fatalf("expected one warning per bridge, got %d", warnings)
	}
}
