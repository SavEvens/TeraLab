// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
)

type nodeState struct {
	ID      string   `json:"id"`
	LabID   string   `json:"lab_id,omitempty"`
	Engine  string   `json:"engine"`
	Exe     string   `json:"exe"`
	PID     int      `json:"pid"`
	RunDir  string   `json:"run_dir"`
	Taps    []string `json:"taps,omitempty"`
	Cgroup  string   `json:"cgroup,omitempty"`
	Started string   `json:"started,omitempty"`
	// StartTicks is the kernel start time of PID (field 22 of /proc/pid/stat).
	// It rejects a reused PID even when the executable happens to match.
	StartTicks uint64 `json:"start_ticks,omitempty"`
	// UID/GID are the per-lab numeric identity the engine ran as. They are
	// recorded so the sweep can reject a process whose real uid does not match
	// (and so a rollback-mode agent quarantines a per-lab record instead of
	// trusting it). Zero in rollback mode.
	UID uint32 `json:"uid,omitempty"`
	GID uint32 `json:"gid,omitempty"`
	// Container fields let a restarted agent re-register a docker node
	// without a live engine process. The full spec keeps stop/status/wipe
	// working even when the agent no longer holds an exec.Cmd.
	Image       string         `json:"image,omitempty"`
	ContainerID string         `json:"container_id,omitempty"`
	Docker      *DockerOptions `json:"docker,omitempty"`
	Spec        *Spec          `json:"spec,omitempty"`
}

// stateFileRecord is one validated node record. Reconcile reads every record
// once so container reconciliation and the generic orphan sweep see the same
// data.
type stateFileRecord struct {
	path  string
	state nodeState
}

// ReconcileResult reports resources reclaimed after a crash or restart.
type ReconcileResult struct {
	KilledProcesses   []int    `json:"killed_processes,omitempty"`
	RemovedLinks      []string `json:"removed_links,omitempty"`
	RemovedDirs       []string `json:"removed_dirs,omitempty"`
	RemovedCgroups    []string `json:"removed_cgroups,omitempty"`
	RemovedContainers []string `json:"removed_containers,omitempty"`
	// QuarantinedState lists state files that failed validation and the legacy
	// run-directory node.json files that were discarded.
	QuarantinedState []string `json:"quarantined_state,omitempty"`
}

