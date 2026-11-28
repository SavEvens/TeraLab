// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sync"
	"syscall"
	"time"
)

// The per-lab uid registry maps a lab's run-directory component to a numeric
// slot. It is root-owned state, written only by the agent, and never derived
// from a lab-writable path. Named accounts (teralab-vm-NNN) are a packaging
// refinement; the agent only needs the numeric uid/gid.
const (
	uidMapVersion  = 1
	uidMapFileName = "uidmap.json"
	uidMapLockName = "uidmap.lock"
	// uidMapMinFirst mirrors config's reserved range. A registry below it is
	// rejected so a corrupted or hand-edited file cannot hand out a system
	// account's id.
	uidMapMinFirst = 200000
	// uidMapLimit is the exclusive upper bound of a valid Linux uid.
	uidMapLimit = uint64(1) << 31
)

// uidMapEntry is one lab allocation. UID and GID are equal because every slot
// is its own primary group; keeping both fields makes the record explicit.
type uidMapEntry struct {
	LabID    string `json:"lab_id"`
	Slot     int    `json:"slot"`
	UID      uint32 `json:"uid"`
	GID      uint32 `json:"gid"`
	Created  string `json:"created"`
	LastUsed string `json:"last_used"`
}

// uidMapFile is the versioned on-disk registry. Labs is keyed by the lab's
// run-directory component (shortPathComponent(labID)); a lab-less node uses a
// node-prefixed key so it cannot collide with a lab component.
type uidMapFile struct {
	Version int                    `json:"version"`
	Labs    map[string]uidMapEntry `json:"labs"`
}

// uidAllocator owns StateDir/uidmap.json. Every mutation takes an exclusive
// flock on a sibling lock file, then writes the registry atomically. The
// in-process mutex keeps concurrent starts from interleaving before flock.
type uidAllocator struct {
	dir   string
	first uint32
	slots int
	mu    sync.Mutex
}

func newUIDAllocator(stateDir string, first uint32, slots int) *uidAllocator {
	return &uidAllocator{dir: stateDir, first: first, slots: slots}
}

func (a *uidAllocator) path() string {
	return filepath.Join(a.dir, uidMapFileName)
}

func (a *uidAllocator) lockPath() string {
	return filepath.Join(a.dir, uidMapLockName)
}

// validatePool rejects a pool that cannot be represented. It mirrors the
// configuration check and is repeated here so a Manager built programmatically
// (tests, embedders) cannot allocate an unsafe uid.
func (a *uidAllocator) validatePool() error {
	if a == nil {
		return errors.New("per-lab uid registry is not configured")
	}
	if a.slots < 1 {
		return errors.New("per-lab uid slot count must be at least 1")
	}
	if a.first < uidMapMinFirst {
		return fmt.Errorf("per-lab uid range must start at or above %d", uidMapMinFirst)
	}
	last := uint64(a.first) + uint64(a.slots) - 1
	if last >= uidMapLimit {
		return fmt.Errorf("per-lab uid range %d..%d exceeds the Linux uid limit", a.first, last)
	}

	return nil
}

// allocate returns the lab's stable slot, creating one under the registry lock
// when the lab has none. Exhaustion is a hard error: the caller must never fall
// back to the shared VM user.
func (a *uidAllocator) allocate(key, labID string, now time.Time) (uidMapEntry, error) {
	if err := a.validatePool(); err != nil {
		return uidMapEntry{}, err
	}
	if key == "" {
		return uidMapEntry{}, fmt.Errorf("%w: lab key is empty", ErrEngineUnavailable)
	}

	var result uidMapEntry
	err := a.withLock(func(registry *uidMapFile) (bool, error) {
		if entry, ok := registry.Labs[key]; ok {
			if entry.LabID != labID {
				return false, fmt.Errorf(
					"%w: uid registry entry %q belongs to lab %q, not %q",
					ErrEngineUnavailable,
					key,
					entry.LabID,
					labID,
				)
			}
			if err := a.validateEntry(entry); err != nil {
				return false, err
			}
			result = entry
			entry.LastUsed = now.UTC().Format(time.RFC3339)
			registry.Labs[key] = entry

			return true, nil
		}
		used := make(map[int]bool, len(registry.Labs))
		for _, entry := range registry.Labs {
			used[entry.Slot] = true
		}
		for slot := 0; slot < a.slots; slot++ {
			if used[slot] {
				continue
			}
			entry := uidMapEntry{
				LabID:    labID,
				Slot:     slot,
				UID:      a.first + uint32(slot),
				GID:      a.first + uint32(slot),
				Created:  now.UTC().Format(time.RFC3339),
				LastUsed: now.UTC().Format(time.RFC3339),
			}
			registry.Labs[key] = entry
			result = entry

			return true, nil
		}

		return false, fmt.Errorf(
			"%w: per-lab uid pool exhausted (%d slots in %d..%d); wipe or reconcile labs to free a slot",
			ErrEngineUnavailable,
			a.slots,
			a.first,
			uint64(a.first)+uint64(a.slots)-1,
		)
	})

	return result, err
}

