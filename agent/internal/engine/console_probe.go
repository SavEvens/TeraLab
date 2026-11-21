// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"errors"
	"regexp"
	"strings"
	"sync"
	"time"
)

const (
	// consoleProbeBudget bounds how long the agent waits for a first prompt
	// before it gives up and leaves the node not ready.
	consoleProbeBudget = 60 * time.Second
	// consoleCommandTimeout bounds one replayed command's echo and prompt.
	consoleCommandTimeout = 10 * time.Second
	// consoleWindowBytes is how much console output the probe keeps to match
	// a prompt or one of the first-boot dialogs.
	consoleWindowBytes = 8192

	pressReturnPrompt = "Press RETURN to get started"
	setupDialogPrompt = "initial configuration dialog"
)

var (
	// consolePromptPattern matches an IOS or VPCS prompt line: "R1#",
	// "R1(config-if)#", "VPCS>". It is matched against a window of console
	// output with control sequences removed.
	consolePromptPattern = regexp.MustCompile(`(?m)[\w.@/-]+(\([^)]*\))?[#>]\s*$`)
	// consoleEscapePattern strips ANSI control sequences from console output.
	consoleEscapePattern = regexp.MustCompile(`\x1b\[[0-9;?]*[A-Za-z]`)
)

// consoleReader is an internal console viewer. It replays a node's history,
// follows live output and can write command bursts back to the engine. It is
// used only by the agent's own probes; client consoles keep their own
// PTYSession and both receive the same stream.
type consoleReader struct {
	node    *Node
	session *PTYSession

	chunks chan []byte
	closed chan struct{}
	once   sync.Once
	tail   []byte

	answeredReturn bool
	answeredSetup  bool
}

// newConsoleReader attaches an internal session to a running node.
func (m *Manager) newConsoleReader(node *Node) (*consoleReader, error) {
	session, ok := m.AttachPTY(node.Spec.ID)
	if !ok {
		return nil, errors.New("node console is not available")
	}

	reader := &consoleReader{
		node:    node,
		session: session,
		chunks:  make(chan []byte, 128),
		closed:  make(chan struct{}),
	}
	go reader.read()

	return reader, nil
}

func (r *consoleReader) read() {
	defer close(r.chunks)

	buffer := make([]byte, 4096)
	for {
		count, err := r.session.Read(buffer)
		if count > 0 {
			chunk := append([]byte(nil), buffer[:count]...)
			select {
			case r.chunks <- chunk:
			case <-r.closed:
				return
			}
		}
		if err != nil {
			return
		}
	}
}

func (r *consoleReader) close() {
	r.once.Do(func() {
		close(r.closed)
		_ = r.session.Close()
	})
}

// text is the console window with escapes and carriage returns removed, so a
// prompt line can be matched across chunk boundaries.
func (r *consoleReader) text() string {
	text := consoleEscapePattern.ReplaceAllString(string(r.tail), "")
	text = strings.ReplaceAll(text, "\r\n", "\n")

	return strings.ReplaceAll(text, "\r", "")
}

func (r *consoleReader) push(chunk []byte) {
	r.tail = append(r.tail, chunk...)
	if len(r.tail) > consoleWindowBytes {
		r.tail = r.tail[len(r.tail)-consoleWindowBytes:]
	}
}

// write sends one burst. The session serialises it against user input through
// the node write mutex.
func (r *consoleReader) write(command string) error {
	if _, err := r.session.Write([]byte(command)); err != nil {
		return err
	}

	return nil
}

// awaitPrompt reads console output until a prompt line appears or the timeout
// expires. IOL first-boot dialogs are answered while it waits.
func (r *consoleReader) awaitPrompt(timeout time.Duration) bool {
	timer := time.NewTimer(timeout)
	defer timer.Stop()

	for {
		if consolePromptSeen(r.text()) {
			return true
		}
		select {
		case chunk, ok := <-r.chunks:
			if !ok {
				return consolePromptSeen(r.text())
			}
			r.push(chunk)
			r.answerDialogs()
		case <-timer.C:
			return consolePromptSeen(r.text())
		}
	}
}

// discard forgets buffered output so the next awaitPrompt only matches the
// response to the command that follows.
func (r *consoleReader) discard() {
	r.tail = r.tail[:0]
	for {
		select {
		case _, ok := <-r.chunks:
			if !ok {
				return
			}
		default:
			return
		}
	}
}

// send replays one command line and waits briefly for the prompt that follows.
func (r *consoleReader) send(command string, timeout time.Duration) bool {
	r.discard()
	if err := r.write(command + "\r"); err != nil {
		return false
	}

	return r.awaitPrompt(timeout)
}

// answerDialogs answers the IOL first-boot prompts once each. A write error
// ends the probe through the next read, so it is ignored here.
func (r *consoleReader) answerDialogs() {
	text := r.text()
	if !r.answeredReturn && strings.Contains(text, pressReturnPrompt) {
		r.answeredReturn = true
		_ = r.write("\r")
	}
	if !r.answeredSetup && strings.Contains(strings.ToLower(text), setupDialogPrompt) {
		r.answeredSetup = true
		_ = r.write("no\r")
	}
}

// consolePromptSeen reports whether a device prompt is the last visible line.
func consolePromptSeen(text string) bool {
	return consolePromptPattern.MatchString(text)
}

// startConsoleProbe follows a node console in the background: it answers the
// IOL first-boot dialogs, marks the node ready once a prompt appears, then runs
// the optional post-ready step. A timeout only logs a warning; node starts
// never depend on the guest.
func (m *Manager) startConsoleProbe(node *Node, afterReady func(*consoleReader)) {
	go func() {
		reader, err := m.newConsoleReader(node)
		if err != nil {
			m.logger.Debug("console probe skipped", "node", node.Spec.ID, "reason", err)
			return
		}
		defer reader.close()

		if !reader.awaitPrompt(consoleProbeBudget) {
			m.logger.Warn("console prompt not seen within the probe budget; node stays not ready",
				"node", node.Spec.ID, "budget", consoleProbeBudget)
			return
		}
		node.Ready.Store(true)
		m.logger.Info("node console ready", "node", node.Spec.ID)
		if afterReady != nil {
			afterReady(reader)
		}
	}()
}
