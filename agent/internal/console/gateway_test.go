// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package console

import (
	"bytes"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/token"
	"github.com/gorilla/websocket"
)

func TestParseResizeFrame(t *testing.T) {
	tests := []struct {
		name     string
		frame    []byte
		wantOK   bool
		wantCols int
		wantRows int
	}{
		{
			name:     "valid frame",
			frame:    []byte(`{"type":"resize","cols":120,"rows":40}`),
			wantOK:   true,
			wantCols: 120,
			wantRows: 40,
		},
		{
			name:     "valid frame with reordered keys",
			frame:    []byte(`{"rows":40,"type":"resize","cols":120}`),
			wantOK:   true,
			wantCols: 120,
			wantRows: 40,
		},
		{
			name:     "valid frame with insignificant whitespace",
			frame:    []byte("{\n  \"type\": \"resize\",\n  \"cols\": 80,\n  \"rows\": 24\n}"),
			wantOK:   true,
			wantCols: 80,
			wantRows: 24,
		},
		{
			name:     "valid minimum dimensions",
			frame:    []byte(`{"type":"resize","cols":1,"rows":1}`),
			wantOK:   true,
			wantCols: 1,
			wantRows: 1,
		},
		{
			name:     "valid maximum dimensions",
			frame:    []byte(`{"type":"resize","cols":1024,"rows":256}`),
			wantOK:   true,
			wantCols: 1024,
			wantRows: 256,
		},
		{name: "binary frame", frame: []byte{0x00, 0x01, 0xff, 0x7f, 0x10}},
		{name: "empty frame", frame: []byte{}},
		{name: "plain console text", frame: []byte("show version\r\n")},
		{name: "malformed json", frame: []byte(`{"type":"resize","cols":80,`)},
		{name: "json array", frame: []byte(`[{"type":"resize","cols":80,"rows":24}]`)},
		{name: "wrong type value", frame: []byte(`{"type":"ping","cols":80,"rows":24}`)},
		{name: "non string type", frame: []byte(`{"type":1,"cols":80,"rows":24}`)},
		{name: "null type", frame: []byte(`{"type":null,"cols":80,"rows":24}`)},
		{name: "extra key", frame: []byte(`{"type":"resize","cols":80,"rows":24,"extra":true}`)},
		{name: "missing rows", frame: []byte(`{"type":"resize","cols":80}`)},
		{name: "case shifted keys", frame: []byte(`{"Type":"resize","Cols":80,"Rows":24}`)},
		{name: "cols below range", frame: []byte(`{"type":"resize","cols":0,"rows":24}`)},
		{name: "cols above range", frame: []byte(`{"type":"resize","cols":1025,"rows":24}`)},
		{name: "rows below range", frame: []byte(`{"type":"resize","cols":80,"rows":0}`)},
		{name: "rows above range", frame: []byte(`{"type":"resize","cols":80,"rows":257}`)},
		{name: "negative cols", frame: []byte(`{"type":"resize","cols":-1,"rows":24}`)},
		{name: "floating point cols", frame: []byte(`{"type":"resize","cols":80.5,"rows":24}`)},
		{name: "string cols", frame: []byte(`{"type":"resize","cols":"80","rows":24}`)},
		{name: "null cols", frame: []byte(`{"type":"resize","cols":null,"rows":24}`)},
		{
			name:  "concatenated frames",
			frame: []byte(`{"type":"resize","cols":80,"rows":24}{"type":"resize","cols":80,"rows":24}`),
		},
		{
			name:  "trailing garbage",
			frame: []byte(`{"type":"resize","cols":80,"rows":24} x`),
		},
		{
			name:  "resize not at frame start",
			frame: []byte(`resize {"type":"resize","cols":80,"rows":24}`),
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			cols, rows, ok := parseResizeFrame(test.frame)
			if ok != test.wantOK {
				t.Fatalf("parseResizeFrame(%q) ok = %v, want %v", test.frame, ok, test.wantOK)
			}
			if !ok {
				return
			}
			if cols != test.wantCols || rows != test.wantRows {
				t.Fatalf("parseResizeFrame(%q) = %dx%d, want %dx%d", test.frame, cols, rows, test.wantCols, test.wantRows)
			}
		})
	}
}