// Reconcile removes state left by a previous agent process: orphan engine
// processes, stale taps and bridges, cgroups, run directories and trusted
// state records.
//
// Only StateDir is trusted. A node.json under the VM-writable RunDir is a
// legacy artefact and is discarded without being read.
//
// keepLabIDs is the keep-set of labs that still exist: every directory directly
// below the run root whose run-directory component (see shortPathComponent) is
// not in the keep-set and does not hold a live node is removed, and every
// removed path is reported in RemovedDirs.
//
// A nil keep-set disables lab-directory pruning; only the node-level cleanup
// runs. Startup reconciliation passes nil because the agent does not know which
// labs exist until the API tells it.
func (m *Manager) Reconcile(keepLabIDs []string) (ReconcileResult, error) {
	result := ReconcileResult{}
	var firstErr error
	note := func(err error) {
		if err != nil && firstErr == nil {
			firstErr = err
		}
	}

	liveNodes := map[string]bool{}
	// liveDirs holds the run-directory components that must survive the lab
	// sweep: the lab of every running node, or the node's own directory when it
	// has no lab.
	liveDirs := map[string]bool{}
	m.mu.Lock()
	for _, node := range m.nodes {
		// Only running nodes own live resources. Stop() leaves a node in the
		// map with State "stopped" after it has already reaped the process,
		// taps, cgroup and state file, so a stopped node must neither keep its
		// lab directory alive nor mask a stale state file from the cleanup
		// below.
		if node.State != "running" {
			continue
		}
		liveNodes[node.Spec.ID] = true
		if node.Spec.LabID != "" {
			liveDirs[shortPathComponent(node.Spec.LabID)] = true
		} else {
			liveDirs[shortPathComponent(node.Spec.ID)] = true
		}
	}
	m.mu.Unlock()
	for _, labID := range keepLabIDs {
		if labID != "" {
			liveDirs[shortPathComponent(labID)] = true
		}
	}

	runRoot := m.runRoot()
	stateRoot := m.stateRoot()
	// Legacy node.json files in the VM-writable run tree are never parsed.
	m.quarantineLegacyRunState(runRoot, stateRoot, &result)

	records, rejected, stateErr := m.readStateRecords(stateRoot)
	result.QuarantinedState = append(result.QuarantinedState, rejected...)
	note(stateErr)

	// Docker containers are accounted for before the generic state-file sweep:
	// labelled containers with a matching state record are re-registered,
	// orphan containers are removed, and unlabelled containers are never
	// listed.
	docker := m.reconcileDockerContainers(keepLabIDs, records)
	if docker.err != nil {
		note(docker.err)
	}
	result.RemovedContainers = append(result.RemovedContainers, docker.removed...)
	result.RemovedDirs = append(result.RemovedDirs, docker.removedDirs...)
	result.RemovedLinks = append(result.RemovedLinks, docker.removedLinks...)
	for nodeID, labID := range docker.registered {
		liveNodes[nodeID] = true
		if labID != "" {
			liveDirs[shortPathComponent(labID)] = true
		} else {
			liveDirs[shortPathComponent(nodeID)] = true
		}
	}

	for _, record := range records {
		statePath := record.path
		state := record.state
		if docker.handled[statePath] {
			continue
		}
		if state.Engine == "docker" && !docker.listed {
			// Docker could not be queried: never kill a container init PID and
			// never discard state that a later reconcile still needs.
			continue
		}
		if state.ID != "" && liveNodes[state.ID] {
			continue
		}

		// A docker state PID is the container init process, not an engine the
		// agent spawned; it must never be signalled here. For every other
		// engine the record passed path, executable, PID and start-time
		// validation before it reached this loop.
		// A recorded cgroup must match the live process: a reused PID or a
		// process that escaped the node cgroup is never signalled. Without
		// cgroups the verified PID is still reaped. In per-lab mode the real
		// uid must also match the recorded lab uid; a mismatch is untrusted
		// and no signal is sent.
		cgroupMatches := state.Cgroup == "" || m.teraLabOwnedProcess(state.PID, state.Cgroup)
		if state.Engine != "docker" && state.PID > 0 && cgroupMatches &&
			m.processMatchesState(state) {
			group := processGroupLeader(state.PID) && m.teraLabOwnedProcess(state.PID, state.Cgroup)
			err := signalOrphan(state.PID, group)
			if err != nil && !errors.Is(err, syscall.ESRCH) {
				note(fmt.Errorf("kill orphan %d: %w", state.PID, err))
			} else {
				result.KilledProcesses = append(result.KilledProcesses, state.PID)
			}
			waitForExit(state.PID, 2*time.Second)
		}

		for _, tap := range state.Taps {
			if err := m.net.DeleteLink(tap); err != nil {
				note(err)
			} else {
				result.RemovedLinks = append(result.RemovedLinks, tap)
			}
		}
		if state.Cgroup != "" {
			m.removeCgroup(state.Cgroup)
			result.RemovedCgroups = append(result.RemovedCgroups, state.Cgroup)
		}

		if pathWithinRoot(runRoot, filepath.Clean(state.RunDir)) {
			if err := os.RemoveAll(state.RunDir); err != nil {
				note(err)
			} else {
				result.RemovedDirs = append(result.RemovedDirs, state.RunDir)
				if parent := filepath.Dir(state.RunDir); parent != runRoot && parent != stateRoot {
					_ = os.Remove(parent)
				}
			}
		}
		m.removeStatePath(statePath)
	}

	// A nil keep-set means the caller does not know the labs yet; leave lab
	// directories alone in that case.
	if keepLabIDs != nil {
		entries, readErr := os.ReadDir(runRoot)
		if readErr != nil && !errors.Is(readErr, os.ErrNotExist) {
			note(readErr)
		}
		for _, entry := range entries {
			if !entry.IsDir() || liveDirs[entry.Name()] {
				continue
			}
			path := filepath.Join(runRoot, entry.Name())
			if path == stateRoot {
				continue
			}
			if removeErr := os.RemoveAll(path); removeErr != nil {
				note(fmt.Errorf("remove stale lab directory %s: %w", path, removeErr))
				continue
			}
			result.RemovedDirs = append(result.RemovedDirs, path)
			// The run tree is gone, so its uid slot can be reused. The key is
			// the run-directory component; releasing an unknown key is a no-op.
			m.releaseLabComponent(entry.Name())
		}
	}

	// Recompute the keep-set after re-registration so the host end of a
	// container veth is never swept as a stale link.
	keep := m.liveLinks("")
	stale, cleanupErr := m.net.CleanupStale(keep)
	if cleanupErr != nil {
		note(cleanupErr)
	}
	result.RemovedLinks = append(result.RemovedLinks, stale...)

	// Recompute the keep-set of live cgroups. The lab component in each path
	// means a stale cgroup is attributed to exactly one lab, and a node id
	// reused in another lab cannot keep this one alive.
	liveCgroups := map[string]bool{}
	m.mu.Lock()
	for _, node := range m.nodes {
		if node.State == "running" && node.CgroupPath != "" {
			liveCgroups[filepath.Clean(node.CgroupPath)] = true
		}
	}
	m.mu.Unlock()

	if !m.cfg.DisableCgroups {
		sweepNodeCgroup := func(path string) bool {
			if liveCgroups[filepath.Clean(path)] {
				return false
			}
			m.removeCgroup(path)
			result.RemovedCgroups = append(result.RemovedCgroups, path)

			return true
		}
		if entries, err := os.ReadDir(m.cfg.CgroupRoot); err == nil {
			for _, entry := range entries {
				if !entry.IsDir() {
					continue
				}
				path := filepath.Join(m.cfg.CgroupRoot, entry.Name())
				if strings.HasPrefix(entry.Name(), "lab-") {
					children, readErr := os.ReadDir(path)
					if readErr != nil {
						continue
					}
					for _, child := range children {
						if !child.IsDir() || !strings.HasPrefix(child.Name(), "node-") {
							continue
						}
						sweepNodeCgroup(filepath.Join(path, child.Name()))
					}
					continue
				}
				if strings.HasPrefix(entry.Name(), "node-") {
					// Legacy layout from before the lab component.
					sweepNodeCgroup(path)
				}
			}
		}
	}

	if len(result.KilledProcesses) > 0 || len(result.RemovedLinks) > 0 || len(result.RemovedDirs) > 0 ||
		len(result.RemovedContainers) > 0 || len(result.QuarantinedState) > 0 {
		m.logger.Warn("reconciled stale node resources",
			"killed", result.KilledProcesses,
			"links", result.RemovedLinks,
			"dirs", len(result.RemovedDirs),
			"containers", result.RemovedContainers,
			"quarantined", len(result.QuarantinedState),
		)
	}

	return result, firstErr
}

