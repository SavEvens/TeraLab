// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"bytes"
	"io"
	"log/slog"
	"os"
	"testing"
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/config"
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