type recordingResizeTarget struct {
	mu    sync.Mutex
	calls int
	cols  int
	rows  int
}

func (r *recordingResizeTarget) Resize(cols, rows int) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.calls++
	r.cols = cols
	r.rows = rows

	return nil
}

func (r *recordingResizeTarget) size() (calls, cols, rows int) {
	r.mu.Lock()
	defer r.mu.Unlock()

	return r.calls, r.cols, r.rows
}

func TestConsumeResizeFrameOnlySwallowsControlFrames(t *testing.T) {
	tests := []struct {
		name         string
		frame        []byte
		wantConsumed bool
		wantCalls    int
		wantCols     int
		wantRows     int
	}{
		{
			name:         "resize control frame",
			frame:        []byte(`{"type":"resize","cols":120,"rows":40}`),
			wantConsumed: true,
			wantCalls:    1,
			wantCols:     120,
			wantRows:     40,
		},
		{name: "binary frame", frame: []byte{0x00, 0x01, 0xff}},
		{name: "plain text", frame: []byte("show version\r")},
		{name: "malformed json", frame: []byte(`{"type":`)},
		{name: "wrong type", frame: []byte(`{"type":"ping","cols":80,"rows":24}`)},
		{name: "out of range", frame: []byte(`{"type":"resize","cols":4096,"rows":24}`)},
		{name: "extra key", frame: []byte(`{"type":"resize","cols":80,"rows":24,"id":1}`)},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			target := &recordingResizeTarget{}
			if consumed := consumeResizeFrame(target, test.frame); consumed != test.wantConsumed {
				t.Fatalf("consumeResizeFrame(%q) = %v, want %v", test.frame, consumed, test.wantConsumed)
			}
			calls, cols, rows := target.size()
			if calls != test.wantCalls {
				t.Fatalf("Resize called %d times, want %d", calls, test.wantCalls)
			}
			if calls > 0 && (cols != test.wantCols || rows != test.wantRows) {
				t.Fatalf("Resize got %dx%d, want %dx%d", cols, rows, test.wantCols, test.wantRows)
			}
		})
	}
}

func TestSerialBridgeConsumesResizeFrames(t *testing.T) {
	gateway := New("127.0.0.1:0", nil, nil, Options{})
	stream := newFakeStream()
	defer stream.Close()
	target := &recordingResizeTarget{}

	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		gateway.bridge(writer, request, stream, func(data []byte) bool {
			return consumeResizeFrame(target, data)
		})
	}))
	defer server.Close()

	connection := dialTestWebSocket(t, server)
	defer connection.Close()

	writeTestFrame(t, connection, websocket.TextMessage, []byte(`{"type":"resize","cols":120,"rows":40}`))
	writeTestFrame(t, connection, websocket.TextMessage, []byte("show version\r"))

	waitFor(t, func() bool {
		return string(stream.writtenBytes()) == "show version\r"
	})

	if forwarded := string(stream.writtenBytes()); strings.Contains(forwarded, "resize") {
		t.Fatalf("resize control frame reached the engine stream: %q", forwarded)
	}
	calls, cols, rows := target.size()
	if calls != 1 || cols != 120 || rows != 40 {
		t.Fatalf("Resize calls=%d size=%dx%d, want 1 call at 120x40", calls, cols, rows)
	}
}

