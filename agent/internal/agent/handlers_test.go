// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package agent

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"slices"
	"strconv"
	"strings"
	"testing"

	"github.com/SavEvens/TeraLab/agent/internal/engine"
)

// The console endpoint must never carry the capability in the URL. The token
// travels in Sec-WebSocket-Protocol; only the bare path is exposed so proxy
// access logs and browser history cannot capture it.
func TestConsoleWebSocketURLHasNoToken(t *testing.T) {
	tests := []struct {
		path string
		want string
	}{
		{path: "/ws", want: "ws://127.0.0.1:8091/ws"},
		{path: "/vnc", want: "ws://127.0.0.1:8091/vnc"},
		{path: "/tcp", want: "ws://127.0.0.1:8091/tcp"},
	}
	for _, test := range tests {
		t.Run(test.path, func(t *testing.T) {
			got := consoleWebSocketURL("127.0.0.1:8091", test.path)
			if got != test.want {
				t.Fatalf("consoleWebSocketURL = %q, want %q", got, test.want)
			}
			if strings.ContainsAny(got, "?#") {
				t.Fatalf("console WebSocket URL must not carry a query or fragment: %q", got)
			}
		})
	}
}

func TestValidConsoleUserID(t *testing.T) {
	tests := []struct {
		name string
		id   string
		want bool
	}{
		{name: "empty", id: "", want: true},
		{name: "uuid", id: "3f2504e0-4f89-11d3-9a0c-0305e82c3301", want: true},
		{name: "maximum length", id: strings.Repeat("u", 128), want: true},
		{name: "too long", id: strings.Repeat("u", 129), want: false},
		{name: "newline", id: "user\ninjected", want: false},
		{name: "nul", id: "user\x00", want: false},
		{name: "delete", id: "user\x7f", want: false},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if got := validConsoleUserID(test.id); got != test.want {
				t.Fatalf("validConsoleUserID(%q) = %v, want %v", test.id, got, test.want)
			}
		})
	}
}

// Large payloads must carry Content-Length. Without it the Go server falls
// back to chunked encoding, which the Unix-socket PHP client cannot decode.
func TestWriteJSONSetsContentLengthForLargePayloads(t *testing.T) {
	nodes := make([]map[string]any, 0, 64)
	for index := 0; index < 64; index++ {
		nodes = append(nodes, map[string]any{
			"id":     strings.Repeat("n", 48),
			"lab_id": strings.Repeat("l", 36),
			"state":  "running",
			"taps":   []string{"tl0000000000001", "tl0000000000002"},
		})
	}
	recorder := httptest.NewRecorder()
	writeJSON(recorder, http.StatusOK, apiResponse{OK: true, Result: map[string]any{"nodes": nodes}})

	body := recorder.Body.Bytes()
	if len(body) <= 2048 {
		t.Fatalf("payload should be large enough to trigger chunking, got %d bytes", len(body))
	}
	if got := recorder.Header().Get("Content-Length"); got != strconv.Itoa(len(body)) {
		t.Fatalf("content-length = %q, want %d", got, len(body))
	}
	var decoded map[string]any
	if err := json.Unmarshal(body, &decoded); err != nil {
		t.Fatalf("body is not valid JSON: %v", err)
	}
	if decoded["ok"] != true {
		t.Fatalf("unexpected payload: %v", decoded)
	}
}

func TestWriteJSONEncodesErrors(t *testing.T) {
	recorder := httptest.NewRecorder()
	writeJSON(recorder, http.StatusNotFound, apiResponse{OK: false, Error: &apiError{Code: "not_found", Message: "missing"}})

	if recorder.Code != http.StatusNotFound {
		t.Fatalf("status = %d", recorder.Code)
	}
	if !strings.Contains(recorder.Body.String(), `"code":"not_found"`) {
		t.Fatalf("unexpected body: %s", recorder.Body.String())
	}
}

// The maintenance endpoint must pass the live lab ids through to the engine
// keep-set, and must treat a missing body as "no lab pruning".
func TestServeCleanupStalePassesLabIDs(t *testing.T) {
	cases := []struct {
		name    string
		body    string
		want    []string
		wantNil bool
	}{
		{name: "absent body", body: "", wantNil: true},
		{name: "blank body", body: "\n", wantNil: true},
		{name: "lab list", body: `{"lab_ids":["lab-a","lab-b"]}`, want: []string{"lab-a", "lab-b"}},
		{name: "explicit empty list", body: `{"lab_ids":[]}`, want: []string{}},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			called := false
			var got []string
			request := httptest.NewRequest(http.MethodPost, "/maintenance/reconcile", strings.NewReader(testCase.body))
			recorder := httptest.NewRecorder()

			serveCleanupStale(recorder, request, func(labIDs []string) (engine.ReconcileResult, error) {
				called = true
				got = labIDs

				return engine.ReconcileResult{RemovedDirs: []string{"/run/lab-stale"}}, nil
			})

			if recorder.Code != http.StatusOK {
				t.Fatalf("status = %d, body = %s", recorder.Code, recorder.Body.String())
			}
			if !called {
				t.Fatal("reconcile was not called")
			}
			if (got == nil) != testCase.wantNil || !slices.Equal(got, testCase.want) {
				t.Fatalf("lab ids = %#v (nil = %t), want %#v (nil = %t)", got, got == nil, testCase.want, testCase.wantNil)
			}
			if !strings.Contains(recorder.Body.String(), "/run/lab-stale") {
				t.Fatalf("result not reported: %s", recorder.Body.String())
			}
		})
	}
}

func TestServeCleanupStaleRejectsMalformedBody(t *testing.T) {
	called := false
	request := httptest.NewRequest(http.MethodPost, "/maintenance/reconcile", strings.NewReader(`{"lab_ids":"oops"}`))
	recorder := httptest.NewRecorder()

	serveCleanupStale(recorder, request, func([]string) (engine.ReconcileResult, error) {
		called = true

		return engine.ReconcileResult{}, nil
	})

	if recorder.Code != http.StatusBadRequest {
		t.Fatalf("status = %d, want 400", recorder.Code)
	}
	if called {
		t.Fatal("reconcile must not run for a malformed body")
	}
	if !strings.Contains(recorder.Body.String(), `"code":"invalid_request"`) {
		t.Fatalf("unexpected body: %s", recorder.Body.String())
	}
}
