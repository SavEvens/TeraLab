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
