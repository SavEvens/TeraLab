// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"os"
	"strings"
	"testing"
)

func TestAgentUnitCanSignalEngineProcesses(t *testing.T) {
	data, err := os.ReadFile("../../systemd/teralab-agent.service")
	if err != nil {
		t.Fatalf("read unit: %v", err)
	}
	unit := string(data)
	for _, capability := range []string{"CAP_KILL", "CAP_SETUID", "CAP_SETGID", "CAP_NET_ADMIN"} {
		if !strings.Contains(unit, "CapabilityBoundingSet=") || !strings.Contains(unit, capability) {
			t.Fatalf("agent unit must include %s", capability)
		}
	}
	if !strings.Contains(unit, "AmbientCapabilities=") || !strings.Contains(unit, "CAP_KILL") {
		t.Fatal("agent unit must grant ambient CAP_KILL for engine stop")
	}
}
