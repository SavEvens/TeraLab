// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"

	"github.com/SavEvens/TeraLab/agent/internal/config"
	"github.com/SavEvens/TeraLab/agent/internal/network"
)

// fallbackStateComponent is used only when Config.StateDir is empty (tests and
// programmatic callers). Production always sets StateDir. The directory stays
// root-owned 0700 and is excluded from lab-directory pruning.
const fallbackStateComponent = ".state"

// stateRoot returns the trusted state directory as an absolute path.
func (m *Manager) stateRoot() string {
	return stateRootFor(m.cfg)
}

// stateRootFor derives the trusted state directory. It is a package function so
// the manager can construct the uid registry before any method is available.
func stateRootFor(cfg config.Config) string {
	if cfg.StateDir != "" {
		if absolute, err := filepath.Abs(cfg.StateDir); err == nil {
			return absolute
		}
		return cfg.StateDir
	}
	runRoot, err := filepath.Abs(cfg.RunDir)
	if err != nil {
		return filepath.Join(cfg.RunDir, fallbackStateComponent)
	}

	return filepath.Join(runRoot, fallbackStateComponent)
}

// statePathFor derives StateDir/<short lab>/<short node>.json. Nodes without a
// lab use StateDir/<short node>.json, mirroring the run-directory layout.
func (m *Manager) statePathFor(id, labID string) string {
	parts := []string{m.stateRoot()}
	if labID != "" {
		parts = append(parts, shortPathComponent(labID))
	}
	parts = append(parts, shortPathComponent(id)+".json")

	return filepath.Join(parts...)
}

// runDirFor derives the run directory for a node. The same derivation is used
// when starting a node and when validating a state record, so a state file
// cannot point the sweep at an unrelated directory.
func (m *Manager) runDirFor(id, labID string) string {
	parts := []string{m.runRoot()}
	if labID != "" {
		parts = append(parts, shortPathComponent(labID))
	}
	parts = append(parts, shortPathComponent(id))

	return filepath.Join(parts...)
}

func (m *Manager) runRoot() string {
	absolute, err := filepath.Abs(m.cfg.RunDir)
	if err != nil {
		return m.cfg.RunDir
	}

	return absolute
}

// writeStateFile writes one state record atomically with root-only modes. The
// parent directory is 0700 and the file 0600: the engine user cannot replace
// or edit a record even though it owns the sibling run directory.
func (m *Manager) writeStateFile(path string, state nodeState) error {
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return fmt.Errorf("create state directory: %w", err)
	}
	if err := os.Chmod(dir, 0o700); err != nil {
		return fmt.Errorf("secure state directory: %w", err)
	}
	if os.Geteuid() == 0 {
		if err := os.Chown(dir, 0, 0); err != nil {
			return fmt.Errorf("own state directory: %w", err)
		}
	}
	data, err := json.MarshalIndent(state, "", "  ")
	if err != nil {
		return fmt.Errorf("encode node state: %w", err)
	}
	temporary, err := os.CreateTemp(dir, ".node-*.tmp")
	if err != nil {
		return fmt.Errorf("create temporary state file: %w", err)
	}
	temporaryPath := temporary.Name()
	defer func() {
		_ = os.Remove(temporaryPath)
	}()
	if _, err := temporary.Write(data); err != nil {
		_ = temporary.Close()
		return fmt.Errorf("write node state: %w", err)
	}
	if err := temporary.Chmod(0o600); err != nil {
		_ = temporary.Close()
		return fmt.Errorf("secure node state: %w", err)
	}
	if err := temporary.Close(); err != nil {
		return fmt.Errorf("close node state: %w", err)
	}
	if err := os.Rename(temporaryPath, path); err != nil {
		return fmt.Errorf("publish node state: %w", err)
	}
	if os.Geteuid() == 0 {
		if err := os.Chown(path, 0, 0); err != nil {
			return fmt.Errorf("own node state: %w", err)
		}
	}

	return nil
}

