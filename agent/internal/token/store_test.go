// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package token

import (
	"errors"
	"strings"
	"testing"
	"time"
)

func TestIssueResolveAndExpire(t *testing.T) {
	store := New()
	now := time.Date(2026, 11, 17, 12, 0, 0, 0, time.UTC)
	store.now = func() time.Time { return now }

	value, err := store.Issue("node-1", "user-1", time.Minute)
	if err != nil {
		t.Fatalf("issue: %v", err)
	}
	if len(value) != EncodedLength {
		t.Fatalf("expected %d-char token, got %d", EncodedLength, len(value))
	}
	if strings.ToLower(value) != value {
		t.Fatalf("token is not lowercase hexadecimal: %q", value)
	}
	entry, ok := store.Resolve(value)
	if !ok {
		t.Fatal("expected token to resolve")
	}
	if entry.NodeID != "node-1" || entry.UserID != "user-1" {
		t.Fatalf("unexpected token binding: %#v", entry)
	}
	if !entry.Issued.Equal(now) || !entry.Expires.Equal(now.Add(time.Minute)) {
		t.Fatalf("unexpected token times: %#v", entry)
	}

	now = now.Add(2 * time.Minute)
	if _, ok := store.Resolve(value); ok {
		t.Fatal("expected expired token to be rejected")
	}
	if len(store.entries) != 0 {
		t.Fatalf("expected expired token to be removed, have %d entries", len(store.entries))
	}
}

func TestIssueWithoutUserKeepsEmptyUserID(t *testing.T) {
	store := New()
	value, err := store.Issue("node-1", "", time.Minute)
	if err != nil {
		t.Fatalf("issue: %v", err)
	}
	entry, ok := store.Resolve(value)
	if !ok {
		t.Fatal("expected token to resolve")
	}
	if entry.UserID != "" {
		t.Fatalf("expected empty user id, got %q", entry.UserID)
	}
}

func TestRevokeRemovesNodeTokens(t *testing.T) {
	store := New()
	first, _ := store.Issue("node-1", "user-1", time.Minute)
	second, _ := store.Issue("node-1", "user-2", time.Minute)
	other, _ := store.Issue("node-2", "user-1", time.Minute)

	store.Revoke("node-1")

	if _, ok := store.Resolve(first); ok {
		t.Fatal("expected first token to be revoked")
	}
	if _, ok := store.Resolve(second); ok {
		t.Fatal("expected second token to be revoked")
	}
	if entry, ok := store.Resolve(other); !ok || entry.NodeID != "node-2" {
		t.Fatal("expected unrelated token to remain")
	}
}

func TestRevokeTokenAndUser(t *testing.T) {
	store := New()
	first, _ := store.Issue("node-1", "user-1", time.Minute)
	second, _ := store.Issue("node-1", "user-1", time.Minute)
	third, _ := store.Issue("node-2", "user-2", time.Minute)

	if !store.RevokeToken(first) {
		t.Fatal("expected RevokeToken to report the token existed")
	}
	if store.RevokeToken(first) {
		t.Fatal("expected a second RevokeToken to report nothing")
	}
	if _, ok := store.Resolve(first); ok {
		t.Fatal("expected revoked token to be rejected")
	}
	if removed := store.RevokeUser("user-1"); removed != 1 {
		t.Fatalf("RevokeUser removed %d, want 1", removed)
	}
	if _, ok := store.Resolve(second); ok {
		t.Fatal("expected user tokens to be revoked")
	}
	if _, ok := store.Resolve(third); !ok {
		t.Fatal("expected another user's token to remain")
	}
	if removed := store.RevokeUser(""); removed != 0 {
		t.Fatalf("RevokeUser with an empty id removed %d tokens", removed)
	}
}

func TestDefaultTTL(t *testing.T) {
	// The API returns expires_in from the agent; pin the documented window.
	if DefaultTTL != 60*time.Second {
		t.Fatalf("DefaultTTL = %v, want 60s", DefaultTTL)
	}
}

func TestIssueUsesDefaultTTLForNonPositive(t *testing.T) {
	store := New()
	now := time.Date(2026, 11, 17, 12, 0, 0, 0, time.UTC)
	store.now = func() time.Time { return now }

	value, err := store.Issue("node-1", "", 0)
	if err != nil {
		t.Fatalf("issue: %v", err)
	}
	entry, ok := store.Resolve(value)
	if !ok {
		t.Fatal("expected token to resolve")
	}
	if !entry.Expires.Equal(now.Add(DefaultTTL)) {
		t.Fatalf("expiry = %v, want %v", entry.Expires, now.Add(DefaultTTL))
	}
}

func TestIssueSweepsExpiredEntries(t *testing.T) {
	store := New()
	now := time.Date(2026, 11, 17, 12, 0, 0, 0, time.UTC)
	store.now = func() time.Time { return now }

	expired, err := store.Issue("node-1", "", time.Second)
	if err != nil {
		t.Fatalf("issue: %v", err)
	}
	now = now.Add(2 * time.Second)
	if _, err := store.Issue("node-1", "", time.Minute); err != nil {
		t.Fatalf("issue after expiry: %v", err)
	}
	if _, ok := store.entries[expired]; ok {
		t.Fatal("expired entry was not swept on issue")
	}
}

func TestIssueEnforcesPerNodeCap(t *testing.T) {
	store := New()
	store.maxPerNode = 2
	for index := 0; index < 2; index++ {
		if _, err := store.Issue("node-1", "", time.Minute); err != nil {
			t.Fatalf("issue %d: %v", index, err)
		}
	}
	if _, err := store.Issue("node-1", "", time.Minute); !errors.Is(err, ErrNodeCapacity) {
		t.Fatalf("expected ErrNodeCapacity, got %v", err)
	}
	if _, err := store.Issue("node-2", "", time.Minute); err != nil {
		t.Fatalf("per-node cap must not affect another node: %v", err)
	}
}

func TestIssueEnforcesTotalCap(t *testing.T) {
	store := New()
	store.maxEntries = 2
	if _, err := store.Issue("node-1", "", time.Minute); err != nil {
		t.Fatalf("issue 1: %v", err)
	}
	if _, err := store.Issue("node-2", "", time.Minute); err != nil {
		t.Fatalf("issue 2: %v", err)
	}
	if _, err := store.Issue("node-3", "", time.Minute); !errors.Is(err, ErrCapacity) {
		t.Fatalf("expected ErrCapacity, got %v", err)
	}
	if len(store.entries) != 2 {
		t.Fatalf("cap must not change the store size, have %d", len(store.entries))
	}
}

func TestIssueRequiresNodeID(t *testing.T) {
	store := New()
	if _, err := store.Issue("", "", time.Minute); err == nil {
		t.Fatal("expected an empty node id to be rejected")
	}
}

func TestCleanupRemovesExpiredEntries(t *testing.T) {
	store := New()
	now := time.Date(2026, 11, 17, 12, 0, 0, 0, time.UTC)
	store.now = func() time.Time { return now }

	value, err := store.Issue("node-1", "", time.Second)
	if err != nil {
		t.Fatalf("issue: %v", err)
	}
	now = now.Add(2 * time.Second)
	store.Cleanup()
	if _, ok := store.entries[value]; ok {
		t.Fatal("Cleanup left an expired entry behind")
	}
}
