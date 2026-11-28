// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package token

import (
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"sync"
	"time"
)

// SubprotocolPrefix is the WebSocket subprotocol namespace for console
// capabilities. Clients send "teralab.console.<token>" in
// Sec-WebSocket-Protocol; the gateway echoes the selected value.
const SubprotocolPrefix = "teralab.console."

// EncodedLength is the number of hexadecimal characters in an issued token.
const EncodedLength = 64

const (
	// DefaultTTL is how long a freshly issued console token can be redeemed.
	DefaultTTL = 60 * time.Second
	// MaxEntries bounds the whole store. Pending tokens are cheap but not
	// free, and an unbounded map is an easy memory-exhaustion target.
	MaxEntries = 1000
	// MaxPendingPerNode bounds outstanding tokens for one node. A user only
	// needs one or two (initial connection plus reconnect); more is abuse or
	// a runaway client.
	MaxPendingPerNode = 5
)

// ErrCapacity reports that the global pending-token cap is full.
var ErrCapacity = errors.New("console token store is full")

// ErrNodeCapacity reports that the per-node pending-token cap is full.
var ErrNodeCapacity = errors.New("too many pending console tokens for this node")

// Token is the capability stored for one issued console token.
type Token struct {
	NodeID  string
	UserID  string
	Issued  time.Time
	Expires time.Time
}

// Store holds short-lived console capabilities. Tokens are random 256-bit
// values and are never logged or persisted.
type Store struct {
	mu         sync.Mutex
	entries    map[string]Token
	now        func() time.Time
	maxEntries int
	maxPerNode int
}

func New() *Store {
	return &Store{
		entries:    map[string]Token{},
		now:        time.Now,
		maxEntries: MaxEntries,
		maxPerNode: MaxPendingPerNode,
	}
}

// Issue creates a token bound to nodeID and, when the API supplies one, the
// authenticated user ID. Expired entries are swept first, then both caps are
// enforced. A non-positive ttl falls back to DefaultTTL.
func (s *Store) Issue(nodeID, userID string, ttl time.Duration) (string, error) {
	if nodeID == "" {
		return "", errors.New("console token needs a node id")
	}
	if ttl <= 0 {
		ttl = DefaultTTL
	}

	raw := make([]byte, EncodedLength/2)
	if _, err := rand.Read(raw); err != nil {
		return "", fmt.Errorf("generate console token: %w", err)
	}
	value := hex.EncodeToString(raw)

	now := s.now()
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.entries == nil {
		s.entries = map[string]Token{}
	}
	if s.maxPerNode <= 0 {
		s.maxPerNode = MaxPendingPerNode
	}
	if s.maxEntries <= 0 {
		s.maxEntries = MaxEntries
	}
	s.sweepLocked(now)
	pending := 0
	for _, item := range s.entries {
		if item.NodeID == nodeID {
			pending++
		}
	}
	if pending >= s.maxPerNode {
		return "", ErrNodeCapacity
	}
	if len(s.entries) >= s.maxEntries {
		return "", ErrCapacity
	}
	s.entries[value] = Token{
		NodeID:  nodeID,
		UserID:  userID,
		Issued:  now,
		Expires: now.Add(ttl),
	}

	return value, nil
}

// Resolve returns the capability for value. Expired entries are removed. The
// token is not consumed: a console reconnect within the TTL keeps working, as
// it did before this change.
func (s *Store) Resolve(value string) (Token, bool) {
	now := s.now()
	s.mu.Lock()
	defer s.mu.Unlock()
	s.sweepLocked(now)
	if value == "" {
		return Token{}, false
	}
	item, ok := s.entries[value]
	if !ok {
		return Token{}, false
	}
	if !now.Before(item.Expires) {
		delete(s.entries, value)

		return Token{}, false
	}

	return item, true
}

// Revoke removes every token bound to nodeID. It runs when a node stops or is
// wiped so a capability cannot outlive the engine process.
func (s *Store) Revoke(nodeID string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for value, item := range s.entries {
		if item.NodeID == nodeID {
			delete(s.entries, value)
		}
	}
}

// RevokeToken removes one specific capability and reports whether it existed.
func (s *Store) RevokeToken(value string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if _, ok := s.entries[value]; !ok {
		return false
	}
	delete(s.entries, value)

	return true
}

// RevokeUser removes every token issued for userID and returns the count. An
// empty user ID is a no-op: tokens without a user are not a user session.
func (s *Store) RevokeUser(userID string) int {
	if userID == "" {
		return 0
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	removed := 0
	for value, item := range s.entries {
		if item.UserID == userID {
			delete(s.entries, value)
			removed++
		}
	}

	return removed
}

// Cleanup removes expired entries. Issue and Resolve call it inline; the
// gateway also calls it on a timer so an idle store does not retain stale
// capabilities.
func (s *Store) Cleanup() {
	now := s.now()
	s.mu.Lock()
	defer s.mu.Unlock()
	s.sweepLocked(now)
}

func (s *Store) sweepLocked(now time.Time) {
	for value, item := range s.entries {
		if !now.Before(item.Expires) {
			delete(s.entries, value)
		}
	}
}
