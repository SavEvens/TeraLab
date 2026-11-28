// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package network

import (
	"errors"
	"regexp"
	"strings"
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

// ownedLinkPrefix is the mandatory prefix of every link TeraLab creates and may
// delete. It keeps DeleteLink/getlink and the cleanup sweeps away from host
// interfaces such as eth0, br0 or docker0.
const ownedLinkPrefix = "tl"

// OwnsLinkName reports whether name is a valid TeraLab-managed link name.
func OwnsLinkName(name string) bool {
	return strings.HasPrefix(name, ownedLinkPrefix) && ValidateLinkName(name) == nil
}

// validateOwnedLinkName rejects a name that TeraLab did not create.
func validateOwnedLinkName(name string) error {
	if err := ValidateLinkName(name); err != nil {
		return err
	}
	if !strings.HasPrefix(name, ownedLinkPrefix) {
		return errors.New("refusing to touch a link that is not TeraLab-owned")
	}

	return nil
}