// stateFileTrusted rejects anything an engine user could have planted: a
// symlink, a file with group/other permissions, a file not owned by the agent
// identity, or a file in a group/world-writable directory.
func stateFileTrusted(path string) error {
	info, err := os.Lstat(path)
	if err != nil {
		return err
	}
	if !info.Mode().IsRegular() {
		return errors.New("state file is not a regular file")
	}
	if info.Mode().Perm() != 0o600 {
		return fmt.Errorf("state file mode %04o is not 0600", info.Mode().Perm())
	}
	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		return errors.New("cannot determine state file ownership")
	}
	if os.Geteuid() == 0 {
		if stat.Uid != 0 {
			return fmt.Errorf("state file is owned by uid %d, not root", stat.Uid)
		}
	} else if int(stat.Uid) != os.Geteuid() {
		return fmt.Errorf("state file is owned by uid %d, not the agent user", stat.Uid)
	}
	parent, err := os.Stat(filepath.Dir(path))
	if err != nil {
		return err
	}
	if !parent.IsDir() {
		return errors.New("state file parent is not a directory")
	}
	if parent.Mode().Perm()&0o077 != 0 {
		return fmt.Errorf("state directory mode %04o is not private", parent.Mode().Perm())
	}

	return nil
}

// validateStateRecord checks everything a record may make the agent do: the id
// and lab must match the path, the engine must be known, a non-docker process
// must carry a positive PID and an allowlisted executable, taps must be
// TeraLab links of the same lab, and the cgroup must be the node's own child
// of the TeraLab root. RunDir must be the deterministic path for the record.
func (m *Manager) validateStateRecord(path string, state nodeState) error {
	if state.ID == "" || !idPattern.MatchString(state.ID) {
		return errors.New("invalid node id in state record")
	}
	if state.LabID != "" && !idPattern.MatchString(state.LabID) {
		return errors.New("invalid lab id in state record")
	}
	expectedPath := m.statePathFor(state.ID, state.LabID)
	if filepath.Clean(path) != expectedPath {
		return fmt.Errorf("state path %s does not match record %s/%s", path, state.LabID, state.ID)
	}
	expectedRunDir := m.runDirFor(state.ID, state.LabID)
	if filepath.Clean(state.RunDir) != expectedRunDir {
		return fmt.Errorf("run directory %q does not match the record", state.RunDir)
	}
	if !knownEngine(state.Engine) {
		return fmt.Errorf("unknown engine %q in state record", state.Engine)
	}
	if state.Engine != "docker" {
		if state.PID <= 0 {
			return errors.New("state record has no positive pid")
		}
		if !m.allowedEngineExecutable(state.Engine, state.Exe) {
			return fmt.Errorf("state record executable %q is not an allowed %s binary", state.Exe, state.Engine)
		}
	}
	prefix := "tl" + network.LabTag(state.LabID)
	spec := Spec{ID: state.ID, LabID: state.LabID}
	for index, tap := range state.Taps {
		if !network.OwnsLinkName(tap) || !strings.HasPrefix(tap, prefix) {
			return fmt.Errorf("state record tap %q is not owned by lab %q", tap, state.LabID)
		}
		// Taps are created in interface order from the node identity, so a
		// record cannot borrow a sibling node's tap inside the same lab.
		if expected := spec.TapName(index); tap != expected {
			return fmt.Errorf("state record tap %q does not belong to node %q", tap, state.ID)
		}
	}
	if state.Cgroup != "" {
		root := m.cgroupRoot()
		if root == "" {
			return errors.New("state record has a cgroup but no TeraLab cgroup root is configured")
		}
		expected := m.cgroupNodePath(Spec{ID: state.ID, LabID: state.LabID})
		if filepath.Clean(state.Cgroup) != expected {
			return fmt.Errorf("cgroup %q does not match the record", state.Cgroup)
		}
		if !pathWithinRoot(m.cgroupRoot(), state.Cgroup) {
			return fmt.Errorf("cgroup %q is outside the TeraLab root", state.Cgroup)
		}
	}
	if m.cfg.PerLabUID {
		if state.Engine != "docker" {
			if state.UID == 0 || state.GID != state.UID || !m.labUIDInPool(state.UID) {
				return fmt.Errorf(
					"state record uid/gid %d/%d is not a per-lab identity",
					state.UID,
					state.GID,
				)
			}
			if err := m.stateUIDValid(state); err != nil {
				return err
			}
		}
	} else if state.Engine != "docker" && (state.UID != 0 || state.GID != 0) {
		// A per-lab engine record must not drive a kill after a rollback:
		// quarantine it so no stale uid can be trusted under the shared
		// identity. Docker records never drive a kill, so they keep working.
		return errors.New("state record carries a per-lab uid while per-lab uids are disabled")
	}

	return nil
}

