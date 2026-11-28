// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// errStateRegistryUnavailable marks a state-validation failure caused by the
// uid registry itself rather than by the record. Such a record may be valid, so
// it must be left untouched: never quarantined, signalled or deleted.
var errStateRegistryUnavailable = errors.New("per-lab uid registry unavailable")

// labIdentity is the credential a lab's engines run as. In per-lab mode Slot is
// the numeric pool slot (>= 0); the rollback path uses the shared VMUser and
// Slot is -1.
type labIdentity struct {
	Key    string
	Slot   int
	UID    uint32
	GID    uint32
	Groups []uint32
	PerLab bool
}

// apply installs the credential and process group on a prepared engine
// command. A zero uid means the rollback mode was configured without a VM user
// (AllowRootVM); the command is then left unprivileged-by-configuration, exactly
// as stage 1 did.
func (i labIdentity) apply(command *exec.Cmd) {
	attribute := &syscall.SysProcAttr{Setpgid: true}
	if i.UID > 0 || i.GID > 0 {
		attribute.Credential = &syscall.Credential{
			Uid:    i.UID,
			Gid:    i.GID,
			Groups: append([]uint32(nil), i.Groups...),
		}
	}
	command.SysProcAttr = attribute
}

// labUIDKey derives the registry key from the lab's run-directory component.
// A node without a lab gets its own slot so it never shares a uid with an
// unrelated lab-less node; real labs always use shortPathComponent(labID).
func labUIDKey(labID, nodeID string) string {
	if labID != "" {
		return shortPathComponent(labID)
	}

	return "node:" + shortPathComponent(nodeID)
}

// legacyIdentity is the stage-1 shared identity used when per-lab uids are
// disabled and by callers that only need a stable command credential (tests).
func (m *Manager) legacyIdentity() labIdentity {
	return labIdentity{
		Slot:   -1,
		UID:    m.cfg.VMUID,
		GID:    m.cfg.VMGID,
		Groups: append([]uint32(nil), m.cfg.VMGroups...),
	}
}

// labIdentityFor resolves the identity for a node start. In per-lab mode it
// allocates (or reuses) a slot under the root-owned registry; allocation
// failures wrap ErrEngineUnavailable and never fall back to the shared user.
func (m *Manager) labIdentityFor(spec Spec) (labIdentity, error) {
	if !m.cfg.PerLabUID {
		return m.legacyIdentity(), nil
	}
	if err := m.perLabUIDFailure(); err != nil {
		return labIdentity{}, err
	}
	if m.uidRegistry == nil {
		return labIdentity{}, fmt.Errorf("%w: per-lab uid registry is not configured", ErrEngineUnavailable)
	}
	key := labUIDKey(spec.LabID, spec.ID)
	entry, err := m.uidRegistry.allocate(key, spec.LabID, time.Now())
	if err != nil {
		return labIdentity{}, err
	}

	return labIdentity{
		Key:    key,
		Slot:   entry.Slot,
		UID:    entry.UID,
		GID:    entry.GID,
		Groups: m.supplementaryGroups(),
		PerLab: true,
	}, nil
}

// supplementaryGroups returns the deduplicated non-primary groups every engine
// needs: the shared image-store group (when configured) and the /dev/kvm group
// (when the device exists). Device ownership is read from the inode, so no
// named account or passwd entry is required.
func (m *Manager) supplementaryGroups() []uint32 {
	groups := make([]uint32, 0, 2)
	if m.cfg.ImageGID != 0 {
		groups = append(groups, m.cfg.ImageGID)
	}
	if gid, ok := deviceGID("/dev/kvm"); ok {
		groups = append(groups, gid)
	}

	return dedupeGIDs(groups)
}

func deviceGID(path string) (uint32, bool) {
	info, err := os.Stat(path)
	if err != nil {
		return 0, false
	}
	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		return 0, false
	}

	return stat.Gid, true
}

func dedupeGIDs(values []uint32) []uint32 {
	if len(values) == 0 {
		return nil
	}
	seen := make(map[uint32]bool, len(values))
	result := make([]uint32, 0, len(values))
	for _, value := range values {
		if seen[value] {
			continue
		}
		seen[value] = true
		result = append(result, value)
	}

	return result
}

