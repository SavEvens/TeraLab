// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package system

import "testing"

func TestInterfacesListsHostLinks(t *testing.T) {
	links := interfaces()
	if len(links) == 0 {
		t.Fatal("expected at least one host interface")
	}
	foundLoopback := false
	for _, link := range links {
		if link.Name == "lo" {
			foundLoopback = true
		}
		if link.MTU <= 0 {
			t.Fatalf("interface %s has invalid MTU %d", link.Name, link.MTU)
		}
	}
	if !foundLoopback {
		t.Fatal("expected loopback interface")
	}
}