func TestBridgeForwardsFramesWithoutInterceptor(t *testing.T) {
	gateway := New("127.0.0.1:0", nil, nil, Options{})
	stream := newFakeStream()
	defer stream.Close()

	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		gateway.bridge(writer, request, stream, nil)
	}))
	defer server.Close()

	connection := dialTestWebSocket(t, server)
	defer connection.Close()

	resizePayload := []byte(`{"type":"resize","cols":120,"rows":40}`)
	binaryPayload := []byte{0x00, 0x01, 0xff, 0x7e}
	writeTestFrame(t, connection, websocket.TextMessage, resizePayload)
	writeTestFrame(t, connection, websocket.BinaryMessage, binaryPayload)

	want := append(append([]byte(nil), resizePayload...), binaryPayload...)
	waitFor(t, func() bool {
		return len(stream.writtenBytes()) == len(want)
	})
	if got := stream.writtenBytes(); string(got) != string(want) {
		t.Fatalf("raw bridge altered frames: got %q, want %q", got, want)
	}
}

// TestBridgeRoundTripsMessages pins both directions of a live WebSocket
// bridge: an engine frame reaches the client as a binary message, a client
// frame reaches the engine stream, and closing the client ends the bridge.
func TestBridgeRoundTripsMessages(t *testing.T) {
	gateway := New("127.0.0.1:0", nil, nil, Options{})
	stream := newFakeStream()
	defer stream.Close()

	bridged := make(chan struct{})
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		defer close(bridged)
		gateway.bridge(writer, request, stream, nil)
	}))
	defer server.Close()

	connection := dialTestWebSocket(t, server)

	toClient := []byte("engine output\r\n")
	if _, err := stream.writer.Write(toClient); err != nil {
		t.Fatalf("write engine frame: %v", err)
	}
	messageType, received, err := connection.ReadMessage()
	if err != nil {
		t.Fatalf("read bridged frame: %v", err)
	}
	if messageType != websocket.BinaryMessage || !bytes.Equal(received, toClient) {
		t.Fatalf("bridged frame = %d %q, want %d %q", messageType, received, websocket.BinaryMessage, toClient)
	}

	toEngine := []byte("show version\r")
	writeTestFrame(t, connection, websocket.BinaryMessage, toEngine)
	waitFor(t, func() bool {
		return bytes.Equal(stream.writtenBytes(), toEngine)
	})
	if got := stream.writtenBytes(); !bytes.Equal(got, toEngine) {
		t.Fatalf("engine received %q, want %q", got, toEngine)
	}

	if err := connection.Close(); err != nil {
		t.Fatalf("close client: %v", err)
	}
	select {
	case <-bridged:
	case <-time.After(5 * time.Second):
		t.Fatal("bridge did not return after the client closed")
	}
}

func TestConsoleAcceptsSubprotocolToken(t *testing.T) {
	store := token.New()
	value, err := store.Issue("node-1", "user-1", time.Minute)
	if err != nil {
		t.Fatalf("issue token: %v", err)
	}
	gateway := New("127.0.0.1:0", nil, store, Options{})
	stream := newFakeStream()
	defer stream.Close()
	server := newConsoleTestServer(t, gateway, stream)

	protocol := token.SubprotocolPrefix + value
	connection, _, err := tryDial(server, "/ws", nil, []string{protocol})
	if err != nil {
		t.Fatalf("dial with subprotocol token: %v", err)
	}
	defer connection.Close()

	if got := connection.Subprotocol(); got != protocol {
		t.Fatalf("negotiated subprotocol = %q, want %q", got, protocol)
	}
	entry, ok := store.Resolve(value)
	if !ok || entry.NodeID != "node-1" || entry.UserID != "user-1" {
		t.Fatalf("token was not bound to the node/user: %#v %v", entry, ok)
	}
}

