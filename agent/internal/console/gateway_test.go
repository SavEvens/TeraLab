// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package console

import (
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

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
	gateway := New("127.0.0.1:0", nil, nil)
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
	gateway := New("127.0.0.1:0", nil, nil)
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

	url := "ws" + strings.TrimPrefix(server.URL, "http")
	connection, _, err := websocket.DefaultDialer.Dial(url, nil)
	if err != nil {
		t.Fatalf("dial test websocket: %v", err)
	}

	return connection
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
