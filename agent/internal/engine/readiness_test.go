// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"encoding/json"
	"os"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestInfoReportsReadyOnlyWhileRunning(t *testing.T) {
	manager := testConsoleManager(t)
	node := &Node{
		Spec:  Spec{ID: "node1", Name: "R1", Engine: "iol"},
		State: "running",
	}
	node.Ready.Store(true)

	info := manager.info(node)
	if !info.Ready {
		t.Fatal("a ready running node must report ready")
	}
	data, err := json.Marshal(info)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(data), `"ready":true`) {
		t.Fatalf("ready must be serialised, got %s", data)
	}

	node.State = "stopped"
	if manager.info(node).Ready {
		t.Fatal("a stopped node must not report ready")
	}
}

func TestPumpPTYReadinessPerEngine(t *testing.T) {
	tests := []struct {
		engine    string
		wantReady bool
	}{
		{engine: "qemu", wantReady: true},
		{engine: "iol", wantReady: false},
		{engine: "vpcs", wantReady: false},
		{engine: "dynamips", wantReady: false},
	}

	for _, test := range tests {
		t.Run(test.engine, func(t *testing.T) {
			manager := testConsoleManager(t)
			reader, writer, err := os.Pipe()
			if err != nil {
				t.Fatal(err)
			}
			node := &Node{
				Spec:        Spec{ID: "node1", Name: "R1", Engine: test.engine},
				PTY:         reader,
				State:       "running",
				consoleDone: make(chan struct{}),
				subscribers: map[chan []byte]struct{}{},
			}
			manager.nodes["node1"] = node
			go manager.pumpPTY(node)

			if _, err := writer.Write([]byte("booting\r\n")); err != nil {
				t.Fatal(err)
			}

			deadline := time.Now().Add(2 * time.Second)
			for {
				node.historyMu.Lock()
				seen := len(node.history) > 0
				node.historyMu.Unlock()
				if seen {
					break
				}
				if time.Now().After(deadline) {
					t.Fatal("console output was not pumped")
				}
				time.Sleep(5 * time.Millisecond)
			}

			if got := node.Ready.Load(); got != test.wantReady {
				t.Fatalf("Ready = %v, want %v after console output", got, test.wantReady)
			}

			_ = writer.Close()
			select {
			case <-node.consoleDone:
			case <-time.After(2 * time.Second):
				t.Fatal("pump did not stop after pty close")
			}
		})
	}
}

// overlapDetector records writes that enter it while another write is running.
type overlapDetector struct {
	mu      sync.Mutex
	active  int
	overlap int
}

func (d *overlapDetector) Write(buffer []byte) (int, error) {
	d.mu.Lock()
	d.active++
	if d.active > 1 {
		d.overlap++
	}
	d.mu.Unlock()

	time.Sleep(2 * time.Millisecond)

	d.mu.Lock()
	d.active--
	d.mu.Unlock()

	return len(buffer), nil
}

func TestPTYSessionWritesAreSerialised(t *testing.T) {
	node := &Node{}
	detector := &overlapDetector{}
	sessions := []*PTYSession{
		{node: node, writeTo: detector},
		{node: node, writeTo: detector},
	}

	var group sync.WaitGroup
	for _, session := range sessions {
		for index := 0; index < 4; index++ {
			group.Add(1)
			go func(writer *PTYSession) {
				defer group.Done()
				if _, err := writer.Write([]byte("configure terminal\r")); err != nil {
					t.Errorf("write: %v", err)
				}
			}(session)
		}
	}
	group.Wait()

	if detector.overlap != 0 {
		t.Fatalf("%d console writes overlapped", detector.overlap)
	}
}

func TestPTYSessionWriteWithoutTargetFails(t *testing.T) {
	session := &PTYSession{node: &Node{}}

	if _, err := session.Write([]byte("show version\n")); err == nil {
		t.Fatal("writing to a detached session must fail")
	}
}
