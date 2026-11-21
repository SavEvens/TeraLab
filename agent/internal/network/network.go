// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package network

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
)

// Backends supported for lab networks.
const (
	BackendLinux = "linux"
	BackendOVS   = "ovs"
	BackendAuto  = "auto"
)

// DefaultBridgeGroupFwdMask forwards STP (bit 0) and LACP (bit 2) frames.
// Per-port masks on Linux >= 4.15 only reject MAC pause frames, so this lets
// the lab switches run STP and LACP without a patched kernel.
const DefaultBridgeGroupFwdMask uint16 = 0x5

// CommandRunner executes an external command and returns combined output.
type CommandRunner func(name string, args ...string) ([]byte, error)

type Manager struct {
	VMUser      string
	SysClassNet string
	Backend     string
	// BridgeGroupFwdMask is written to every bridge port's group_fwd_mask;
	// 0 disables the per-port write. The bridge-level mask is kept either way.
	BridgeGroupFwdMask uint16
	// Logf receives one warning per bridge when the per-port attribute is
	// unavailable. Nil disables logging.
	Logf func(format string, args ...any)
	// WriteFile is os.WriteFile; tests replace it to inject kernel errors.
	WriteFile func(path string, data []byte, perm os.FileMode) error
	Run       CommandRunner

	warnMu sync.Mutex
	warned map[string]bool
}

func New(vmUser, backend string) *Manager {
	manager := &Manager{
		VMUser:             vmUser,
		SysClassNet:        "/sys/class/net",
		Backend:            backend,
		BridgeGroupFwdMask: DefaultBridgeGroupFwdMask,
		WriteFile:          os.WriteFile,
		Run:                defaultRunner,
		warned:             map[string]bool{},
	}
	if manager.Backend == "" || manager.Backend == BackendAuto {
		if _, err := exec.LookPath("ovs-vsctl"); err == nil {
			manager.Backend = BackendOVS
		} else {
			manager.Backend = BackendLinux
		}
	}

	return manager
}

func defaultRunner(name string, args ...string) ([]byte, error) {
	return exec.Command(name, args...).CombinedOutput()
}

// LabTag is the stable four-character tag for a lab.
func LabTag(labID string) string {
	sum := sha256.Sum256([]byte("lab:" + labID))

	return hex.EncodeToString(sum[:2])
}

// BridgeName returns a deterministic, 13-character lab network bridge name.
func BridgeName(labID, networkID string) string {
	sum := sha256.Sum256([]byte("net:" + labID + ":" + networkID))

	return "tl" + LabTag(labID) + "b" + hex.EncodeToString(sum[:3])
}

// TapName returns a deterministic, 15-character node interface tap name.
func TapName(labID, nodeID string, index int) string {
	sum := sha256.Sum256([]byte("tap:" + labID + ":" + nodeID + ":" + strconv.Itoa(index)))

	return "tl" + LabTag(labID) + "t" + hex.EncodeToString(sum[:3]) + fmt.Sprintf("%02x", index)
}

// EnsureBridge creates or refreshes a lab bridge using the configured backend.
func (m *Manager) EnsureBridge(name string) error {
	if err := ValidateLinkName(name); err != nil {
		return err
	}
	if m.Backend == BackendOVS {
		return m.ensureOVSBridge(name)
	}

	if !m.linkExists(name) {
		if output, err := m.Run("ip", "link", "add", "name", name, "type", "bridge"); err != nil {
			return fmt.Errorf("create bridge %s: %s: %w", name, strings.TrimSpace(string(output)), err)
		}
	}
	if err := m.enableGroupForwarding(name); err != nil {
		return err
	}
	// Re-apply per-port forwarding to ports that already exist, so restarting
	// a lab refreshes taps that survived a crash.
	m.applyBridgePortForwarding(name)
	if err := m.writeSysfs(name, "bridge/multicast_snooping", "0"); err != nil {
		return err
	}
	if output, err := m.Run("ip", "link", "set", "dev", name, "up"); err != nil {
		return fmt.Errorf("bring bridge %s up: %s: %w", name, strings.TrimSpace(string(output)), err)
	}

	return nil
}

func (m *Manager) ensureOVSBridge(name string) error {
	if output, err := m.Run("ovs-vsctl", "--may-exist", "add-br", name); err != nil {
		return fmt.Errorf("create ovs bridge %s: %s: %w", name, strings.TrimSpace(string(output)), err)
	}
	// Forward reserved control frames (LLDP and LACP) instead of dropping them.
	if output, err := m.Run("ovs-vsctl", "set", "bridge", name, "other_config:forward-bpdu=true"); err != nil {
		return fmt.Errorf("enable ovs frame forwarding on %s: %s: %w", name, strings.TrimSpace(string(output)), err)
	}
	if output, err := m.Run("ip", "link", "set", "dev", name, "up"); err != nil {
		return fmt.Errorf("bring ovs bridge %s up: %s: %w", name, strings.TrimSpace(string(output)), err)
	}

	return nil
}

