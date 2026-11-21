// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"strings"
	"time"
)

const (
	// iolFixupBudget bounds the whole post-boot replay. A slow host only
	// loses the remainder of the fixup; the node keeps running.
	iolFixupBudget = 60 * time.Second
)

// IPv6OnlyInterfaces returns the interfaces of an embedded configuration that
// carry an "ipv6 address" and no "ip address" line, in configuration order.
//
// IOS drops the "no shutdown" of such interfaces when it restores a startup
// configuration: without an IPv4 address they come up administratively down.
// The agent therefore replays "no shutdown" on the console after boot.
//
// An interface whose block carries an explicit "shutdown" is left out: the
// user meant it down, and the replay must not override that.
//
// An interface block starts at an "interface <name>" line and ends at the next
// top-level command. Addresses in any other section (routes, access lists,
// router blocks) are ignored. A top-level line that is an interface command is
// still treated as part of the block, so an unindented configuration parses
// the same way as a standard IOS one.
func IPv6OnlyInterfaces(config string) []string {
	interfaces := []string{}
	current := ""
	hasIPv4, hasIPv6, shutdown := false, false, false

	flush := func() {
		if current != "" && hasIPv6 && !hasIPv4 && !shutdown {
			interfaces = append(interfaces, current)
		}
		current = ""
		hasIPv4, hasIPv6, shutdown = false, false, false
	}

	for _, raw := range strings.Split(config, "\n") {
		line := strings.TrimRight(raw, "\r")
		trimmed := strings.TrimSpace(line)
		if trimmed == "" || strings.HasPrefix(trimmed, "!") {
			continue
		}

		fields := strings.Fields(trimmed)
		lowerFields := strings.Fields(strings.ToLower(trimmed))
		if lowerFields[0] == "interface" && len(fields) >= 2 {
			flush()
			current = fields[1]
			continue
		}
		if current == "" {
			continue
		}
		if !hasLeadingSpace(line) && !interfaceBlockCommand(lowerFields) {
			flush()
			continue
		}

		lower := strings.ToLower(trimmed)
		switch {
		case strings.HasPrefix(lower, "ipv6 address"):
			hasIPv6 = true
		case strings.HasPrefix(lower, "ip address"):
			hasIPv4 = true
		case lower == "shutdown":
			shutdown = true
		}
	}
	flush()

	return interfaces
}

// hasLeadingSpace reports whether a line is indented, which in an IOS running
// configuration marks it as part of the enclosing section.
func hasLeadingSpace(line string) bool {
	return strings.TrimLeft(line, " \t") != line
}

// interfaceBlockCommand reports whether a top-level line can only be an
// interface-mode command, so it does not end the current block.
func interfaceBlockCommand(lowerFields []string) bool {
	switch lowerFields[0] {
	case "shutdown":
		return true
	case "no":
		return len(lowerFields) > 1 && lowerFields[1] == "shutdown"
	case "ip", "ipv6":
		return len(lowerFields) > 1 && lowerFields[1] == "address"
	}

	return false
}

// RequiresEnablePassword reports whether the configuration protects
// privileged exec with a password or secret. In that case the replay cannot
// reach configuration mode without credentials, and the agent must not stall
// on the "Password:" prompt or guess a credential.
func RequiresEnablePassword(config string) bool {
	for _, raw := range strings.Split(config, "\n") {
		lower := strings.ToLower(strings.TrimSpace(strings.TrimRight(raw, "\r")))
		switch {
		case strings.HasPrefix(lower, "enable secret"),
			strings.HasPrefix(lower, "enable password"),
			strings.HasPrefix(lower, "enable algorithm-type"):
			return true
		}
	}

	return false
}

// iolFixupCommands builds the console replay for the given interfaces: one
// "enable", then a configure/interface/no shutdown/end cycle per interface.
func iolFixupCommands(interfaces []string) []string {
	if len(interfaces) == 0 {
		return nil
	}

	commands := make([]string, 0, 1+4*len(interfaces))
	commands = append(commands, "enable")
	for _, name := range interfaces {
		commands = append(commands,
			"configure terminal",
			"interface "+name,
			"no shutdown",
			"end",
		)
	}

	return commands
}

// startIOLPostBoot watches an IOL node console and replays the admin state of
// IPv6-only interfaces once the device answers with a prompt. It is
// best-effort: a timeout logs a warning and never fails the node start.
func (m *Manager) startIOLPostBoot(node *Node) {
	m.startConsoleProbe(node, func(reader *consoleReader) {
		if node.Spec.Ethernet == 0 {
			// IOL ignores the startup configuration when no interface is
			// attached, so there is nothing to replay.
			return
		}
		interfaces := IPv6OnlyInterfaces(node.Spec.Config)
		if len(interfaces) == 0 {
			return
		}
		if RequiresEnablePassword(node.Spec.Config) {
			m.logger.Warn("iol: enable authentication configured, skipping post-boot no shutdown replay",
				"node", node.Spec.ID)
			return
		}

		deadline := time.Now().Add(iolFixupBudget)
		for _, command := range iolFixupCommands(interfaces) {
			if time.Now().After(deadline) {
				m.logger.Warn("iol: post-boot fixup budget exhausted", "node", node.Spec.ID)
				return
			}
			m.logger.Info("iol: post-boot fixup", "node", node.Spec.ID, "command", command)
			if !reader.send(command, consoleCommandTimeout) {
				m.logger.Warn("iol: post-boot fixup stopped without a prompt",
					"node", node.Spec.ID, "command", command)
				return
			}
		}
		m.logger.Info("iol: post-boot fixup complete",
			"node", node.Spec.ID, "interfaces", interfaces)
	})
}
