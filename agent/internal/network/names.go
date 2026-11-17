// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package network

import (
	"errors"
	"regexp"
)

const maxLinkName = 15

var linkNamePattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,14}$`)

// ValidateLinkName enforces Linux interface-name limits and charset.
func ValidateLinkName(name string) error {
	if len(name) == 0 || len(name) > maxLinkName {
		return errors.New("network link name must be 1-15 characters")
	}
	if !linkNamePattern.MatchString(name) {
		return errors.New("invalid network link name")
	}

	return nil
}