// A console tab reconnects with the same capability while it is still valid.
// Tokens are intentionally not consumed on resolve; pinning that keeps the
// existing reconnect behaviour working within the TTL.
func TestConsoleTokenAllowsReconnectWithinTTL(t *testing.T) {
	store := token.New()
	value, err := store.Issue("node-1", "", time.Minute)
	if err != nil {
		t.Fatalf("issue token: %v", err)
	}
	gateway := New("127.0.0.1:0", nil, store, Options{})
	stream := newFakeStream()
	defer stream.Close()
	server := newConsoleTestServer(t, gateway, stream)
	protocol := token.SubprotocolPrefix + value

	for attempt := 0; attempt < 2; attempt++ {
		connection, _, dialErr := tryDial(server, "/ws", nil, []string{protocol})
		if dialErr != nil {
			t.Fatalf("reconnect %d: %v", attempt, dialErr)
		}
		_ = connection.Close()
	}
}

func TestConsoleRejectsTokenInQuery(t *testing.T) {
	store := token.New()
	value, err := store.Issue("node-1", "", time.Minute)
	if err != nil {
		t.Fatalf("issue token: %v", err)
	}
	var logs bytes.Buffer
	gateway := New("127.0.0.1:0", nil, store, Options{
		Logger: slog.New(slog.NewTextHandler(&logs, &slog.HandlerOptions{Level: slog.LevelDebug})),
	})
	stream := newFakeStream()
	defer stream.Close()
	server := newConsoleTestServer(t, gateway, stream)

	_, response, dialErr := tryDial(server, "/ws?token="+value, nil, nil)
	if status := handshakeStatus(t, response, dialErr); status != http.StatusUnauthorized {
		t.Fatalf("query-token handshake status = %d, want %d", status, http.StatusUnauthorized)
	}

	recorded := logs.String()
	if strings.Contains(recorded, value) {
		t.Fatalf("console token leaked into the agent log:\n%s", recorded)
	}
	if strings.Contains(recorded, "?") || strings.Contains(recorded, "token=") {
		t.Fatalf("request query leaked into the agent log:\n%s", recorded)
	}
	if !strings.Contains(recorded, "reason=query") {
		t.Fatalf("expected the rejection reason in the log:\n%s", recorded)
	}
	if !strings.Contains(recorded, "path=/ws") {
		t.Fatalf("expected the request path (and no query) in the log:\n%s", recorded)
	}
}

func TestConsoleSubprotocolTokenNotLogged(t *testing.T) {
	store := token.New()
	value, err := store.Issue("node-1", "user-1", time.Minute)
	if err != nil {
		t.Fatalf("issue token: %v", err)
	}
	var logs bytes.Buffer
	gateway := New("127.0.0.1:0", nil, store, Options{
		Logger: slog.New(slog.NewTextHandler(&logs, &slog.HandlerOptions{Level: slog.LevelDebug})),
	})
	stream := newFakeStream()
	defer stream.Close()
	server := newConsoleTestServer(t, gateway, stream)

	connection, _, err := tryDial(server, "/ws", nil, []string{token.SubprotocolPrefix + value})
	if err != nil {
		t.Fatalf("dial with subprotocol token: %v", err)
	}
	defer connection.Close()

	recorded := logs.String()
	if strings.Contains(recorded, value) {
		t.Fatalf("console token leaked into the agent log:\n%s", recorded)
	}
	if !strings.Contains(recorded, "console token accepted") {
		t.Fatalf("expected an acceptance log line:\n%s", recorded)
	}
	if !strings.Contains(recorded, "node=node-1") || !strings.Contains(recorded, "user=user-1") {
		t.Fatalf("acceptance log should identify node and user:\n%s", recorded)
	}
}

