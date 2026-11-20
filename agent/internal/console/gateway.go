// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package console

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"strconv"
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/engine"
	"github.com/SavEvens/TeraLab/agent/internal/token"
	"github.com/gorilla/websocket"
)

type Gateway struct {
	addr     string
	manager  *engine.Manager
	tokens   *token.Store
	listener net.Listener
	server   *http.Server
	upgrader websocket.Upgrader
}

func New(addr string, manager *engine.Manager, tokens *token.Store) *Gateway {
	return &Gateway{
		addr:    addr,
		manager: manager,
		tokens:  tokens,
		upgrader: websocket.Upgrader{
			ReadBufferSize:  4096,
			WriteBufferSize: 4096,
			CheckOrigin: func(request *http.Request) bool {
				return true
			},
		},
	}
}

func (g *Gateway) Start() error {
	host, _, err := net.SplitHostPort(g.addr)
	if err != nil {
		return err
	}
	if host != "127.0.0.1" && host != "localhost" {
		return errors.New("console gateway must bind to loopback")
	}

	listener, err := net.Listen("tcp", g.addr)
	if err != nil {
		return err
	}
	g.listener = listener

	mux := http.NewServeMux()
	mux.HandleFunc("/ws", g.handleSerial)
	mux.HandleFunc("/vnc", g.handleVNC)
	mux.HandleFunc("/tcp", g.handleTCP)
	g.server = &http.Server{
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
		IdleTimeout:       60 * time.Second,
	}

	go func() {
		_ = g.server.Serve(listener)
	}()

	return nil
}

func (g *Gateway) Shutdown(ctx context.Context) error {
	if g.server == nil {
		return nil
	}

	return g.server.Shutdown(ctx)
}

func (g *Gateway) handleSerial(writer http.ResponseWriter, request *http.Request) {
	nodeID, ok := g.tokens.Resolve(request.URL.Query().Get("token"))
	if !ok {
		http.Error(writer, "invalid or expired console token", http.StatusUnauthorized)
		return
	}
	session, ok := g.manager.AttachPTY(nodeID)
	if !ok {
		http.Error(writer, "node is not running", http.StatusNotFound)
		return
	}
	defer session.Close()

	g.bridge(writer, request, session, func(data []byte) bool {
		return consumeResizeFrame(session, data)
	})
}

func (g *Gateway) handleVNC(writer http.ResponseWriter, request *http.Request) {
	nodeID, ok := g.tokens.Resolve(request.URL.Query().Get("token"))
	if !ok {
		http.Error(writer, "invalid or expired console token", http.StatusUnauthorized)
		return
	}
	port, _, ok := g.manager.VNC(nodeID)
	if !ok {
		http.Error(writer, "node has no vnc console", http.StatusNotFound)
		return
	}

	stream, err := net.DialTimeout("tcp", "127.0.0.1:"+strconv.Itoa(port), 5*time.Second)
	if err != nil {
		http.Error(writer, "cannot reach the vnc server", http.StatusBadGateway)
		return
	}
	defer stream.Close()

	g.bridge(writer, request, stream, nil)
}

func (g *Gateway) handleTCP(writer http.ResponseWriter, request *http.Request) {
	nodeID, ok := g.tokens.Resolve(request.URL.Query().Get("token"))
	if !ok {
		http.Error(writer, "invalid or expired console token", http.StatusUnauthorized)
		return
	}
	port, ok := g.manager.TCPConsole(nodeID)
	if !ok {
		http.Error(writer, "node has no tcp console", http.StatusNotFound)
		return
	}

	stream, err := net.DialTimeout("tcp", "127.0.0.1:"+strconv.Itoa(port), 5*time.Second)
	if err != nil {
		http.Error(writer, "cannot reach the console server", http.StatusBadGateway)
		return
	}
	defer stream.Close()

	g.bridge(writer, request, stream, nil)
}

// resizeTarget is the part of a serial console session the gateway drives.
type resizeTarget interface {
	Resize(cols, rows int) error
}

// bridge copies frames between a WebSocket and an engine stream.
//
// When consume is not nil it sees every inbound frame first and a true result
// swallows that frame. Only the serial bridge uses it, for resize control
// frames, so they are applied to the pty instead of being written into the
// device console. The VNC and TCP bridges pass nil and stay raw passthrough.
//
// The stream is owned by the caller: the pty is shared with the node and must
// survive viewer disconnects, while TCP streams are closed by their handlers.
func (g *Gateway) bridge(
	writer http.ResponseWriter,
	request *http.Request,
	stream io.ReadWriteCloser,
	consume func([]byte) bool,
) {
	connection, err := g.upgrader.Upgrade(writer, request, nil)
	if err != nil {
		return
	}
	defer connection.Close()

	done := make(chan struct{})
	go func() {
		defer close(done)
		defer func() { _ = connection.Close() }()
		buffer := make([]byte, 8192)
		for {
			count, readErr := stream.Read(buffer)
			if readErr != nil {
				return
			}
			if writeErr := connection.WriteMessage(websocket.BinaryMessage, buffer[:count]); writeErr != nil {
				return
			}
		}
	}()

	for {
		_, data, readErr := connection.ReadMessage()
		if readErr != nil {
			return
		}
		if consume != nil && consume(data) {
			continue
		}
		if _, writeErr := stream.Write(data); writeErr != nil {
			return
		}
	}
}

// parseResizeFrame recognises a resize control frame: a JSON object with
// exactly the keys "type", "cols" and "rows", where "type" is the string
// "resize" and both dimensions are integer values inside the console bounds.
// Binary traffic, plain text, malformed or differently shaped JSON, extra
// keys, wrong types, and out-of-range values are all rejected so the caller
// forwards the frame untouched.
func parseResizeFrame(data []byte) (cols, rows int, ok bool) {
	if len(data) == 0 || data[0] != '{' {
		return 0, 0, false
	}

	decoder := json.NewDecoder(bytes.NewReader(data))
	var fields map[string]json.RawMessage
	if err := decoder.Decode(&fields); err != nil {
		return 0, 0, false
	}
	// Reject anything after the object, e.g. two concatenated frames.
	if _, err := decoder.Token(); !errors.Is(err, io.EOF) {
		return 0, 0, false
	}
	if len(fields) != 3 {
		return 0, 0, false
	}

	var frameType string
	if err := json.Unmarshal(fields["type"], &frameType); err != nil || frameType != "resize" {
		return 0, 0, false
	}
	if err := json.Unmarshal(fields["cols"], &cols); err != nil {
		return 0, 0, false
	}
	if err := json.Unmarshal(fields["rows"], &rows); err != nil {
		return 0, 0, false
	}
	if cols < 1 || cols > 1024 || rows < 1 || rows > 256 {
		return 0, 0, false
	}

	return cols, rows, true
}

// consumeResizeFrame applies a recognised resize control frame and reports
// whether the caller must not forward it. A frame that does not match the
// strict shape is never touched, so console traffic stays byte-for-byte.
func consumeResizeFrame(target resizeTarget, data []byte) bool {
	cols, rows, ok := parseResizeFrame(data)
	if !ok {
		return false
	}
	_ = target.Resize(cols, rows)

	return true
}
