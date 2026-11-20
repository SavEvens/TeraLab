// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package agent

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
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