func TestConsoleRejectsMissingOrUnknownOrigin(t *testing.T) {
	store := token.New()
	value, err := store.Issue("node-1", "", time.Minute)
	if err != nil {
		t.Fatalf("issue token: %v", err)
	}
	gateway := New("127.0.0.1:0", nil, store, Options{})
	stream := newFakeStream()
	defer stream.Close()
	server := newConsoleTestServer(t, gateway, stream)
	protocol := token.SubprotocolPrefix + value

	tests := []struct {
		name   string
		origin string
		want   int
	}{
		{name: "missing origin", origin: "", want: http.StatusForbidden},
		{name: "unknown origin", origin: "https://evil.example", want: http.StatusForbidden},
		{name: "origin with path", origin: "https://evil.example/path", want: http.StatusForbidden},
		{name: "same origin", origin: server.URL, want: 0},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			headers := http.Header{}
			if test.origin != "" {
				headers.Set("Origin", test.origin)
			} else {
				// An explicit empty Origin value is what a non-browser
				// client sends when it omits the header; tryDial only
				// defaults when the key is absent.
				headers["Origin"] = []string{""}
			}
			connection, response, dialErr := tryDial(server, "/ws", headers, []string{protocol})
			if test.want == 0 {
				if dialErr != nil {
					t.Fatalf("expected same-origin handshake to succeed: %v", dialErr)
				}
				defer connection.Close()
				return
			}
			if status := handshakeStatus(t, response, dialErr); status != test.want {
				t.Fatalf("handshake status = %d, want %d", status, test.want)
			}
		})
	}
}

func TestConsoleAcceptsConfiguredAllowlist(t *testing.T) {
	store := token.New()
	value, err := store.Issue("node-1", "", time.Minute)
	if err != nil {
		t.Fatalf("issue token: %v", err)
	}
	gateway := New("127.0.0.1:0", nil, store, Options{
		AllowedOrigins: []string{"https://lab.example", "https://alt.example:8443"},
	})
	stream := newFakeStream()
	defer stream.Close()
	server := newConsoleTestServer(t, gateway, stream)
	protocol := token.SubprotocolPrefix + value

	tests := []struct {
		name   string
		origin string
		want   int
	}{
		{name: "first allowlist entry", origin: "https://lab.example", want: 0},
		{name: "second allowlist entry", origin: "https://alt.example:8443", want: 0},
		{name: "default port canonical", origin: "https://lab.example:443", want: 0},
		{name: "unknown origin", origin: "https://evil.example", want: http.StatusForbidden},
		{name: "same-origin is replaced by the list", origin: server.URL, want: http.StatusForbidden},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			headers := http.Header{"Origin": []string{test.origin}}
			connection, response, dialErr := tryDial(server, "/ws", headers, []string{protocol})
			if test.want == 0 {
				if dialErr != nil {
					t.Fatalf("expected allowlisted handshake to succeed: %v", dialErr)
				}
				defer connection.Close()
				return
			}
			if status := handshakeStatus(t, response, dialErr); status != test.want {
				t.Fatalf("handshake status = %d, want %d", status, test.want)
			}
		})
	}
}

func TestConsoleRejectsExpiredToken(t *testing.T) {
	store := token.New()
	value, err := store.Issue("node-1", "", time.Millisecond)
	if err != nil {
		t.Fatalf("issue token: %v", err)
	}
	time.Sleep(5 * time.Millisecond)
	gateway := New("127.0.0.1:0", nil, store, Options{})
	stream := newFakeStream()
	defer stream.Close()
	server := newConsoleTestServer(t, gateway, stream)

	_, response, dialErr := tryDial(server, "/ws", nil, []string{token.SubprotocolPrefix + value})
	if status := handshakeStatus(t, response, dialErr); status != http.StatusUnauthorized {
		t.Fatalf("expired-token handshake status = %d, want %d", status, http.StatusUnauthorized)
	}
}