// prepareLabUIDs validates the registry at startup. A corrupt registry does not
// stop the agent (stop/wipe/status must keep working) but every lab start then
// fails closed until an operator fixes or removes the file.
func (m *Manager) prepareLabUIDs() error {
	if !m.cfg.PerLabUID || m.uidRegistry == nil {
		return nil
	}
	err := m.uidRegistry.validate()

	m.uidFailureMu.Lock()
	m.uidFailure = err
	m.uidFailureMu.Unlock()

	return err
}

func (m *Manager) perLabUIDFailure() error {
	m.uidFailureMu.Lock()
	defer m.uidFailureMu.Unlock()
	if m.uidFailure == nil {
		return nil
	}

	return fmt.Errorf("%w: per-lab uid registry is invalid: %v", ErrEngineUnavailable, m.uidFailure)
}

// releaseLab returns the lab's slot after its run subtree is gone. It shares
// releaseLabSlot so a uid that a still-running process holds is never reused.
func (m *Manager) releaseLab(labID string) {
	if !m.cfg.PerLabUID || labID == "" || m.uidRegistry == nil {
		return
	}
	m.releaseLabSlot(labUIDKey(labID, ""))
}

// releaseLabComponent releases a slot by run-directory component, which is what
// the reconcile sweep knows when it prunes a stale lab directory.
func (m *Manager) releaseLabComponent(component string) {
	if !m.cfg.PerLabUID || component == "" || m.uidRegistry == nil {
		return
	}
	m.releaseLabSlot(component)
	// A lab-less node directory also lives directly below RunDir and uses a
	// node-prefixed key.
	m.releaseLabSlot("node:" + component)
}

// releaseLabSlot frees one registry key only when no live process still runs
// with its uid. Removing the run directory does not stop an engine: if the uid
// is still held, the slot is deliberately leaked (and a warning logged) rather
// than reused by a new lab.
func (m *Manager) releaseLabSlot(key string) {
	entry, ok, err := m.uidRegistry.lookup(key)
	if err != nil || !ok {
		// An unreadable registry cannot be changed safely and an unknown key
		// has nothing to release.
		return
	}
	if m.uidInUse(entry.UID) {
		if m.logger != nil {
			m.logger.Warn(
				"leaving per-lab uid slot allocated: uid is still in use",
				"key", key,
				"uid", entry.UID,
			)
		}

		return
	}
	_ = m.uidRegistry.release(key)
}

// uidInUse reports whether any live process holds uid. It is a method so tests
// can inject a probe.
func (m *Manager) uidInUse(uid uint32) bool {
	if m.uidProbe != nil {
		return m.uidProbe(uid)
	}

	return processHoldsUID(uid)
}

// labRunRoot is the lab's own subtree below RunDir. A lab-less node owns its
// node directory instead.
func (m *Manager) labRunRoot(spec Spec) string {
	if spec.LabID != "" {
		return filepath.Join(m.runRoot(), shortPathComponent(spec.LabID))
	}

	return m.runDirFor(spec.ID, "")
}

// privateTmpDir is the per-lab private tmp. Rollback mode keeps the stage-1
// environment (shared node run dir as HOME, no TMPDIR override).
func (m *Manager) privateTmpDir(spec Spec) string {
	if !m.cfg.PerLabUID {
		return ""
	}

	return filepath.Join(m.labRunRoot(spec), "tmp")
}

// engineHome is the engine HOME: the private tmp in per-lab mode, otherwise the
// node run directory as before.
func (m *Manager) engineHome(spec Spec, runDir string) string {
	if tmp := m.privateTmpDir(spec); tmp != "" {
		return tmp
	}

	return runDir
}

// engineEnvironment is the minimal engine environment plus the per-lab TMPDIR.
// The base list is engine-specific so rollback mode keeps exactly the stage-1
// environment; per-lab mode only switches HOME to the private tmp and adds
// TMPDIR.
func engineEnvironment(base []string, tmp string) []string {
	if tmp == "" {
		return base
	}

	return append(base, "TMPDIR="+tmp)
}

// tapOwner is the identity `ip tuntap add ... user` binds the tap to. The
// numeric uid form is accepted by iproute2 without a passwd entry; rollback
// mode keeps the configured account name.
func (m *Manager) tapOwner(identity labIdentity) string {
	if m.cfg.PerLabUID {
		return strconv.FormatUint(uint64(identity.UID), 10)
	}

	return m.cfg.VMUser
}

// createLabTap creates a tap owned by the lab identity and attaches it.
func (m *Manager) createLabTap(spec Spec, identity labIdentity, tap, bridge string) error {
	return m.net.CreateTapAs(tap, bridge, m.tapOwner(identity))
}

