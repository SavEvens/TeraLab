// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"bytes"
	"errors"
	"io"
	"os"
	"testing"
	"time"
)

// newActivityNode registers a running node with a console-ready pty and an
// activity timestamp one hour in the past, so any touch is unambiguous.
func newActivityNode(t *testing.T, id string, pty *os.File) (*Manager, *Node, time.Time) {
	t.Helper()
	manager := testConsoleManager(t)
	started := time.Now().Add(-time.Hour)
	node := &Node{
		Spec:         Spec{ID: id, Name: "R1", Engine: "qemu"},
		PTY:          pty,
		State:        "running",
		Started:      started,
		LastActivity: started,
		consoleDone:  make(chan struct{}),
		subscribers:  map[chan []byte]struct{}{},
	}
	manager.nodes[id] = node

	return manager, node, started
}

func nodeActivity(manager *Manager, node *Node) time.Time {
	manager.mu.Lock()
	defer manager.mu.Unlock()

	return node.LastActivity
}

func TestPTYSessionReadTouchesActivity(t *testing.T) {
	reader, writer, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	defer reader.Close()
	defer writer.Close()

	manager, node, started := newActivityNode(t, "node1", reader)
	node.history = []byte("hello")
	session, ok := manager.AttachPTY("node1")
	if !ok {
		t.Fatal("attach failed")
	}
	defer session.Close()

	buffer := make([]byte, len("hello"))
	if count, readErr := session.Read(buffer); readErr != nil || count != len("hello") {
		t.Fatalf("session read = %d, %v", count, readErr)
	}
	if activity := nodeActivity(manager, node); !activity.After(started) {
		t.Fatalf("console read did not touch activity: %v", activity)
	}
}

func TestPTYSessionWriteTouchesActivity(t *testing.T) {
	reader, writer, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	defer reader.Close()
	defer writer.Close()

	manager, node, started := newActivityNode(t, "node1", writer)
	session, ok := manager.AttachPTY("node1")
	if !ok {
		t.Fatal("attach failed")
	}
	defer session.Close()

	if _, writeErr := session.Write([]byte("show version\n")); writeErr != nil {
		t.Fatalf("session write: %v", writeErr)
	}
	if activity := nodeActivity(manager, node); !activity.After(started) {
		t.Fatalf("console write did not touch activity: %v", activity)
	}
}

func TestPTYSessionFailedIOLeavesActivityAlone(t *testing.T) {
	manager, node, started := newActivityNode(t, "node1", nil)
	session := &PTYSession{
		node: node,
		ch:   make(chan []byte),
		done: make(chan struct{}),
		OnIO: func() { manager.touchActivity("node1") },
	}
	session.writeTo = nil

	if _, err := session.Write([]byte("x")); !errors.Is(err, io.ErrClosedPipe) {
		t.Fatalf("write without a stream = %v, want a closed pipe", err)
	}
	if activity := nodeActivity(manager, node); !activity.Equal(started) {
		t.Fatalf("failed write touched activity: %v", activity)
	}

	close(session.done)
	if count, err := session.Read(make([]byte, 8)); count != 0 || !errors.Is(err, io.EOF) {
		t.Fatalf("read after close = %d, %v", count, err)
	}
	if activity := nodeActivity(manager, node); !activity.Equal(started) {
		t.Fatalf("closed read touched activity: %v", activity)
	}
}

func TestTouchActivityIgnoresUnknownNodes(t *testing.T) {
	manager := testConsoleManager(t)

	manager.touchActivity("")
	manager.touchActivity("missing")

	if len(manager.nodes) != 0 {
		t.Fatalf("unknown ids must not create nodes: %v", manager.nodes)
	}
}

func TestRegisterInitialisesActivityForRunningNode(t *testing.T) {
	manager := testConsoleManager(t)
	started := time.Now()
	node := &Node{
		Spec:    Spec{ID: "node1", Engine: "docker", Image: "alpine:3.20"},
		State:   "running",
		Started: started,
		RunDir:  t.TempDir(),
	}

	before := time.Now()
	info, err := manager.register(node)
	if err != nil {
		t.Fatalf("register: %v", err)
	}
	// A fresh launch is active now, which is within a second of its start.
	if node.LastActivity.Before(before) {
		t.Fatalf("node activity = %v, want a timestamp at or after %v", node.LastActivity, before)
	}
	if gap := node.LastActivity.Sub(started); gap < 0 || gap > time.Second {
		t.Fatalf("node activity %v is not within a second of start %v", node.LastActivity, started)
	}
	if !info.LastActivity.Equal(node.LastActivity) {
		t.Fatalf("reported activity = %v, want %v", info.LastActivity, node.LastActivity)
	}
}

