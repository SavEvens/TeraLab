// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package token

import (
	"crypto/rand"
	"encoding/hex"
	"sync"
	"time"
)

type entry struct {
	nodeID  string
	expires time.Time
}

type Store struct {
	mu      sync.Mutex
	entries map[string]entry
	now     func() time.Time
}

func New() *Store {
	return &Store{entries: map[string]entry{}, now: time.Now}
}

func (s *Store) Issue(nodeID string, ttl time.Duration) string {
	raw := make([]byte, 32)
	if _, err := rand.Read(raw); err != nil {
		return ""
	}
	token := hex.EncodeToString(raw)
	s.mu.Lock()
	s.entries[token] = entry{nodeID: nodeID, expires: s.now().Add(ttl)}
	s.mu.Unlock()
	return token
}

func (s *Store) Resolve(token string) (string, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	item, ok := s.entries[token]
	if !ok {
		return "", false
	}
	if s.now().After(item.expires) {
		delete(s.entries, token)
		return "", false
	}
	return item.nodeID, true
}

func (s *Store) Revoke(nodeID string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for token, item := range s.entries {
		if item.nodeID == nodeID {
			delete(s.entries, token)
		}
	}
}