// lookup reads the registry without allocating. It fails closed when the file
// is corrupt; a missing file means no lab has been allocated yet.
func (a *uidAllocator) lookup(key string) (uidMapEntry, bool, error) {
	if err := a.validatePool(); err != nil {
		return uidMapEntry{}, false, err
	}
	var entry uidMapEntry
	found := false
	err := a.withLock(func(registry *uidMapFile) (bool, error) {
		value, ok := registry.Labs[key]
		if !ok {
			return false, nil
		}
		if err := a.validateEntry(value); err != nil {
			return false, err
		}
		entry = value
		found = true

		return false, nil
	})

	return entry, found, err
}

// release returns one slot to the free list. Releasing an unknown key is a
// no-op so wipe and reconcile are both idempotent.
func (a *uidAllocator) release(key string) error {
	if err := a.validatePool(); err != nil {
		return err
	}
	if key == "" {
		return nil
	}

	return a.withLock(func(registry *uidMapFile) (bool, error) {
		if _, ok := registry.Labs[key]; !ok {
			return false, nil
		}
		delete(registry.Labs, key)

		return true, nil
	})
}

// validate confirms the registry can be used. It is called once at startup so
// an operator sees a corrupt file in the log before a lab start fails.
func (a *uidAllocator) validate() error {
	if err := a.validatePool(); err != nil {
		return err
	}

	return a.withLock(func(*uidMapFile) (bool, error) { return false, nil })
}

// validateEntry re-derives the uid/gid from the slot and rejects a record that
// disagrees. This is the registry-level half of "never trust a foreign uid".
func (a *uidAllocator) validateEntry(entry uidMapEntry) error {
	if entry.Slot < 0 || entry.Slot >= a.slots {
		return fmt.Errorf(
			"%w: uid registry slot %d for lab %q is outside 0..%d",
			ErrEngineUnavailable,
			entry.Slot,
			entry.LabID,
			a.slots-1,
		)
	}
	want := a.first + uint32(entry.Slot)
	if entry.UID != want || entry.GID != want {
		return fmt.Errorf(
			"%w: uid registry entry for lab %q has uid/gid %d/%d, want %d",
			ErrEngineUnavailable,
			entry.LabID,
			entry.UID,
			entry.GID,
			want,
		)
	}

	return nil
}

// withLock runs fn under the registry flock. fn reports whether the registry
// changed; only then is the file rewritten atomically. A missing registry is
// treated as an empty versioned file.
func (a *uidAllocator) withLock(fn func(*uidMapFile) (bool, error)) error {
	if a == nil {
		return errors.New("per-lab uid registry is not configured")
	}
	if err := os.MkdirAll(a.dir, 0o700); err != nil {
		return fmt.Errorf("create per-lab uid state directory: %w", err)
	}
	lock, err := os.OpenFile(a.lockPath(), os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return fmt.Errorf("open per-lab uid lock: %w", err)
	}
	defer lock.Close()
	if err := syscall.Flock(int(lock.Fd()), syscall.LOCK_EX); err != nil {
		return fmt.Errorf("lock per-lab uid registry: %w", err)
	}
	defer func() {
		_ = syscall.Flock(int(lock.Fd()), syscall.LOCK_UN)
	}()

	a.mu.Lock()
	defer a.mu.Unlock()

	registry, err := a.load()
	if err != nil {
		return err
	}
	dirty, err := fn(registry)
	if err != nil {
		return err
	}
	if !dirty {
		return nil
	}

	return a.save(registry)
}

