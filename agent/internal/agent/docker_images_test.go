// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package agent

import (
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/SavEvens/TeraLab/agent/internal/config"
	"github.com/SavEvens/TeraLab/agent/internal/engine"
)

// fakeAgentDocker writes a docker script for handler tests. The agent tests
// only need the fixed image ls argv, so the script body decides the outcome.
func fakeAgentDocker(t *testing.T, body string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "docker")
	script := "#!/bin/sh\n" + body + "\n"
	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatalf("write fake docker: %v", err)
	}

	return path
}

func newDockerImagesServer(binary string) *Server {
	return New(
		config.Config{DockerBinary: binary},
		slog.New(slog.NewTextHandler(io.Discard, nil)),
	)
}

func TestHandleDockerImages(t *testing.T) {
	successBody := `if [ "$1" = image ] && [ "$2" = ls ]; then
cat <<'OUT'
frrouting/frr|v8.4.1|sha256:fff|158MB|2026-11-21 09:05:00 +0000 UTC
<none>|<none>|sha256:dangling|0B|2026-11-21 08:00:00 +0000 UTC
alpine|3.20|sha256:aaa|7.81MB|2026-11-21 09:00:00 +0000 UTC
alpine|3.20|sha256:dup|7.81MB|2026-11-21 09:00:00 +0000 UTC
OUT
exit 0
fi
exit 0`

	cases := []struct {
		name       string
		binary     string
		wantStatus int
		wantCode   string
		wantRefs   []string
		wantRaw    string
	}{
		{
			name:       "sorted and deduplicated list",
			binary:     fakeAgentDocker(t, successBody),
			wantStatus: http.StatusOK,
			wantRefs:   []string{"alpine:3.20", "frrouting/frr:v8.4.1"},
		},
		{
			name:       "empty list",
			binary:     fakeAgentDocker(t, "exit 0"),
			wantStatus: http.StatusOK,
			wantRefs:   []string{},
			wantRaw:    `"images":[]`,
		},
		{
			name: "daemon unreachable",
			binary: fakeAgentDocker(t, `echo "Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?" >&2
exit 1`),
			wantStatus: http.StatusServiceUnavailable,
			wantCode:   "engine_unavailable",
			wantRaw:    "daemon",
		},
		{
			name:       "binary missing",
			binary:     filepath.Join(t.TempDir(), "docker"),
			wantStatus: http.StatusServiceUnavailable,
			wantCode:   "engine_unavailable",
			wantRaw:    "docker is not installed",
		},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			server := newDockerImagesServer(testCase.binary)
			request := httptest.NewRequest(http.MethodGet, "/v1/docker/images", nil)
			recorder := httptest.NewRecorder()

			server.handleDockerImages(recorder, request)

			if recorder.Code != testCase.wantStatus {
				t.Fatalf("status = %d, want %d (body %s)", recorder.Code, testCase.wantStatus, recorder.Body.String())
			}
			raw := recorder.Body.String()
			if testCase.wantRaw != "" && !strings.Contains(raw, testCase.wantRaw) {
				t.Fatalf("body %q does not contain %q", raw, testCase.wantRaw)
			}
			var payload struct {
				OK     bool `json:"ok"`
				Result struct {
					Images []engine.DockerImage `json:"images"`
				} `json:"result"`
				Error *apiError `json:"error"`
			}
			if err := json.Unmarshal([]byte(raw), &payload); err != nil {
				t.Fatalf("decode response: %v", err)
			}
			if testCase.wantCode != "" {
				if payload.OK || payload.Error == nil || payload.Error.Code != testCase.wantCode {
					t.Fatalf("error = %#v, want code %q", payload.Error, testCase.wantCode)
				}
				return
			}
			if !payload.OK {
				t.Fatalf("response not ok: %s", raw)
			}
			references := make([]string, 0, len(payload.Result.Images))
			for _, image := range payload.Result.Images {
				references = append(references, image.Reference)
			}
			if !slices.Equal(references, testCase.wantRefs) {
				t.Fatalf("references = %#v, want %#v", references, testCase.wantRefs)
			}
		})
	}
}

// The endpoint is registered behind the same bearer/peer-UID middleware as the
// other routes: an unauthenticated request must be rejected, not treated as an
// unknown path.
func TestDockerImagesRouteRequiresAuth(t *testing.T) {
	server := newDockerImagesServer("")
	request := httptest.NewRequest(http.MethodGet, "/v1/docker/images", nil)
	recorder := httptest.NewRecorder()

	server.routes().ServeHTTP(recorder, request)

	if recorder.Code != http.StatusUnauthorized {
		t.Fatalf("status = %d, want 401 (body %s)", recorder.Code, recorder.Body.String())
	}
	if !strings.Contains(recorder.Body.String(), `"code":"unauthorized"`) {
		t.Fatalf("unexpected body: %s", recorder.Body.String())
	}
}