// enableGroupForwarding enables LLDP and other reserved multicast groups.
//
// Newer kernels reject bits 0-2 (STP, 802.3x, LACP); the highest accepted mask
// keeps LLDP and every other group working. STP and LACP are forwarded per port
// instead (see applyPortForwarding), where only MAC pause is reserved.
func (m *Manager) enableGroupForwarding(name string) error {
	var lastErr error
	for _, value := range []string{"65535", "65528", "16384"} {
		err := m.writeSysfs(name, "bridge/group_fwd_mask", value)
		if err == nil {
			return nil
		}
		lastErr = err
		if !strings.Contains(err.Error(), "invalid argument") {
			return err
		}
	}

	return lastErr
}

// applyBridgePortForwarding refreshes the per-port mask on every existing port
// of a bridge. A missing bridge directory is normal on first creation.
func (m *Manager) applyBridgePortForwarding(bridge string) {
	if m.BridgeGroupFwdMask == 0 {
		return
	}
	entries, err := os.ReadDir(filepath.Join(m.SysClassNet, bridge, "brif"))
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return
		}
		m.warnOnce("bridge:"+bridge, "bridge %s: cannot list ports for group_fwd_mask: %v", bridge, err)
		return
	}
	for _, entry := range entries {
		m.applyPortForwarding(bridge, entry.Name())
	}
}

// applyPortForwarding forwards STP and LACP frames arriving on one tap.
// Kernels older than 4.15 and the OVS backend have no per-port attribute;
// that degrades to a single warning so a lab always starts.
func (m *Manager) applyPortForwarding(bridge, tap string) {
	if m.BridgeGroupFwdMask == 0 {
		return
	}
	write := m.WriteFile
	if write == nil {
		write = os.WriteFile
	}
	path := filepath.Join(m.SysClassNet, bridge, "brif", tap, "group_fwd_mask")
	value := fmt.Sprintf("0x%x", m.BridgeGroupFwdMask)
	if err := write(path, []byte(value), 0o644); err != nil {
		m.warnOnce("port:"+bridge, "bridge %s: per-port group_fwd_mask unavailable: %v", bridge, err)
	}
}

// warnOnce logs the first failure for a key; repeated taps on the same bridge
// must not flood the log.
func (m *Manager) warnOnce(key, format string, args ...any) {
	if m.Logf == nil {
		return
	}
	m.warnMu.Lock()
	if m.warned == nil {
		m.warned = map[string]bool{}
	}
	if m.warned[key] {
		m.warnMu.Unlock()
		return
	}
	m.warned[key] = true
	m.warnMu.Unlock()

	m.Logf(format, args...)
}

// EnsureLab creates every bridge for a lab and returns bridge names by network id.
func (m *Manager) EnsureLab(labID string, networkIDs []string) (map[string]string, error) {
	bridges := make(map[string]string, len(networkIDs))
	for _, networkID := range networkIDs {
		name := BridgeName(labID, networkID)
		if err := m.EnsureBridge(name); err != nil {
			return nil, err
		}
		bridges[networkID] = name
	}

	return bridges, nil
}

// CreateTap creates a tap owned by the unprivileged VM user and attaches it.
func (m *Manager) CreateTap(tap, bridge string) error {
	if err := ValidateLinkName(tap); err != nil {
		return err
	}
	if bridge != "" {
		if err := ValidateLinkName(bridge); err != nil {
			return err
		}
	}
	m.DeleteLink(tap)

	args := []string{"tuntap", "add", "dev", tap, "mode", "tap"}
	if m.VMUser != "" {
		args = append(args, "user", m.VMUser)
	}
	if output, err := m.Run("ip", args...); err != nil {
		return fmt.Errorf("create tap %s: %s: %w", tap, strings.TrimSpace(string(output)), err)
	}
	if bridge != "" {
		if m.Backend == BackendOVS {
			if output, err := m.Run("ovs-vsctl", "--may-exist", "add-port", bridge, tap); err != nil {
				m.DeleteLink(tap)
				return fmt.Errorf("attach tap %s to %s: %s: %w", tap, bridge, strings.TrimSpace(string(output)), err)
			}
		} else {
			if output, err := m.Run("ip", "link", "set", "dev", tap, "master", bridge); err != nil {
				m.DeleteLink(tap)
				return fmt.Errorf("attach tap %s to %s: %s: %w", tap, bridge, strings.TrimSpace(string(output)), err)
			}
			m.applyPortForwarding(bridge, tap)
		}
	}
	if output, err := m.Run("ip", "link", "set", "dev", tap, "up"); err != nil {
		m.DeleteLink(tap)
		return fmt.Errorf("bring tap %s up: %s: %w", tap, strings.TrimSpace(string(output)), err)
	}

	return nil
}

