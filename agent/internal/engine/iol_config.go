// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"path/filepath"
	"regexp"
	"strings"
)

// vlanDefinitionPattern matches a global VLAN definition line: "vlan 10",
// "vlan 10,20,30" or "vlan 10-12". Commands that merely mention a VLAN
// ("interface Vlan10", "switchport access vlan 10", "switchport trunk allowed
// vlan 10,20") do not start with "vlan" and never match, and neither do
// comments.
var vlanDefinitionPattern = regexp.MustCompile(`^vlan\s+[0-9]+(\s*[,\-]\s*[0-9]+)*$`)

// vtpTransparentLine is the global command that makes IOS store VLAN
// definitions in the startup configuration instead of the VLAN database.
//
// IOS keeps VLAN 1-1005 in vlan.dat. In the default VTP server mode the vlan
// lines of a startup configuration are ignored; with VTP transparent they are
// part of the configuration and are applied. Without this line an IOL L2 node
// boots with only the default VLANs.
const vtpTransparentLine = "vtp mode transparent"

// EnsureVlanDatabaseMode returns config with "vtp mode transparent" inserted
// before the first VLAN definition when any vlan line exists. It is idempotent
// and returns changed=false when nothing was modified, in which case out is
// config byte for byte.
//
// The inserted line is a global command, so it is written without indentation
// and keeps the configuration's line-ending style. Everything else is left
// exactly as it was.
func EnsureVlanDatabaseMode(config string) (out string, changed bool) {
	if config == "" {
		return config, false
	}

	newline := "\n"
	if strings.Contains(config, "\r\n") {
		newline = "\r\n"
	}

	lines := splitConfigLines(config)
	first := -1
	for index, line := range lines {
		trimmed := strings.TrimSpace(strings.TrimRight(line, "\r\n"))
		if strings.EqualFold(trimmed, vtpTransparentLine) {
			return config, false
		}
		if first < 0 && vlanDefinitionPattern.MatchString(strings.ToLower(trimmed)) {
			first = index
		}
	}
	if first < 0 {
		return config, false
	}

	result := make([]string, 0, len(lines)+1)
	result = append(result, lines[:first]...)
	result = append(result, vtpTransparentLine+newline)
	result = append(result, lines[first:]...)

	return strings.Join(result, ""), true
}

// splitConfigLines splits a configuration into lines that keep their own
// terminator, so joining the result reproduces the input exactly.
func splitConfigLines(config string) []string {
	lines := make([]string, 0, strings.Count(config, "\n")+1)
	for len(config) > 0 {
		index := strings.IndexByte(config, '\n')
		if index < 0 {
			lines = append(lines, config)
			break
		}
		lines = append(lines, config[:index+1])
		config = config[index+1:]
	}

	return lines
}

// iolSwitchImageTokens are basename fragments of the IOL layer-2 switch image,
// for example "x86_64_crb_linux_l2-adventerprisek9-ms.bin". Only that build
// keeps VLAN definitions in the startup configuration.
var iolSwitchImageTokens = []string{"linux_l2", "_l2-", "linux-l2"}

// isIOLSwitchImage reports whether the node image is the IOL layer-2 build.
// Layer-3 images such as "x86_64_crb_linux-adventerprisek9-ms.bin" contain
// none of the tokens.
func isIOLSwitchImage(image string) bool {
	name := strings.ToLower(filepath.Base(image))
	for _, token := range iolSwitchImageTokens {
		if strings.Contains(name, token) {
			return true
		}
	}

	return false
}