// allowedEngineExecutable limits a record to the engine binary the agent
// itself would launch. IOL images are admin-uploaded executables under the
// configured image roots; every other engine must match its configured binary.
func (m *Manager) allowedEngineExecutable(engine, exe string) bool {
	exe = strings.TrimSpace(exe)
	if exe == "" {
		return false
	}
	resolved, err := filepath.EvalSymlinks(exe)
	if err != nil {
		// An engine binary replaced or removed after start still has a
		// canonical path in the record; compare it without following links so
		// the orphan sweep can reclaim it.
		resolved, err = filepath.Abs(exe)
		if err != nil {
			return false
		}
	}
	switch engine {
	case "qemu":
		return samePath(resolved, m.cfg.QEMUBinary)
	case "vpcs":
		return samePath(resolved, m.cfg.VPCSBinary)
	case "dynamips":
		return samePath(resolved, m.cfg.DynamipsBinary)
	case "iol":
		return m.underImageRoots(resolved)
	default:
		return false
	}
}

func samePath(first, second string) bool {
	if second == "" {
		return false
	}
	resolvedSecond, err := filepath.EvalSymlinks(second)
	if err != nil {
		resolvedSecond = second
	}

	return filepath.Clean(first) == filepath.Clean(resolvedSecond)
}

func (m *Manager) underImageRoots(path string) bool {
	for _, root := range m.cfg.ImageRoots() {
		if root == "" {
			continue
		}
		resolvedRoot, err := filepath.EvalSymlinks(root)
		if err != nil {
			continue
		}
		if pathWithinRoot(resolvedRoot, path) {
			return true
		}
	}

	return false
}

func knownEngine(engine string) bool {
	switch engine {
	case "qemu", "vpcs", "dynamips", "iol", "docker":
		return true
	default:
		return false
	}
}

func (m *Manager) cgroupRoot() string {
	if m.cfg.CgroupRoot == "" {
		return ""
	}
	if absolute, err := filepath.Abs(m.cfg.CgroupRoot); err == nil {
		return absolute
	}

	return filepath.Clean(m.cfg.CgroupRoot)
}

// removeStatePath removes one record and its now-empty lab directory, but only
// inside the trusted state root.
func (m *Manager) removeStatePath(path string) {
	if path == "" {
		return
	}
	root := m.stateRoot()
	if !pathWithinRoot(root, filepath.Clean(path)) {
		return
	}
	_ = os.Remove(path)
	parent := filepath.Dir(filepath.Clean(path))
	if parent != root {
		_ = os.Remove(parent)
	}
}

// processStartTicks reads field 22 (starttime) from /proc/<pid>/stat. It is
// used to reject a reused PID; 0 means "unavailable", never "matches".
func processStartTicks(pid int) uint64 {
	if pid <= 0 {
		return 0
	}
	data, err := os.ReadFile(filepath.Join("/proc", strconv.Itoa(pid), "stat"))
	if err != nil {
		return 0
	}
	closeIndex := strings.LastIndexByte(string(data), ')')
	if closeIndex < 0 {
		return 0
	}
	fields := strings.Fields(string(data[closeIndex+1:]))
	// fields[0] is state (field 3); starttime (field 22) is fields[19].
	if len(fields) <= 19 {
		return 0
	}
	value, err := strconv.ParseUint(fields[19], 10, 64)
	if err != nil {
		return 0
	}

	return value
}