// load reads and validates the registry. A missing file is an empty registry;
// anything else that does not validate stops engine starts.
func (a *uidAllocator) load() (*uidMapFile, error) {
	path := a.path()
	info, err := os.Lstat(path)
	if errors.Is(err, os.ErrNotExist) {
		return &uidMapFile{Version: uidMapVersion, Labs: map[string]uidMapEntry{}}, nil
	}
	if err != nil {
		return nil, fmt.Errorf("stat per-lab uid registry: %w", err)
	}
	if !info.Mode().IsRegular() {
		return nil, fmt.Errorf("%w: per-lab uid registry is not a regular file", ErrEngineUnavailable)
	}
	if info.Mode().Perm() != 0o600 {
		return nil, fmt.Errorf(
			"%w: per-lab uid registry mode is %04o, want 0600",
			ErrEngineUnavailable,
			info.Mode().Perm(),
		)
	}
	if os.Geteuid() == 0 {
		stat, ok := info.Sys().(*syscall.Stat_t)
		if !ok || stat.Uid != 0 {
			return nil, fmt.Errorf("%w: per-lab uid registry is not owned by root", ErrEngineUnavailable)
		}
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read per-lab uid registry: %w", err)
	}
	registry := &uidMapFile{}
	if err := json.Unmarshal(data, registry); err != nil {
		return nil, fmt.Errorf("%w: decode per-lab uid registry: %v", ErrEngineUnavailable, err)
	}
	if registry.Version != uidMapVersion {
		return nil, fmt.Errorf(
			"%w: per-lab uid registry version %d is not supported (want %d)",
			ErrEngineUnavailable,
			registry.Version,
			uidMapVersion,
		)
	}
	if registry.Labs == nil {
		registry.Labs = map[string]uidMapEntry{}
	}
	slots := make(map[int]string, len(registry.Labs))
	for key, entry := range registry.Labs {
		if key == "" {
			return nil, fmt.Errorf("%w: per-lab uid registry has an empty key", ErrEngineUnavailable)
		}
		if err := a.validateEntry(entry); err != nil {
			return nil, err
		}
		if other, duplicate := slots[entry.Slot]; duplicate {
			return nil, fmt.Errorf(
				"%w: per-lab uid slot %d is shared by %q and %q",
				ErrEngineUnavailable,
				entry.Slot,
				other,
				key,
			)
		}
		slots[entry.Slot] = key
	}

	return registry, nil
}

// save publishes the registry atomically with root-only modes. The lock file
// is not written; the rename keeps readers from seeing a partial file.
func (a *uidAllocator) save(registry *uidMapFile) error {
	data, err := json.MarshalIndent(registry, "", "  ")
	if err != nil {
		return fmt.Errorf("encode per-lab uid registry: %w", err)
	}
	temporary, err := os.CreateTemp(a.dir, ".uidmap-*.tmp")
	if err != nil {
		return fmt.Errorf("create temporary per-lab uid registry: %w", err)
	}
	temporaryPath := temporary.Name()
	defer func() {
		_ = os.Remove(temporaryPath)
	}()
	if _, err := temporary.Write(data); err != nil {
		_ = temporary.Close()
		return fmt.Errorf("write per-lab uid registry: %w", err)
	}
	if err := temporary.Chmod(0o600); err != nil {
		_ = temporary.Close()
		return fmt.Errorf("secure per-lab uid registry: %w", err)
	}
	if err := temporary.Sync(); err != nil {
		_ = temporary.Close()
		return fmt.Errorf("sync per-lab uid registry: %w", err)
	}
	if err := temporary.Close(); err != nil {
		return fmt.Errorf("close per-lab uid registry: %w", err)
	}
	if err := os.Rename(temporaryPath, a.path()); err != nil {
		return fmt.Errorf("publish per-lab uid registry: %w", err)
	}
	if os.Geteuid() == 0 {
		if err := os.Chown(a.path(), 0, 0); err != nil {
			return fmt.Errorf("own per-lab uid registry: %w", err)
		}
	}

	return nil
}
