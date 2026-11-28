// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package console

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/engine"
	"github.com/SavEvens/TeraLab/agent/internal/origin"
	"github.com/SavEvens/TeraLab/agent/internal/token"
	"github.com/gorilla/websocket"
)

// cleanupInterval is how often the gateway sweeps expired console tokens even
// when no handshake arrives.
const cleanupInterval = 30 * time.Second

// Options carries the gateway's browser-facing policy. The zero value keeps
// the same-origin default and discards log records.
type Options struct {
	// AllowedOrigins lists scheme://host[:port] values accepted in the
	// Origin header. Empty means same-origin only.
	AllowedOrigins []string
	// Logger receives handshake decisions and cleanup errors. Tokens are
	// never included in log records.
	Logger *slog.Logger
}

type Gateway struct {
	addr           string
	manager        *engine.Manager
	tokens         *token.Store
	listener       net.Listener
	server         *http.Server
	upgrader       websocket.Upgrader
	allowedOrigins []string
	logger         *slog.Logger
	cleanupStop    chan struct{}
}

func New(addr string, manager *engine.Manager, tokens *token.Store, options Options) *Gateway {
	logger := options.Logger
	if logger == nil {
		logger = slog.New(slog.NewTextHandler(io.Discard, nil))
	}
	gateway := &Gateway{
		addr:           addr,
		manager:        manager,
		tokens:         tokens,
		allowedOrigins: append([]string(nil), options.AllowedOrigins...),
		logger:         logger,
	}
	gateway.upgrader = websocket.Upgrader{
		ReadBufferSize:  4096,
		WriteBufferSize: 4096,
		CheckOrigin:     gateway.checkOrigin,
	}

	return gateway
}

