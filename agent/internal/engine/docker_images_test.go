// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"context"
	"errors"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

func TestParseDockerImages(t *testing.T) {
	cases := []struct {
		name   string
		output string
		want   []DockerImage
	}{
		{
			name: "dangling skipped",
			output: `<none>|<none>|sha256:dangling|0B|2026-11-21 08:00:00 +0000 UTC
alpine|3.20|sha256:aaa|7.81MB|2026-11-21 09:00:00 +0000 UTC`,
			want: []DockerImage{
				{Reference: "alpine:3.20", ID: "sha256:aaa", Size: "7.81MB", Created: "2026-11-21 09:00:00 +0000 UTC"},
			},
		},
		{
			name: "sorts by reference",
			output: `frrouting/frr|v8.4.1|sha256:fff|158MB|2026-11-21 09:05:00 +0000 UTC
alpine|3.20|sha256:aaa|7.81MB|2026-11-21 09:00:00 +0000 UTC
busybox|1.36|sha256:bbb|4.26MB|2026-11-21 09:01:00 +0000 UTC`,
			want: []DockerImage{
				{Reference: "alpine:3.20", ID: "sha256:aaa", Size: "7.81MB", Created: "2026-11-21 09:00:00 +0000 UTC"},
				{Reference: "busybox:1.36", ID: "sha256:bbb", Size: "4.26MB", Created: "2026-11-21 09:01:00 +0000 UTC"},
				{Reference: "frrouting/frr:v8.4.1", ID: "sha256:fff", Size: "158MB", Created: "2026-11-21 09:05:00 +0000 UTC"},
			},
		},
		{
			name: "deduplicates by reference keeping the first",
			output: `alpine|3.20|sha256:first|7.81MB|2026-11-21 09:00:00 +0000 UTC
alpine|3.20|sha256:second|7.81MB|2026-11-21 09:00:00 +0000 UTC`,
			want: []DockerImage{
				{Reference: "alpine:3.20", ID: "sha256:first", Size: "7.81MB", Created: "2026-11-21 09:00:00 +0000 UTC"},
			},
		},
		{
			name:   "registry port is not a tag separator",
			output: `localhost:5000/frr|8.4.1|sha256:port|158MB|2026-11-21 09:00:00 +0000 UTC`,
			want: []DockerImage{
				{Reference: "localhost:5000/frr:8.4.1", ID: "sha256:port", Size: "158MB", Created: "2026-11-21 09:00:00 +0000 UTC"},
			},
		},
		{
			name: "malformed and blank lines ignored",
			output: `not-enough-columns
alpine|3.20|sha256:aaa|7.81MB|2026-11-21 09:00:00 +0000 UTC

`,
			want: []DockerImage{
				{Reference: "alpine:3.20", ID: "sha256:aaa", Size: "7.81MB", Created: "2026-11-21 09:00:00 +0000 UTC"},
			},
		},
		{name: "empty output", output: "", want: []DockerImage{}},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			got := parseDockerImages([]byte(testCase.output))
			if got == nil {
				t.Fatal("parseDockerImages must return a non-nil slice so the API renders [] not null")
			}
			if !slices.Equal(got, testCase.want) {
				t.Fatalf("images = %#v, want %#v", got, testCase.want)
			}
		})
	}
}

func TestDockerImagesListsLocalImages(t *testing.T) {
	body := `if [ "$1" = image ] && [ "$2" = ls ]; then
cat <<'OUT'
frrouting/frr|v8.4.1|sha256:fff|158MB|2026-11-21 09:05:00 +0000 UTC
<none>|<none>|sha256:dangling|0B|2026-11-21 08:00:00 +0000 UTC
alpine|3.20|sha256:aaa|7.81MB|2026-11-21 09:00:00 +0000 UTC
OUT
exit 0
fi
exit 0`
	binary, logPath := fakeDocker(t, body)
	manager := newDockerTestManager(t, binary)

	images, err := manager.DockerImages(context.Background())
	if err != nil {
		t.Fatalf("list docker images: %v", err)
	}
	want := []DockerImage{
		{Reference: "alpine:3.20", ID: "sha256:aaa", Size: "7.81MB", Created: "2026-11-21 09:00:00 +0000 UTC"},
		{Reference: "frrouting/frr:v8.4.1", ID: "sha256:fff", Size: "158MB", Created: "2026-11-21 09:05:00 +0000 UTC"},
	}
	if !slices.Equal(images, want) {
		t.Fatalf("images = %#v, want %#v", images, want)
	}
	calls := dockerCalls(t, logPath)
	if !callsContain(calls, "image ls --no-trunc --format "+dockerImagesFormat) {
		t.Fatalf("fixed image listing argv was not used: %v", calls)
	}
}

func TestDockerImagesEmptyOutputIsEmptyList(t *testing.T) {
	binary, _ := fakeDocker(t, "exit 0")
	manager := newDockerTestManager(t, binary)

	images, err := manager.DockerImages(context.Background())
	if err != nil {
		t.Fatalf("list docker images: %v", err)
	}
	if images == nil || len(images) != 0 {
		t.Fatalf("images = %#v, want an empty non-nil slice", images)
	}
}

func TestDockerImagesDaemonFailureIsUnavailable(t *testing.T) {
	binary, _ := fakeDocker(t, `echo "Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?" >&2
exit 1`)
	manager := newDockerTestManager(t, binary)

	images, err := manager.DockerImages(context.Background())
	if err == nil {
		t.Fatalf("expected a daemon failure, got images %#v", images)
	}
	if !errors.Is(err, ErrEngineUnavailable) {
		t.Fatalf("daemon failure must be ErrEngineUnavailable: %v", err)
	}
	for _, expected := range []string{"cannot list local docker images", "Docker daemon"} {
		if !strings.Contains(err.Error(), expected) {
			t.Fatalf("expected %q in error, got %v", expected, err)
		}
	}
}

func TestDockerImagesMissingBinaryIsUnavailable(t *testing.T) {
	manager := newDockerTestManager(t, filepath.Join(t.TempDir(), "docker"))

	_, err := manager.DockerImages(context.Background())
	if err == nil || !errors.Is(err, ErrEngineUnavailable) {
		t.Fatalf("missing binary must be ErrEngineUnavailable, got %v", err)
	}
	if !strings.Contains(err.Error(), "docker is not installed") {
		t.Fatalf("unclear error for a missing binary: %v", err)
	}
}

func TestDockerImagesUnconfiguredBinaryIsUnavailable(t *testing.T) {
	manager := newDockerTestManager(t, "")

	_, err := manager.DockerImages(context.Background())
	if err == nil || !errors.Is(err, ErrEngineUnavailable) {
		t.Fatalf("unconfigured binary must be ErrEngineUnavailable, got %v", err)
	}
}
