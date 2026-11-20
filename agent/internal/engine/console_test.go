// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"bytes"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/config"
	"github.com/creack/pty"
)

func testConsoleManager(t *testing.T) *Manager {
	t.Helper()

	return NewManager(config.Config{
		RunDir:    t.TempDir(),
		ImagesDir: t.TempDir(),
		VMUser:    "teralab-vm",
	}, slog.New(slog.NewTextHandler(io.Discard, nil)))
}

func TestPTYPumpDrainsWithoutViewerAndReplaysHistory(t *testing.T) {
	manager := testConsoleManager(t)
	reader, writer, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	node := &Node{
		Spec:        Spec{ID: "node1", Name: "R1", Engine: "qemu"},
		PTY:         reader,
		State:       "running",
		consoleDone: make(chan struct{}),
		subscribers: map[chan []byte]struct{}{},
	}
	manager.nodes["node1"] = node
	go manager.pumpPTY(node)

	payload := bytes.Repeat([]byte("x"), 200000)
	done := make(chan struct{})
	go func() {
		_, _ = writer.Write(payload)
		close(done)
	}()
	select {
	case <-done:
	case <-time.After(3 * time.Second):
		t.Fatal("pty write blocked without a viewer")
	}

	time.Sleep(50 * time.Millisecond)

	session, ok := manager.AttachPTY("node1")
	if !ok {
		t.Fatal("attach failed")
	}
	history, err := readAll(session, consoleHistoryBytes)
	if err != nil {
		t.Fatalf("read history: %v", err)
	}
	if len(history) != consoleHistoryBytes {
		t.Fatalf("unexpected history length: %d", len(history))
	}
	if history[len(history)-1] != 'x' {
		t.Fatal("unexpected history content")
	}
	_ = session.Close()

	second, ok := manager.AttachPTY("node1")
	if !ok {
		t.Fatal("second attach failed")
	}
	if _, err := second.Read(make([]byte, 1)); err != nil {
		t.Fatalf("second session history read: %v", err)
	}
	_ = second.Close()

	_ = writer.Close()
	select {
	case <-node.consoleDone:
	case <-time.After(2 * time.Second):
		t.Fatal("pump did not stop after pty close")
	}
}

func TestPTYSessionWriteReachesEngine(t *testing.T) {
	manager := testConsoleManager(t)
	reader, writer, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	defer writer.Close()
	node := &Node{
		Spec:        Spec{ID: "node1", Name: "R1", Engine: "qemu"},
		PTY:         writer,
		State:       "running",
		consoleDone: make(chan struct{}),
		subscribers: map[chan []byte]struct{}{},
	}
	manager.nodes["node1"] = node

	session, ok := manager.AttachPTY("node1")
	if !ok {
		t.Fatal("attach failed")
	}
	if _, err := session.Write([]byte("show version\n")); err != nil {
		t.Fatalf("write: %v", err)
	}
	buffer := make([]byte, 64)
	_ = reader.SetReadDeadline(time.Now().Add(time.Second))
	count, err := reader.Read(buffer)
	if err != nil {
		t.Fatalf("engine read: %v", err)
	}
	if string(buffer[:count]) != "show version\n" {
		t.Fatalf("unexpected engine input: %q", buffer[:count])
	}
	_ = session.Close()
}

func readAll(session *PTYSession, expected int) ([]byte, error) {
	result := make([]byte, 0, expected)
	buffer := make([]byte, 65536)
	for len(result) < expected {
		count, err := session.Read(buffer)
		if err != nil {
			return result, err
		}
		result = append(result, buffer[:count]...)
	}

	return result, nil
}

func TestReadCgroupUsage(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "memory.current"), []byte("123456789\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "cpu.stat"), []byte("usage_usec 987654\nuser_usec 1\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	memory, cpu := readCgroupUsage(dir)
	if memory != 123456789 || cpu != 987654 {
		t.Fatalf("unexpected usage: %d %d", memory, cpu)
	}
	if memory, cpu := readCgroupUsage(""); memory != 0 || cpu != 0 {
		t.Fatal("empty cgroup path must return zero usage")
	}
}

func TestEnsureExecutableRepairsMode(t *testing.T) {
	path := filepath.Join(t.TempDir(), "iol.bin")
	if err := os.WriteFile(path, []byte("\x7fELF"), 0o640); err != nil {
		t.Fatal(err)
	}
	if err := ensureExecutable(path); err != nil {
		t.Fatalf("ensureExecutable: %v", err)
	}
	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode()&0o111 == 0 {
		t.Fatal("expected executable bit after repair")
	}
}

func TestPTYSessionResizeSetsWindowSize(t *testing.T) {
	ptmx, tty, err := pty.Open()
	if err != nil {
		t.Skipf("pty unavailable: %v", err)
	}
	defer ptmx.Close()
	defer tty.Close()

	manager := testConsoleManager(t)
	node := &Node{
		Spec:        Spec{ID: "node1", Name: "R1", Engine: "qemu"},
		PTY:         ptmx,
		State:       "running",
		consoleDone: make(chan struct{}),
		subscribers: map[chan []byte]struct{}{},
	}
	manager.nodes["node1"] = node

	session, ok := manager.AttachPTY("node1")
	if !ok {
		t.Fatal("attach failed")
	}
	defer session.Close()

	if err := session.Resize(132, 43); err != nil {
		t.Fatalf("resize: %v", err)
	}

	rows, cols, err := pty.Getsize(ptmx)
	if err != nil {
		t.Fatalf("get size: %v", err)
	}
	if cols != 132 || rows != 43 {
		t.Fatalf("unexpected window size %dx%d, want 132x43", cols, rows)
	}

	if err := session.Resize(0, 24); err == nil {
		t.Fatal("expected an error for an invalid size")
	}
}

func TestPTYSessionResizeWithoutPTYIsNoOp(t *testing.T) {
	session := &PTYSession{node: &Node{}}

	if err := session.Resize(80, 24); err != nil {
		t.Fatalf("resize without pty: %v", err)
	}
}