// checkOrigin enforces the configured browser-origin allowlist. A missing
// Origin is rejected: browsers always send it on a WebSocket handshake, and
// non-browser clients must not bypass the same-origin policy by omission.
func (g *Gateway) checkOrigin(request *http.Request) bool {
	return origin.Allows(g.allowedOrigins, request.Header.Get("Origin"), request.Host)
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
	g.startCleanup()

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

func (g *Gateway) startCleanup() {
	if g.tokens == nil {
		return
	}
	stop := make(chan struct{})
	g.cleanupStop = stop
	go func() {
		ticker := time.NewTicker(cleanupInterval)
		defer ticker.Stop()
		for {
			select {
			case <-ticker.C:
				g.tokens.Cleanup()
			case <-stop:
				return
			}
		}
	}()
}

func (g *Gateway) Shutdown(ctx context.Context) error {
	if g.cleanupStop != nil {
		close(g.cleanupStop)
		g.cleanupStop = nil
	}
	if g.server == nil {
		return nil
	}

	return g.server.Shutdown(ctx)
}

// authorizeConsole validates the browser origin and extracts the capability
// from the Sec-WebSocket-Protocol header. Query tokens are rejected outright;
// they end up in proxy access logs and browser history. A failure writes the
// HTTP response itself and returns false. The raw token is never logged.
func (g *Gateway) authorizeConsole(writer http.ResponseWriter, request *http.Request) (token.Token, string, bool) {
	path := request.URL.Path
	remote := request.RemoteAddr
	if !g.checkOrigin(request) {
		g.logger.Warn("console handshake rejected", "path", path, "remote", remote, "reason", "origin")
		http.Error(writer, "console origin is not allowed", http.StatusForbidden)

		return token.Token{}, "", false
	}
	if request.URL.RawQuery != "" {
		g.logger.Warn("console handshake rejected", "path", path, "remote", remote, "reason", "query")
		http.Error(writer, "console tokens must be sent in the Sec-WebSocket-Protocol header, not the URL", http.StatusUnauthorized)

		return token.Token{}, "", false
	}
	value, protocol, ok := consoleSubprotocol(request)
	if !ok {
		g.logger.Warn("console handshake rejected", "path", path, "remote", remote, "reason", "subprotocol")
		http.Error(writer, "missing or ambiguous console WebSocket subprotocol", http.StatusUnauthorized)

		return token.Token{}, "", false
	}
	if g.tokens == nil {
		g.logger.Warn("console handshake rejected", "path", path, "remote", remote, "reason", "token_store")
		http.Error(writer, "invalid or expired console token", http.StatusUnauthorized)

		return token.Token{}, "", false
	}
	entry, ok := g.tokens.Resolve(value)
	if !ok {
		g.logger.Warn("console handshake rejected", "path", path, "remote", remote, "reason", "token")
		http.Error(writer, "invalid or expired console token", http.StatusUnauthorized)

		return token.Token{}, "", false
	}
	g.logger.Info("console token accepted", "path", path, "remote", remote, "node", entry.NodeID, "user", entry.UserID)

	return entry, protocol, true
}

// consoleSubprotocol extracts exactly one teralab.console.<token> value from
// the requested subprotocols. Multiple matching values are treated as
// ambiguous rather than silently picking one.
func consoleSubprotocol(request *http.Request) (value, protocol string, ok bool) {
	for _, header := range request.Header.Values("Sec-WebSocket-Protocol") {
		for _, part := range strings.Split(header, ",") {
			candidate := strings.TrimSpace(part)
			if !strings.HasPrefix(candidate, token.SubprotocolPrefix) {
				continue
			}
			if protocol != "" {
				return "", "", false
			}
			protocol = candidate
			value = strings.TrimPrefix(candidate, token.SubprotocolPrefix)
		}
	}
	if protocol == "" || !validTokenValue(value) {
		return "", "", false
	}

	return value, protocol, true
}

func validTokenValue(value string) bool {
	if len(value) != token.EncodedLength {
		return false
	}
	for index := 0; index < len(value); index++ {
		character := value[index]
		if (character < '0' || character > '9') && (character < 'a' || character > 'f') {
			return false
		}
	}

	return true
}

func (g *Gateway) handleSerial(writer http.ResponseWriter, request *http.Request) {
	entry, protocol, ok := g.authorizeConsole(writer, request)
	if !ok {
		return
	}
	session, ok := g.manager.AttachPTY(entry.NodeID)
	if !ok {
		http.Error(writer, "node is not running", http.StatusNotFound)
		return
	}
	defer session.Close()

	g.bridgeProtocol(writer, request, session, func(data []byte) bool {
		return consumeResizeFrame(session, data)
	}, protocol)
}

func (g *Gateway) handleVNC(writer http.ResponseWriter, request *http.Request) {
	entry, protocol, ok := g.authorizeConsole(writer, request)
	if !ok {
		return
	}
	port, _, ok := g.manager.VNC(entry.NodeID)
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

	g.bridgeProtocol(writer, request, stream, nil, protocol)
}

func (g *Gateway) handleTCP(writer http.ResponseWriter, request *http.Request) {
	entry, protocol, ok := g.authorizeConsole(writer, request)
	if !ok {
		return
	}
	port, ok := g.manager.TCPConsole(entry.NodeID)
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

	g.bridgeProtocol(writer, request, stream, nil, protocol)
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
	g.bridgeProtocol(writer, request, stream, consume, "")
}

// bridgeProtocol is bridge with a selected WebSocket subprotocol. The value
// must be one the client offered; gorilla echoes it in the handshake response,
// which browsers require when a subprotocol was requested.
func (g *Gateway) bridgeProtocol(
	writer http.ResponseWriter,
	request *http.Request,
	stream io.ReadWriteCloser,
	consume func([]byte) bool,
	subprotocol string,
) {
	upgrader := g.upgrader
	if subprotocol != "" {
		upgrader.Subprotocols = []string{subprotocol}
	}
	connection, err := upgrader.Upgrade(writer, request, nil)
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