// chownAs applies the identity to one path. Errors are ignored because the
// agent may run unprivileged in tests; the startup path re-applies ownership
// and reports a root-owned failure.
func (m *Manager) chownAs(identity labIdentity, path string) {
	if identity.UID == 0 && identity.GID == 0 {
		return
	}
	_ = lchownFile(path, int(identity.UID), int(identity.GID))
}

// ownLabRunTree applies the ownership matrix to the lab's own run subtree only.
// Rollback mode keeps the stage-1 behaviour (lab and node directories); per-lab
// mode walks the whole subtree so files the engine created as root (logs,
// NETMAP, overlay before the first chown) are lab-owned before the engine runs.
func (m *Manager) ownLabRunTree(spec Spec, identity labIdentity) error {
	runRoot := m.runRoot()
	labRoot := filepath.Clean(m.labRunRoot(spec))
	if labRoot == runRoot || !pathWithinRoot(runRoot, labRoot) {
		return fmt.Errorf("refusing to own a path outside the lab run subtree: %s", labRoot)
	}

	if !identity.PerLab {
		nodeDir := filepath.Clean(m.runDirFor(spec.ID, spec.LabID))
		if parent := filepath.Dir(nodeDir); parent != runRoot {
			m.chownAs(identity, parent)
		}
		m.chownAs(identity, nodeDir)

		return nil
	}

	tmpDir := filepath.Clean(m.privateTmpDir(spec))
	return filepath.WalkDir(labRoot, func(path string, entry fs.DirEntry, walkErr error) error {
		if walkErr != nil {
			return walkErr
		}
		info, err := entry.Info()
		if err != nil {
			return err
		}
		if info.Mode()&os.ModeSymlink != 0 {
			// Never follow an engine-planted symlink out of the lab subtree.
			return nil
		}
		mode := os.FileMode(0o640)
		switch {
		case info.IsDir() && filepath.Clean(path) == tmpDir:
			mode = 0o700
		case info.IsDir():
			mode = 0o750
		case info.Mode()&os.ModeSocket != 0:
			mode = 0o660
		}
		if info.Mode().Perm() != mode {
			if err := chmodFile(path, mode); err != nil {
				return fmt.Errorf("set mode on %s: %w", path, err)
			}
		}
		if stat, ok := info.Sys().(*syscall.Stat_t); ok {
			if stat.Uid != identity.UID || stat.Gid != identity.GID {
				if err := lchownFile(path, int(identity.UID), int(identity.GID)); err != nil {
					if os.Geteuid() == 0 {
						return fmt.Errorf("own %s: %w", path, err)
					}
					// An unprivileged caller (tests, dev) cannot apply a numeric
					// identity; production runs the agent as root.
				}
			}
		}

		return nil
	})
}

// lchownFile and chmodFile are replaceable in tests so ownership containment
// can be asserted without root. Ownership uses Lchown on purpose: the walk
// skips symlinks, but a lab uid can still swap an entry for a link between the
// Info and the Chown. Lchown never follows the final component, so such a race
// can only change the link itself, not a target outside the run tree. The mode
// change remains path-based (see the "Residual risks" section in README.md).
var (
	lchownFile = os.Lchown
	chmodFile  = os.Chmod
)

// releaseNodeRunRoot releases the identity of a wiped node. A lab keeps its
// slot while any node directory remains; a lab-less node releases its own slot
// as soon as its directory is gone.
func (m *Manager) releaseNodeRunRoot(spec Spec) {
	if spec.LabID != "" {
		m.dropEmptyLabRunDir(spec.LabID)

		return
	}
	if m.cfg.PerLabUID && m.uidRegistry != nil {
		m.releaseLabSlot(labUIDKey("", spec.ID))
	}
}

// labDirIsEmptyExceptTmp reports whether the lab run directory holds no node
// directory any more. The private tmp is the only entry the agent keeps.
func labDirIsEmptyExceptTmp(dir string) bool {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return false
	}
	for _, entry := range entries {
		if entry.Name() != "tmp" {
			return false
		}
	}

	return true
}