// readStateRecords walks the trusted state root, validates every record and
// quarantines the rest. Invalid records are renamed out of the .json namespace
// so they are never considered again, and they can never make the agent act.
//
// A record whose validation failed because the uid registry is unavailable or
// corrupt is not invalid: it is left in place, omitted from the record set so
// nothing is signalled or deleted, and reported through the returned error.
func (m *Manager) readStateRecords(stateRoot string) ([]stateFileRecord, []string, error) {
	var records []stateFileRecord
	var rejected []string
	var registryErr error
	err := filepath.WalkDir(stateRoot, func(path string, entry fs.DirEntry, err error) error {
		if err != nil {
			if errors.Is(err, os.ErrNotExist) {
				return nil
			}
			return err
		}
		if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".json") {
			return nil
		}
		// The uid registry lives in StateDir but is not a node record. It must
		// survive every reconcile, including rollback mode. Compare the exact
		// root path so a node named "uidmap" in a lab is still reconciled.
		if filepath.Clean(path) == filepath.Join(stateRoot, uidMapFileName) {
			return nil
		}
		if trustErr := stateFileTrusted(path); trustErr != nil {
			rejected = append(rejected, path)
			m.quarantineStateFile(path, trustErr)

			return nil
		}
		data, readErr := os.ReadFile(path)
		if readErr != nil {
			rejected = append(rejected, path)
			m.quarantineStateFile(path, readErr)

			return nil
		}
		state := nodeState{}
		if err := json.Unmarshal(data, &state); err != nil {
			rejected = append(rejected, path)
			m.quarantineStateFile(path, err)

			return nil
		}
		if validateErr := m.validateStateRecord(path, state); validateErr != nil {
			if errors.Is(validateErr, errStateRegistryUnavailable) {
				// The record may be valid; the agent simply cannot check it.
				// Leave it in place and out of the record set so the sweep
				// neither signals nor deletes anything, then report it.
				if m.logger != nil {
					m.logger.Error(
						"cannot validate state record; leaving it untouched",
						"path", path,
						"error", validateErr,
					)
				}
				if registryErr == nil {
					registryErr = validateErr
				}

				return nil
			}
			rejected = append(rejected, path)
			m.quarantineStateFile(path, validateErr)

			return nil
		}
		records = append(records, stateFileRecord{path: path, state: state})

		return nil
	})
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		if m.logger != nil {
			m.logger.Warn("state directory walk incomplete", "error", err)
		}
	}

	return records, rejected, registryErr
}