// AttachInterface attaches an existing interface to a lab bridge using the
// configured backend, applies the same per-port forwarding mask as CreateTap,
// and brings the link up. Container nodes use it for the host end of a veth
// pair; TeraLab remains the sole owner of lab bridges.
func (m *Manager) AttachInterface(link, bridge string) error {
	if err := ValidateLinkName(link); err != nil {
		return err
	}
	if bridge != "" {
		if err := ValidateLinkName(bridge); err != nil {
			return err
		}
		if m.Backend == BackendOVS {
			if output, err := m.Run("ovs-vsctl", "--may-exist", "add-port", bridge, link); err != nil {
				return fmt.Errorf("attach %s to %s: %s: %w", link, bridge, strings.TrimSpace(string(output)), err)
			}
		} else {
			if output, err := m.Run("ip", "link", "set", "dev", link, "master", bridge); err != nil {
				return fmt.Errorf("attach %s to %s: %s: %w", link, bridge, strings.TrimSpace(string(output)), err)
			}
			m.applyPortForwarding(bridge, link)
		}
	}
	if output, err := m.Run("ip", "link", "set", "dev", link, "up"); err != nil {
		return fmt.Errorf("bring %s up: %s: %w", link, strings.TrimSpace(string(output)), err)
	}

	return nil
}

// DeleteLink removes a link and tolerates an already-missing device.
func (m *Manager) DeleteLink(name string) error {
	if err := ValidateLinkName(name); err != nil {
		return err
	}
	if m.Backend == BackendOVS {
		// Deleting a nonexistent bridge or port is a no-op with --if-exists.
		_, _ = m.Run("ovs-vsctl", "--if-exists", "del-br", name)
		_, _ = m.Run("ovs-vsctl", "--if-exists", "del-port", name)
	}
	output, err := m.Run("ip", "link", "delete", name)
	if err != nil && !strings.Contains(strings.ToLower(string(output)), "cannot find device") {
		return fmt.Errorf("delete link %s: %s: %w", name, strings.TrimSpace(string(output)), err)
	}

	return nil
}

// CleanupLab removes lab-owned links whose names start with the lab tag,
// sparing every link the caller still needs.
func (m *Manager) CleanupLab(labID string, keep map[string]bool) ([]string, error) {
	prefix := "tl" + LabTag(labID)
	return m.removeLinksByPrefix(prefix, keep)
}

// CleanupStale removes TeraLab-owned links that are not in the keep set.
func (m *Manager) CleanupStale(keep map[string]bool) ([]string, error) {
	return m.removeLinksByPrefix("tl", keep)
}

// ListOwned returns all TeraLab-owned link names.
func (m *Manager) ListOwned() ([]string, error) {
	entries, err := os.ReadDir(m.SysClassNet)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return nil, nil
		}
		return nil, err
	}

	names := make([]string, 0, len(entries))
	for _, entry := range entries {
		if strings.HasPrefix(entry.Name(), "tl") {
			names = append(names, entry.Name())
		}
	}
	sort.Strings(names)

	return names, nil
}

// LinkExists reports whether a network device currently exists.
func (m *Manager) LinkExists(name string) bool {
	return m.linkExists(name)
}

func (m *Manager) linkExists(name string) bool {
	if err := ValidateLinkName(name); err != nil {
		return false
	}
	_, err := os.Stat(filepath.Join(m.SysClassNet, name))

	return err == nil
}

func (m *Manager) writeSysfs(link, suffix, value string) error {
	path := filepath.Join(m.SysClassNet, link, filepath.FromSlash(suffix))
	if err := os.WriteFile(path, []byte(value), 0o644); err != nil {
		return fmt.Errorf("configure %s/%s: %w", link, suffix, err)
	}

	return nil
}

func (m *Manager) removeLinksByPrefix(prefix string, keep map[string]bool) ([]string, error) {
	owned, err := m.ListOwned()
	if err != nil {
		return nil, err
	}

	removed := make([]string, 0)
	var firstErr error
	for _, name := range owned {
		if !strings.HasPrefix(name, prefix) {
			continue
		}
		if keep != nil && keep[name] {
			continue
		}
		if err := m.DeleteLink(name); err != nil {
			if firstErr == nil {
				firstErr = err
			}
			continue
		}
		removed = append(removed, name)
	}

	return removed, firstErr
}
