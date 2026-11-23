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

	// OnIO, when set, is notified after every successful console read or
	// write. It must not block: it runs on the console hot path.
	OnIO func()

	mu      sync.Mutex
	backlog []byte
	writeTo io.Writer
}

// notifyIO reports console activity through the optional callback.
func (s *PTYSession) notifyIO() {
	if s.OnIO != nil {
		s.OnIO()
	}
}

func (s *PTYSession) Read(buffer []byte) (int, error) {
	s.mu.Lock()
	if len(s.backlog) > 0 {
		count := copy(buffer, s.backlog)
		s.backlog = s.backlog[count:]
		s.mu.Unlock()
		if count > 0 {
			s.notifyIO()
		}
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
		if count > 0 {
			s.notifyIO()
		}
		return count, nil
	case <-s.done:
		return 0, io.EOF
	case <-s.node.consoleDone:
		return 0, io.EOF
	}
}

// Write sends one burst to the node console. The node write mutex keeps a
// user's keystrokes and an agent command replay from interleaving.
func (s *PTYSession) Write(buffer []byte) (int, error) {
	if s.writeTo == nil {
		return 0, io.ErrClosedPipe
	}
	if s.node != nil {
		s.node.writeMu.Lock()
		defer s.node.writeMu.Unlock()
	}

	count, err := s.writeTo.Write(buffer)
	if count > 0 {
		s.notifyIO()
	}

	return count, err
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
		writeTo: node.consoleWriter(),
		OnIO:    func() { m.touchActivity(id) },
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

// activityStream reports successful console reads and writes to an optional
// callback. Docker exec consoles wrap their pty master with it so container
// console I/O counts as node activity, exactly like a serial pty. A wrapped
// read is engine output rather than user input, which can only make a node
// look more active; that errs on the side of never idle-wiping a live guest.
type activityStream struct {
	stream io.ReadWriter
	touch  func()
}

func (s *activityStream) Read(buffer []byte) (int, error) {
	count, err := s.stream.Read(buffer)
	if count > 0 && s.touch != nil {
		s.touch()
	}

	return count, err
}

func (s *activityStream) Write(buffer []byte) (int, error) {
	count, err := s.stream.Write(buffer)
	if count > 0 && s.touch != nil {
		s.touch()
	}

	return count, err
}

// consoleReader returns the activity-wrapped console stream when the engine
// provides one (docker exec) and the plain pty otherwise.
func (n *Node) consoleReader() io.Reader {
	if n.consoleStream != nil {
		return n.consoleStream
	}

	return n.PTY
}

// consoleWriter returns the activity-wrapped console stream when the engine
// provides one (docker exec) and the plain pty otherwise.
func (n *Node) consoleWriter() io.Writer {
	if n.consoleStream != nil {
		return n.consoleStream
	}

	return n.PTY
}

// markConsoleOutput marks a node ready on first console output. A QEMU guest
// has no command prompt, so any output means the guest is booting; IOL and
// VPCS readiness comes from the console prompt probe instead.
func (n *Node) markConsoleOutput() {
	if n.Spec.Engine == "qemu" || n.Spec.Engine == "" {
		n.Ready.Store(true)
	}
}

// pumpPTY drains a console pty so engines never block when nobody is viewing,
// and fans the stream out to attached sessions.
func (m *Manager) pumpPTY(node *Node) {
	buffer := make([]byte, 4096)
	for {
		count, err := node.consoleReader().Read(buffer)
		if count > 0 {
			node.markConsoleOutput()
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