func (m *Manager) quarantineStateFile(path string, reason error) {
	quarantine := path + ".rejected"
	_ = os.Remove(quarantine)
	if err := os.Rename(path, quarantine); err != nil {
		if m.logger != nil {
			m.logger.Warn("cannot quarantine rejected state file", "path", path, "reason", reason, "error", err)
		}

		return
	}
	if m.logger != nil {
		m.logger.Warn("quarantined rejected state file", "path", path, "reason", reason)
	}
}

// quarantineLegacyRunState removes node.json files from the run tree. They were
// writable by the engine user and are never trusted after the upgrade; running
// labs must be stopped and restarted across the upgrade.
func (m *Manager) quarantineLegacyRunState(runRoot, stateRoot string, result *ReconcileResult) {
	_ = filepath.WalkDir(runRoot, func(path string, entry fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if entry.IsDir() {
			if path == stateRoot {
				return fs.SkipDir
			}
			return nil
		}
		if entry.Name() != stateFileName {
			return nil
		}
		if os.Remove(path) == nil {
			result.QuarantinedState = append(result.QuarantinedState, path)
		}

		return nil
	})
}

// processMatches verifies that pid is still the process recorded in trusted
// state. An empty executable, a non-positive pid, a mismatched executable or a
// different kernel start time all reject the record.
func processMatches(pid int, expectedExe string, expectedStartTicks uint64) bool {
	if pid <= 0 {
		return false
	}
	expectedExe = strings.TrimSpace(expectedExe)
	if expectedExe == "" {
		return false
	}
	actual, err := filepath.EvalSymlinks(filepath.Join("/proc", strconv.Itoa(pid), "exe"))
	if err != nil {
		// A deleted executable cannot be resolved; fall back to the link
		// target so a stopped engine can still be reaped.
		link, linkErr := os.Readlink(filepath.Join("/proc", strconv.Itoa(pid), "exe"))
		if linkErr != nil {
			return false
		}
		link = strings.TrimSuffix(link, " (deleted)")
		actual = filepath.Clean(link)
	}
	expected, err := filepath.EvalSymlinks(expectedExe)
	if err != nil {
		// The configured executable may have been replaced or removed; fall
		// back to its absolute path so a stale orphan is still reaped.
		expected, err = filepath.Abs(expectedExe)
		if err != nil {
			return false
		}
	}
	if filepath.Clean(actual) != filepath.Clean(expected) {
		return false
	}
	if expectedStartTicks != 0 {
		if actualTicks := processStartTicks(pid); actualTicks == 0 || actualTicks != expectedStartTicks {
			return false
		}
	}

	return true
}

// teraLabOwnedProcess reports whether pid currently lives in the node cgroup
// recorded in trusted state. Without that evidence the sweep signals only the
// verified PID, never its process group.
func (m *Manager) teraLabOwnedProcess(pid int, cgroup string) bool {
	cgroup = filepath.Clean(cgroup)
	if pid <= 0 || cgroup == "" || m.cgroupRoot() == "" {
		return false
	}
	data, err := os.ReadFile(filepath.Join("/proc", strconv.Itoa(pid), "cgroup"))
	if err != nil {
		return false
	}
	want := "/" + strings.TrimPrefix(cgroup, "/sys/fs/cgroup/")
	for _, line := range strings.Split(strings.TrimSpace(string(data)), "\n") {
		fields := strings.SplitN(line, ":", 3)
		if len(fields) != 3 {
			continue
		}
		if fields[2] == want || strings.HasSuffix(fields[2], want) {
			return true
		}
	}

	return false
}

func processGroupLeader(pid int) bool {
	if pid <= 0 {
		return false
	}
	group, err := syscall.Getpgid(pid)

	return err == nil && group == pid
}

// signalOrphan kills the process group only when it is known to be TeraLab's;
// otherwise it signals the single verified process.
func signalOrphan(pid int, group bool) error {
	if group {
		if err := syscall.Kill(-pid, syscall.SIGKILL); err == nil || errors.Is(err, syscall.ESRCH) {
			return err
		}
	}

	return syscall.Kill(pid, syscall.SIGKILL)
}

func waitForExit(pid int, timeout time.Duration) {
	deadline := time.Now().Add(timeout)
	for {
		if err := syscall.Kill(pid, 0); errors.Is(err, syscall.ESRCH) {
			return
		}
		if time.Now().After(deadline) {
			return
		}
		time.Sleep(50 * time.Millisecond)
	}
}