func TestRegisterTreatsRestoredRunningNodeAsActive(t *testing.T) {
	manager := testConsoleManager(t)
	started := time.Now().Add(-6 * time.Hour)
	node := &Node{
		Spec:    Spec{ID: "node1", Engine: "docker", Image: "alpine:3.20"},
		State:   "running",
		Started: started,
		RunDir:  t.TempDir(),
	}

	before := time.Now()
	info, err := manager.register(node)
	if err != nil {
		t.Fatalf("register: %v", err)
	}
	// A node restored as running is active at re-registration, not at the old
	// recorded start time.
	if node.LastActivity.Before(before) {
		t.Fatalf("restored node activity = %v, want a timestamp at or after %v", node.LastActivity, before)
	}
	if !info.LastActivity.Equal(node.LastActivity) {
		t.Fatalf("reported activity = %v, want %v", info.LastActivity, node.LastActivity)
	}
}

func TestRegisterKeepsStartTimeForStoppedNode(t *testing.T) {
	manager := testConsoleManager(t)
	started := time.Now().Add(-time.Hour)
	node := &Node{
		Spec:    Spec{ID: "node1", Engine: "docker", Image: "alpine:3.20"},
		State:   "stopped",
		Started: started,
		RunDir:  t.TempDir(),
	}

	info, err := manager.register(node)
	if err != nil {
		t.Fatalf("register: %v", err)
	}
	if !node.LastActivity.Equal(started) {
		t.Fatalf("stopped node activity = %v, want the start time %v", node.LastActivity, started)
	}
	if !info.LastActivity.Equal(started) {
		t.Fatalf("reported activity = %v, want the start time %v", info.LastActivity, started)
	}
}

func TestInfoReportsLastActivity(t *testing.T) {
	manager := testConsoleManager(t)
	started := time.Now().Add(-time.Minute)
	activity := started.Add(30 * time.Second)
	node := &Node{
		Spec:         Spec{ID: "node1", Name: "R1", Engine: "qemu"},
		State:        "running",
		Started:      started,
		LastActivity: activity,
	}

	info := manager.info(node)
	if !info.LastActivity.Equal(activity) {
		t.Fatalf("info activity = %v, want %v", info.LastActivity, activity)
	}
}

// memoryStream is an in-memory console stream for the activity wrapper test.
type memoryStream struct {
	buffer bytes.Buffer
}

func (s *memoryStream) Read(buffer []byte) (int, error) {
	return s.buffer.Read(buffer)
}

func (s *memoryStream) Write(buffer []byte) (int, error) {
	return s.buffer.Write(buffer)
}

func TestActivityStreamTouchesOnSuccessfulIO(t *testing.T) {
	stream := &memoryStream{}
	stream.buffer.WriteString("in")
	touches := 0
	wrapped := &activityStream{
		stream: stream,
		touch:  func() { touches++ },
	}

	buffer := make([]byte, 2)
	if count, err := wrapped.Read(buffer); count != 2 || err != nil {
		t.Fatalf("read = %d, %v", count, err)
	}
	if touches != 1 {
		t.Fatalf("read touches = %d, want 1", touches)
	}
	if count, err := wrapped.Write([]byte("out")); count != 3 || err != nil {
		t.Fatalf("write = %d, %v", count, err)
	}
	if touches != 2 {
		t.Fatalf("write touches = %d, want 2", touches)
	}

	// A zero-length read or an empty write is not activity.
	if count, _ := wrapped.Read(nil); count != 0 {
		t.Fatalf("unexpected zero-length read = %d", count)
	}
	if _, err := wrapped.Write(nil); err != nil {
		t.Fatalf("empty write: %v", err)
	}
	if touches != 2 {
		t.Fatalf("empty I/O touched activity: %d", touches)
	}
}
