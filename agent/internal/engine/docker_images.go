// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"context"
	"fmt"
	"slices"
	"strings"
	"time"
)

const (
	// dockerImagesFormat is the fixed --format used to list local images.
	// Repository and tag are separate columns so a registry port in a
	// reference cannot be mistaken for a tag separator.
	dockerImagesFormat = `{{.Repository}}|{{.Tag}}|{{.ID}}|{{.Size}}|{{.CreatedAt}}`

	// dockerImagesTimeout bounds the listing; it stays below the API's 60 s
	// agent timeout even when the daemon is slow to answer.
	dockerImagesTimeout = 10 * time.Second
)

// DockerImage is one locally available container image. The fields are
// display strings: the API renders them in the Inspector, it never parses
// them back into a reference or a byte count.
type DockerImage struct {
	Reference string `json:"reference"`
	ID        string `json:"id"`
	Size      string `json:"size"`
	Created   string `json:"created"`
}

// DockerImages lists the images already pulled on the agent host. Only a fixed
// argv runs: the request cannot influence which docker subcommand or format is
// used. Dangling images are skipped, references are deduplicated and the
// result is sorted so the UI gets a stable list.
func (m *Manager) DockerImages(ctx context.Context) ([]DockerImage, error) {
	if m.docker == nil || m.docker.binary == "" {
		return nil, fmt.Errorf("%w: docker binary is not configured", ErrEngineUnavailable)
	}
	if !dockerBinaryAvailable(m.docker.binary) {
		return nil, fmt.Errorf(
			"%w: docker is not installed; install the docker engine and start its daemon",
			ErrEngineUnavailable,
		)
	}

	ctx, cancel := context.WithTimeout(ctx, dockerImagesTimeout)
	defer cancel()
	output, err := m.docker.execute(ctx, "image", "ls", "--no-trunc", "--format", dockerImagesFormat)
	if err != nil {
		return nil, fmt.Errorf("%w: cannot list local docker images: %v", ErrEngineUnavailable, err)
	}

	return parseDockerImages(output), nil
}

// parseDockerImages decodes one line per image: repository|tag|id|size|created.
// A line that does not carry the five expected columns is ignored rather than
// guessed at; dangling images (repository or tag "<none>") are never offered
// because a reference of "<none>:<none>" is unusable for a node.
func parseDockerImages(output []byte) []DockerImage {
	images := make([]DockerImage, 0)
	seen := map[string]bool{}
	for _, line := range strings.Split(string(output), "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		fields := strings.SplitN(line, "|", 5)
		if len(fields) < 5 {
			continue
		}
		repository := strings.TrimSpace(fields[0])
		tag := strings.TrimSpace(fields[1])
		if repository == "" || tag == "" || repository == "<none>" || tag == "<none>" {
			continue
		}
		reference := repository + ":" + tag
		if seen[reference] {
			continue
		}
		seen[reference] = true
		images = append(images, DockerImage{
			Reference: reference,
			ID:        strings.TrimSpace(fields[2]),
			Size:      strings.TrimSpace(fields[3]),
			Created:   strings.TrimSpace(fields[4]),
		})
	}
	slices.SortFunc(images, func(first, second DockerImage) int {
		return strings.Compare(first.Reference, second.Reference)
	})

	return images
}
