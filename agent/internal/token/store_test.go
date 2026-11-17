// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package token

import (
	"testing"
	"time"
)

func TestIssueResolveAndExpire(t *testing.T) {
	store := New()
	now := time.Date(2026, 11, 17, 12, 0, 0, 0, time.UTC)
	store.now = func() time.Time { return now }

	value := store.Issue("node-1", time.Minute)
	if len(value) != 64 {
		t.Fatalf("expected 64-char token, got %d", len(value))
	}
	node, ok := store.Resolve(value)
	if !ok || node != "node-1" {
		t.Fatalf("unexpected resolve result: %q %v", node, ok)
	}

	now = now.Add(2 * time.Minute)
	if _, ok := store.Resolve(value); ok {
		t.Fatal("expected expired token to be rejected")
	}
}

func TestRevokeRemovesNodeTokens(t *testing.T) {
	store := New()
	first := store.Issue("node-1", time.Minute)
	second := store.Issue("node-1", time.Minute)
	other := store.Issue("node-2", time.Minute)

	store.Revoke("node-1")

	if _, ok := store.Resolve(first); ok {
		t.Fatal("expected first token to be revoked")
	}
	if _, ok := store.Resolve(second); ok {
		t.Fatal("expected second token to be revoked")
	}
	if node, ok := store.Resolve(other); !ok || node != "node-2" {
		t.Fatal("expected unrelated token to remain")
	}
}
