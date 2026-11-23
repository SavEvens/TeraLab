// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package system

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/config"
	"github.com/SavEvens/TeraLab/agent/internal/engine"
)

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

func TestParseProcStat(t *testing.T) {
	tests := []struct {
		name      string
		content   string
		wantOK    bool
		wantIdle  uint64
		wantTotal uint64
	}{
		{
			name:      "aggregate line ignores per cpu lines",
			content:   "cpu  100 0 50 800 50 0 0 0 0 0\ncpu0 50 0 25 400 25 0 0 0 0 0\n",
			wantOK:    true,
			wantIdle:  850,
			wantTotal: 1000,
		},
		{
			name:      "short aggregate line",
			content:   "cpu 10 20 30 40\n",
			wantOK:    true,
			wantIdle:  40,
			wantTotal: 100,
		},
		{
			name:      "guest columns are not double counted",
			content:   "cpu 1 2 3 4 5 6 7 8 9 10\n",
			wantOK:    true,
			wantIdle:  9,
			wantTotal: 36,
		},
		{name: "empty content"},
		{name: "no aggregate line", content: "cpu0 1 2 3 4 5\n"},
		{name: "truncated aggregate line", content: "cpu 1 2 3\n"},
		{name: "non numeric value", content: "cpu 1 2 3 four 5\n"},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			times, ok := parseProcStat([]byte(test.content))
			if ok != test.wantOK {
				t.Fatalf("parseProcStat(%q) ok = %v, want %v", test.content, ok, test.wantOK)
			}
			if !ok {
				return
			}
			if times.idle != test.wantIdle || times.total != test.wantTotal {
				t.Fatalf("parseProcStat(%q) = %+v, want idle=%d total=%d", test.content, times, test.wantIdle, test.wantTotal)
			}
		})
	}
}

func TestCPUUsageFromSamples(t *testing.T) {
	tests := []struct {
		name   string
		first  cpuTimes
		second cpuTimes
		want   float64
	}{
		{
			name:   "half busy",
			first:  cpuTimes{idle: 850, total: 1000},
			second: cpuTimes{idle: 900, total: 1100},
			want:   50,
		},
		{
			name:   "rounds to one decimal",
			first:  cpuTimes{},
			second: cpuTimes{idle: 1, total: 3},
			want:   66.7,
		},
		{
			name:   "all idle",
			first:  cpuTimes{idle: 100, total: 200},
			second: cpuTimes{idle: 200, total: 300},
			want:   0,
		},
		{
			name:   "all busy",
			first:  cpuTimes{idle: 100, total: 200},
			second: cpuTimes{idle: 100, total: 300},
			want:   100,
		},
		{
			name:   "zero delta",
			first:  cpuTimes{idle: 100, total: 200},
			second: cpuTimes{idle: 100, total: 200},
			want:   0,
		},
		{
			name:   "counter reset",
			first:  cpuTimes{idle: 100, total: 200},
			second: cpuTimes{idle: 10, total: 20},
			want:   0,
		},
		{
			name:   "idle counter went backwards",
			first:  cpuTimes{idle: 100, total: 200},
			second: cpuTimes{idle: 50, total: 300},
			want:   0,
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if got := cpuUsageFromSamples(test.first, test.second); got != test.want {
				t.Fatalf("cpuUsageFromSamples(%+v, %+v) = %v, want %v", test.first, test.second, got, test.want)
			}
		})
	}
}

func TestCPUUsageReturnsZeroWhenSamplingFails(t *testing.T) {
	missing := filepath.Join(t.TempDir(), "stat")
	if got := cpuUsage(context.Background(), missing); got != 0 {
		t.Fatalf("cpuUsage with a missing file = %v, want 0", got)
	}

	path := filepath.Join(t.TempDir(), "stat")
	if err := os.WriteFile(path, []byte("cpu 1 2 3 4 5\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	start := time.Now()
	if got := cpuUsage(ctx, path); got != 0 {
		t.Fatalf("cpuUsage with a cancelled context = %v, want 0", got)
	}
	const maxCancelLatency = 100 * time.Millisecond
	if elapsed := time.Since(start); elapsed >= maxCancelLatency {
		t.Fatalf("sampling ignored the cancelled context, waited %v", elapsed)
	}
}

func TestStatusCPUUsageJSONField(t *testing.T) {
	payload, err := json.Marshal(Status{CPUUsage: 42.5})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(payload), `"cpu_usage_percent":42.5`) {
		t.Fatalf("status payload lacks the measured cpu field: %s", payload)
	}
}

func TestStatusNodesExposeLastActivity(t *testing.T) {
	started := time.Now().Add(-2 * time.Minute).UTC().Round(0)
	activity := started.Add(time.Minute)
	payload, err := json.Marshal(Status{Nodes: []engine.NodeInfo{{
		ID:           "node1",
		State:        "running",
		Started:      started,
		LastActivity: activity,
	}}})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(payload), `"last_activity"`) {
		t.Fatalf("status payload lacks last_activity: %s", payload)
	}

	var decoded struct {
		Nodes []struct {
			Started      time.Time `json:"started"`
			LastActivity time.Time `json:"last_activity"`
		} `json:"nodes"`
	}
	if err := json.Unmarshal(payload, &decoded); err != nil {
		t.Fatal(err)
	}
	if len(decoded.Nodes) != 1 {
		t.Fatalf("status payload has %d nodes, want 1", len(decoded.Nodes))
	}
	reported := decoded.Nodes[0]
	if reported.LastActivity.IsZero() {
		t.Fatalf("running node has no last activity: %s", payload)
	}
	if reported.LastActivity.Before(reported.Started) {
		t.Fatalf("last activity %v is before started %v", reported.LastActivity, reported.Started)
	}
}

func writeDockerStub(t *testing.T, body string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "docker")
	script := "#!/bin/sh\n" + body + "\n"
	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatalf("write docker stub: %v", err)
	}

	return path
}

func TestDockerEngineStatusReportsDaemonAndVersion(t *testing.T) {
	binary := writeDockerStub(t, `case "$1" in
info) exit 0 ;;
version) echo "27.1.1" ;;
*) exit 1 ;;
esac`)

	status := dockerEngineStatus(context.Background(), config.Config{DockerBinary: binary})
	if status.Name != "docker" || status.Binary != binary {
		t.Fatalf("unexpected engine status identity: %+v", status)
	}
	if !status.Available {
		t.Fatalf("expected docker to be available: %+v", status)
	}
	if status.Version != "27.1.1" {
		t.Fatalf("unexpected docker version: %q", status.Version)
	}
}

func TestDockerEngineStatusRequiresReachableDaemon(t *testing.T) {
	binary := writeDockerStub(t, `exit 1`)

	status := dockerEngineStatus(context.Background(), config.Config{DockerBinary: binary})
	if status.Available {
		t.Fatalf("daemon failure must not report available: %+v", status)
	}
}

func TestDockerEngineStatusMissingBinary(t *testing.T) {
	status := dockerEngineStatus(context.Background(), config.Config{
		DockerBinary: filepath.Join(t.TempDir(), "missing-docker"),
	})
	if status.Available {
		t.Fatalf("missing binary must not report available: %+v", status)
	}
}
