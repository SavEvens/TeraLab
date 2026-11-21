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
	Taps    []string `json:"taps,omitempty"`
	Cgroup  string   `json:"cgroup,omitempty"`
	Started string   `json:"started,omitempty"`
}

// ReconcileResult reports resources reclaimed after a crash or restart.
type ReconcileResult struct {
	KilledProcesses []int    `json:"killed_processes,omitempty"`
	RemovedLinks    []string `json:"removed_links,omitempty"`
	RemovedDirs     []string `json:"removed_dirs,omitempty"`
	RemovedCgroups  []string `json:"removed_cgroups,omitempty"`
}

// Reconcile removes state left by a previous agent process: orphan engine
// processes, stale taps and bridges, cgroups, and run directories.
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

	keep := m.liveLinks("")
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

	runRoot, err := filepath.Abs(m.cfg.RunDir)
	if err != nil {
		return result, err
	}
	stateFiles := []string{}
	walkErr := filepath.WalkDir(runRoot, func(path string, entry fs.DirEntry, err error) error {
		if err != nil {
			if errors.Is(err, os.ErrNotExist) {
				return nil
			}
			return err
		}
		if entry.IsDir() {
			return nil
		}
		if entry.Name() == stateFileName {
			stateFiles = append(stateFiles, path)
		}
		return nil
	})
	if walkErr != nil && !errors.Is(walkErr, os.ErrNotExist) {
		note(walkErr)
	}

	for _, statePath := range stateFiles {
		state := nodeState{}
		data, readErr := os.ReadFile(statePath)
		if readErr != nil {
			note(readErr)
			continue
		}
		if err := json.Unmarshal(data, &state); err != nil {
			note(err)
			continue
		}
		if state.ID != "" && liveNodes[state.ID] {
			continue
		}

		if state.PID > 0 && processMatches(state.PID, state.Exe) {
			err := syscall.Kill(-state.PID, syscall.SIGKILL)
			if err != nil {
				// The orphan may not be a process-group leader; fall back to
				// the process itself.
				err = syscall.Kill(state.PID, syscall.SIGKILL)
			}
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

		dir := filepath.Dir(statePath)
		if err := os.RemoveAll(dir); err != nil {
			note(err)
		} else {
			result.RemovedDirs = append(result.RemovedDirs, dir)
			_ = os.Remove(filepath.Dir(dir))
		}
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
			if dirHasLiveNode(path, liveNodes) {
				continue
			}
			if removeErr := os.RemoveAll(path); removeErr != nil {
				note(fmt.Errorf("remove stale lab directory %s: %w", path, removeErr))
				continue
			}
			result.RemovedDirs = append(result.RemovedDirs, path)
		}
	}

	stale, cleanupErr := m.net.CleanupStale(keep)
	if cleanupErr != nil {
		note(cleanupErr)
	}
	result.RemovedLinks = append(result.RemovedLinks, stale...)

	if !m.cfg.DisableCgroups {
		if entries, err := os.ReadDir(m.cfg.CgroupRoot); err == nil {
			for _, entry := range entries {
				if !entry.IsDir() || !strings.HasPrefix(entry.Name(), "node-") {
					continue
				}
				nodeID := strings.TrimPrefix(entry.Name(), "node-")
				if liveNodes[nodeID] {
					continue
				}
				path := filepath.Join(m.cfg.CgroupRoot, entry.Name())
				m.removeCgroup(path)
				result.RemovedCgroups = append(result.RemovedCgroups, path)
			}
		}
	}

	if len(result.KilledProcesses) > 0 || len(result.RemovedLinks) > 0 || len(result.RemovedDirs) > 0 {
		m.logger.Warn("reconciled stale node resources",
			"killed", result.KilledProcesses,
			"links", result.RemovedLinks,
			"dirs", len(result.RemovedDirs),
		)
	}

	return result, firstErr
}

// dirHasLiveNode reports whether a run directory still holds state for a node
// the manager tracks. It is a safety net for state files whose lab id no longer
// matches the lab directory they live in.
func dirHasLiveNode(dir string, liveNodes map[string]bool) bool {
	found := false
	_ = filepath.WalkDir(dir, func(path string, entry fs.DirEntry, err error) error {
		if err != nil || entry.IsDir() || entry.Name() != stateFileName {
			return nil
		}
		data, readErr := os.ReadFile(path)
		if readErr != nil {
			return nil
		}
		state := nodeState{}
		if json.Unmarshal(data, &state) != nil {
			return nil
		}
		if state.ID != "" && liveNodes[state.ID] {
			found = true

			return fs.SkipAll
		}

		return nil
	})

	return found
}

func processMatches(pid int, expectedExe string) bool {
	actual, err := os.Readlink(filepath.Join("/proc", strconv.Itoa(pid), "exe"))
	if err != nil {
		return false
	}
	actual = strings.TrimSuffix(actual, " (deleted)")
	if expectedExe == "" {
		return filepath.Base(actual) != ""
	}
	expected, err := filepath.EvalSymlinks(expectedExe)
	if err != nil {
		expected = expectedExe
	}

	return actual == expected
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
