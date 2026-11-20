// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"fmt"
	"io"
	"sync"

	"github.com/creack/pty"
)

const (
	consoleHistoryBytes = 65536

	// pty.Winsize carries the dimensions as uint16 fields.
	maxConsoleDimension = 65535
)

// PTYSession is a viewer attached to a node's console stream.
type PTYSession struct {
	node *Node
	ch   chan []byte
	done chan struct{}
	once sync.Once

	mu      sync.Mutex
	backlog []byte
	writeTo io.Writer
}

func (s *PTYSession) Read(buffer []byte) (int, error) {
	s.mu.Lock()
	if len(s.backlog) > 0 {
		count := copy(buffer, s.backlog)
		s.backlog = s.backlog[count:]
		s.mu.Unlock()
		return count, nil
	}
	s.mu.Unlock()

	select {
	case chunk := <-s.ch:
		count := copy(buffer, chunk)
		if count < len(chunk) {
			s.mu.Lock()
			s.backlog = append(s.backlog, chunk[count:]...)
			s.mu.Unlock()
		}
		return count, nil
	case <-s.done:
		return 0, io.EOF
	case <-s.node.consoleDone:
		return 0, io.EOF
	}
}

func (s *PTYSession) Write(buffer []byte) (int, error) {
	return s.writeTo.Write(buffer)
}

// Resize applies a terminal window size to the node's console pty. It is a
// no-op when the node has no pty. Dimensions are validated before they are
// narrowed to the uint16 fields of pty.Winsize.
func (s *PTYSession) Resize(cols, rows int) error {
	if cols < 1 || rows < 1 || cols > maxConsoleDimension || rows > maxConsoleDimension {
		return fmt.Errorf("invalid console size %dx%d", cols, rows)
	}

	s.mu.Lock()
	defer s.mu.Unlock()

	if s.node == nil || s.node.PTY == nil {
		return nil
	}

	return pty.Setsize(s.node.PTY, &pty.Winsize{Cols: uint16(cols), Rows: uint16(rows)})
}

func (s *PTYSession) Close() error {
	s.once.Do(func() {
		close(s.done)
		s.node.historyMu.Lock()
		delete(s.node.subscribers, s.ch)
		s.node.historyMu.Unlock()
	})

	return nil
}

// AttachPTY returns a console view with replayed history for a running node.
func (m *Manager) AttachPTY(id string) (*PTYSession, bool) {
	m.mu.Lock()
	node, ok := m.nodes[id]
	m.mu.Unlock()
	if !ok || node.State != "running" || node.PTY == nil {
		return nil, false
	}

	session := &PTYSession{
		node:    node,
		ch:      make(chan []byte, 128),
		done:    make(chan struct{}),
		writeTo: node.PTY,
	}

	node.historyMu.Lock()
	session.backlog = append([]byte(nil), node.history...)
	if node.subscribers == nil {
		node.subscribers = map[chan []byte]struct{}{}
	}
	node.subscribers[session.ch] = struct{}{}
	node.historyMu.Unlock()

	return session, true
}

// pumpPTY drains a console pty so engines never block when nobody is viewing,
// and fans the stream out to attached sessions.
func (m *Manager) pumpPTY(node *Node) {
	buffer := make([]byte, 4096)
	for {
		count, err := node.PTY.Read(buffer)
		if count > 0 {
			chunk := append([]byte(nil), buffer[:count]...)
			node.historyMu.Lock()
			node.history = append(node.history, chunk...)
			if len(node.history) > consoleHistoryBytes {
				node.history = node.history[len(node.history)-consoleHistoryBytes:]
			}
			for subscriber := range node.subscribers {
				select {
				case subscriber <- chunk:
				default:
				}
			}
			node.historyMu.Unlock()
		}
		if err != nil {
			node.historyMu.Lock()
			close(node.consoleDone)
			node.historyMu.Unlock()
			return
		}
	}
}