// dropEmptyLabRunDir removes the lab subtree once every node is gone and
// releases its uid slot. It only runs when the directory is below RunDir.
func (m *Manager) dropEmptyLabRunDir(labID string) {
	if labID == "" {
		return
	}
	runRoot := m.runRoot()
	labDir := filepath.Join(runRoot, shortPathComponent(labID))
	if !pathWithinRoot(runRoot, labDir) || labDir == runRoot {
		return
	}
	if !labDirIsEmptyExceptTmp(labDir) {
		return
	}
	_ = os.RemoveAll(filepath.Join(labDir, "tmp"))
	if err := os.Remove(labDir); err != nil {
		return
	}
	m.releaseLab(labID)
}

// labUIDInPool reports whether uid belongs to the configured pool.
func (m *Manager) labUIDInPool(uid uint32) bool {
	if m.cfg.LabUIDSlots < 1 {
		return false
	}
	last := uint64(m.cfg.LabUIDFirst) + uint64(m.cfg.LabUIDSlots) - 1

	return uint64(uid) >= uint64(m.cfg.LabUIDFirst) && uint64(uid) <= last
}

// stateUIDValid validates a non-docker state record against the registry. It
// fails closed when the registry is corrupt or has no allocation for the lab.
func (m *Manager) stateUIDValid(state nodeState) error {
	key := labUIDKey(state.LabID, state.ID)
	entry, ok, err := m.uidRegistry.lookup(key)
	if err != nil {
		// A corrupt or unreadable registry says nothing about this record.
		// Tag it so reconciliation leaves the record untouched instead of
		// quarantining otherwise-valid state.
		return fmt.Errorf("%w: %v", errStateRegistryUnavailable, err)
	}
	if !ok {
		return fmt.Errorf("no per-lab uid allocation for %q", key)
	}
	if entry.LabID != state.LabID {
		return fmt.Errorf(
			"per-lab uid allocation for %q belongs to lab %q, not %q",
			key,
			entry.LabID,
			state.LabID,
		)
	}
	if entry.UID != state.UID || entry.GID != state.GID {
		return fmt.Errorf(
			"state record uid/gid %d/%d does not match the allocation %d/%d",
			state.UID,
			state.GID,
			entry.UID,
			entry.GID,
		)
	}

	return nil
}

// processUID reads the real uid from /proc/<pid>/status. The second result is
// false when the process is gone or the field is unavailable.
func processUID(pid int) (uint32, bool) {
	if pid <= 0 {
		return 0, false
	}
	data, err := os.ReadFile(filepath.Join("/proc", strconv.Itoa(pid), "status"))
	if err != nil {
		return 0, false
	}
	for _, line := range strings.Split(string(data), "\n") {
		if !strings.HasPrefix(line, "Uid:") {
			continue
		}
		fields := strings.Fields(line)
		if len(fields) < 2 {
			return 0, false
		}
		uid, err := strconv.ParseUint(fields[1], 10, 32)
		if err != nil {
			return 0, false
		}

		return uint32(uid), true
	}

	return 0, false
}

// processHoldsUID reports whether any live process runs with the real uid. It
// fails closed: when /proc cannot be read the uid is assumed to be in use, so a
// uid slot is never reused while its owner may still be running.
func processHoldsUID(uid uint32) bool {
	entries, err := os.ReadDir("/proc")
	if err != nil {
		return true
	}
	for _, entry := range entries {
		pid, err := strconv.Atoi(entry.Name())
		if err != nil || pid <= 0 {
			continue
		}
		if actual, ok := processUID(pid); ok && actual == uid {
			return true
		}
	}

	return false
}

// processMatchesState verifies the executable, start time and (in per-lab mode)
// the real uid of a recorded engine. A record that fails any check is never
// signalled.
func (m *Manager) processMatchesState(state nodeState) bool {
	if !processMatches(state.PID, state.Exe, state.StartTicks) {
		return false
	}
	if !m.cfg.PerLabUID || state.Engine == "docker" {
		return true
	}
	if state.UID == 0 {
		return false
	}
	actual, ok := processUID(state.PID)

	return ok && actual == state.UID
}

// cgroupLabDir is the per-lab cgroup parent under the configured root. The lab
// component makes a cgroup attributable to exactly one lab; lab-less nodes keep
// their cgroup directly under the root for backwards compatibility.
func (m *Manager) cgroupLabDir(labID string) string {
	root := m.cgroupRoot()
	if root == "" || labID == "" {
		return root
	}

	return filepath.Join(root, "lab-"+shortPathComponent(labID))
}

// cgroupNodePath is the node cgroup path for a spec.
func (m *Manager) cgroupNodePath(spec Spec) string {
	return filepath.Join(m.cgroupLabDir(spec.LabID), "node-"+spec.ID)
}