func TestConsoleRejectsMalformedOrAmbiguousSubprotocol(t *testing.T) {
	store := token.New()
	value, err := store.Issue("node-1", "", time.Minute)
	if err != nil {
		t.Fatalf("issue token: %v", err)
	}
	gateway := New("127.0.0.1:0", nil, store, Options{})
	stream := newFakeStream()
	defer stream.Close()
	server := newConsoleTestServer(t, gateway, stream)

	tests := []struct {
		name      string
		protocols []string
	}{
		{name: "missing", protocols: nil},
		{name: "unrelated", protocols: []string{"chat"}},
		{name: "empty token", protocols: []string{token.SubprotocolPrefix}},
		{name: "short token", protocols: []string{token.SubprotocolPrefix + "abc"}},
		{name: "non-hex token", protocols: []string{token.SubprotocolPrefix + strings.Repeat("z", token.EncodedLength)}},
		{name: "two tokens", protocols: []string{
			token.SubprotocolPrefix + value,
			token.SubprotocolPrefix + strings.Repeat("a", token.EncodedLength),
		}},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			_, response, dialErr := tryDial(server, "/ws", nil, test.protocols)
			if status := handshakeStatus(t, response, dialErr); status != http.StatusUnauthorized {
				t.Fatalf("handshake status = %d, want %d", status, http.StatusUnauthorized)
			}
		})
	}
}

type fakeStream struct {
	reader io.ReadCloser
	writer io.WriteCloser

	mu      sync.Mutex
	written []byte
}

func newFakeStream() *fakeStream {
	reader, writer := io.Pipe()

	return &fakeStream{reader: reader, writer: writer}
}

func (s *fakeStream) Read(buffer []byte) (int, error) {
	return s.reader.Read(buffer)
}

func (s *fakeStream) Write(buffer []byte) (int, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.written = append(s.written, buffer...)

	return len(buffer), nil
}

func (s *fakeStream) Close() error {
	_ = s.writer.Close()

	return s.reader.Close()
}

func (s *fakeStream) writtenBytes() []byte {
	s.mu.Lock()
	defer s.mu.Unlock()

	return append([]byte(nil), s.written...)
}

func dialTestWebSocket(t *testing.T, server *httptest.Server) *websocket.Conn {
	t.Helper()

	connection, _, err := tryDial(server, "/", nil, nil)
	if err != nil {
		t.Fatalf("dial test websocket: %v", err)
	}

	return connection
}

// tryDial opens a WebSocket to server with a same-origin Origin header by
// default. Tests that exercise the origin policy pass an explicit header.
func tryDial(
	server *httptest.Server,
	path string,
	headers http.Header,
	protocols []string,
) (*websocket.Conn, *http.Response, error) {
	url := "ws" + strings.TrimPrefix(server.URL, "http") + path
	requestHeaders := http.Header{}
	for key, values := range headers {
		requestHeaders[key] = values
	}
	if _, present := requestHeaders["Origin"]; !present {
		requestHeaders.Set("Origin", server.URL)
	}
	dialer := *websocket.DefaultDialer
	dialer.Subprotocols = protocols

	return dialer.Dial(url, requestHeaders)
}

func newConsoleTestServer(t *testing.T, gateway *Gateway, stream io.ReadWriteCloser) *httptest.Server {
	t.Helper()

	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		_, protocol, ok := gateway.authorizeConsole(writer, request)
		if !ok {
			return
		}
		gateway.bridgeProtocol(writer, request, stream, nil, protocol)
	}))
	t.Cleanup(server.Close)

	return server
}

func handshakeStatus(t *testing.T, response *http.Response, err error) int {
	t.Helper()
	if err == nil {
		t.Fatal("expected the handshake to fail")
	}
	if response == nil {
		t.Fatalf("handshake failed without an HTTP response: %v", err)
	}
	t.Cleanup(func() { _ = response.Body.Close() })

	return response.StatusCode
}

func writeTestFrame(t *testing.T, connection *websocket.Conn, messageType int, payload []byte) {
	t.Helper()

	if err := connection.WriteMessage(messageType, payload); err != nil {
		t.Fatalf("write test frame: %v", err)
	}
}

func waitFor(t *testing.T, condition func() bool) {
	t.Helper()

	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if condition() {
			return
		}
		time.Sleep(5 * time.Millisecond)
	}

	t.Fatal("condition not met before deadline")
}
