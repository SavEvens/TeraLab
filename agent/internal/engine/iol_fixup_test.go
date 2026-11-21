// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"bytes"
	"strings"
	"testing"
	"time"
)

func TestIPv6OnlyInterfaces(t *testing.T) {
	tests := []struct {
		name   string
		config string
		want   []string
	}{
		{
			name: "ipv6 only",
			config: `interface Ethernet0/0
 ipv6 address 2001:DB8:12::1/64
 ipv6 ospf 1 area 0
 no shutdown
`,
			want: []string{"Ethernet0/0"},
		},
		{
			name: "ipv4 only",
			config: `interface Ethernet0/0
 ip address 10.0.0.1 255.255.255.0
 no shutdown
`,
			want: nil,
		},
		{
			name: "both families",
			config: `interface Ethernet0/0
 ip address 10.0.0.1 255.255.255.0
 ipv6 address 2001:DB8::1/64
`,
			want: nil,
		},
		{
			name: "explicitly unnumbered ipv4",
			config: `interface Ethernet1/0
 no ip address
 ipv6 address 2001:DB8::5/64
`,
			want: []string{"Ethernet1/0"},
		},
		{
			name: "ipv6 only with no shutdown line is included",
			config: `interface Ethernet0/0
 ipv6 address 2001:DB8:12::1/64
 ipv6 ospf 1 area 0
`,
			want: []string{"Ethernet0/0"},
		},
		{
			name: "ipv6 only with no shutdown is included",
			config: `interface Ethernet0/0
 ipv6 address 2001:DB8:12::1/64
 no shutdown
`,
			want: []string{"Ethernet0/0"},
		},
		{
			name: "ipv6 only with an explicit shutdown is excluded",
			config: `interface Ethernet0/0
 ipv6 address 2001:DB8:12::1/64
 shutdown
`,
			want: nil,
		},
		{
			name: "explicit shutdown is per block",
			config: `interface Ethernet0/0
 ipv6 address 2001:DB8:1::1/64
 shutdown
!
interface Ethernet0/1
 ipv6 address 2001:DB8:2::1/64
 no shutdown
!
interface Ethernet0/2
 ipv6 address 2001:DB8:3::1/64
`,
			want: []string{"Ethernet0/1", "Ethernet0/2"},
		},
		{
			name: "subinterfaces are separate blocks",
			config: `interface Ethernet0/0
 ip address 10.0.0.1 255.255.255.0
!
interface Ethernet0/0.10
 encapsulation dot1Q 10
 ipv6 address 2001:DB8:10::1/64
!
interface Ethernet0/0.20
 ip address 10.0.20.1 255.255.255.0
`,
			want: []string{"Ethernet0/0.10"},
		},
		{
			name: "multiple interfaces keep config order",
			config: `interface Loopback0
 ip address 1.1.1.1 255.255.255.255
!
interface Ethernet0/0
 ipv6 address 2001:DB8:1::1/64
!
interface Ethernet0/1
 ipv6 address 2001:DB8:2::1/64
 ip address 10.0.2.1 255.255.255.0
!
interface Ethernet0/2
 ipv6 address 2001:DB8:3::1/64
`,
			want: []string{"Ethernet0/0", "Ethernet0/2"},
		},
		{
			name: "no interfaces",
			config: `hostname R1
ipv6 unicast-routing
!
line con 0
 stopbits 1
`,
			want: nil,
		},
		{
			name: "addresses outside interface blocks are ignored",
			config: `interface Ethernet0/0
 ip address 10.0.0.1 255.255.255.0
!
ipv6 route ::/0 2001:DB8::1
ip access-list extended V6
 permit ipv6 any any
!
class-map match-any V6TRAFFIC
 match ipv6 address V6
`,
			want: nil,
		},
		{
			name: "block ends at the next top-level section",
			config: `interface Ethernet0/0
 ipv6 address 2001:DB8::1/64
!
ipv6 route ::/0 2001:DB8::2
`,
			want: []string{"Ethernet0/0"},
		},
		{
			name: "unindented configuration",
			config: `interface Ethernet0/0
ipv6 address 2001:DB8::1/64
no shutdown
interface Ethernet0/1
ip address 10.0.0.1 255.255.255.0
`,
			want: []string{"Ethernet0/0"},
		},
		{
			name:   "trailing space on a top-level command still ends the block",
			config: "interface Ethernet0/0\n ip address 10.0.0.1 255.255.255.0\nrouter ospf 1 \n ipv6 address 2001:DB8::1/64\n",
			want:   nil,
		},
		{
			name: "uppercase commands",
			config: `INTERFACE Ethernet0/0
 IPV6 ADDRESS 2001:DB8::1/64
`,
			want: []string{"Ethernet0/0"},
		},
		{
			name:   "empty config",
			config: "",
			want:   nil,
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			got := IPv6OnlyInterfaces(test.config)
			if len(got) != len(test.want) {
				t.Fatalf("interfaces = %v, want %v", got, test.want)
			}
			for index := range test.want {
				if got[index] != test.want[index] {
					t.Fatalf("interfaces = %v, want %v", got, test.want)
				}
			}
		})
	}
}

func TestRequiresEnablePassword(t *testing.T) {
	tests := []struct {
		name   string
		config string
		want   bool
	}{
		{
			name: "enable secret",
			config: `hostname R1
enable secret 5 $1$mERr$hx5rVt7rPNoS4wqbXKX7m0
`,
			want: true,
		},
		{
			name: "enable password",
			config: `hostname R1
enable password labpass
`,
			want: true,
		},
		{
			name: "enable password with an encryption type",
			config: `enable password 7 070C285F4D06
`,
			want: true,
		},
		{
			name:   "algorithm-type secret",
			config: "enable algorithm-type scrypt secret labsecret\n",
			want:   true,
		},
		{
			name:   "service password-encryption alone",
			config: "service password-encryption\n",
			want:   false,
		},
		{
			name:   "no enable password",
			config: "no enable password\n",
			want:   false,
		},
		{
			name: "no enable authentication",
			config: `hostname R1
interface Ethernet0/0
 ipv6 address 2001:DB8::1/64
 no shutdown
`,
			want: false,
		},
		{
			name:   "empty config",
			config: "",
			want:   false,
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if got := RequiresEnablePassword(test.config); got != test.want {
				t.Fatalf("RequiresEnablePassword(%q) = %v, want %v", test.config, got, test.want)
			}
		})
	}
}

func TestIOLFixupCommands(t *testing.T) {
	tests := []struct {
		name       string
		interfaces []string
		want       []string
	}{
		{name: "no interfaces", interfaces: nil, want: nil},
		{
			name:       "one interface",
			interfaces: []string{"Ethernet0/0"},
			want: []string{
				"enable",
				"configure terminal",
				"interface Ethernet0/0",
				"no shutdown",
				"end",
			},
		},
		{
			name:       "two interfaces share one enable",
			interfaces: []string{"Ethernet0/0", "Ethernet0/2"},
			want: []string{
				"enable",
				"configure terminal",
				"interface Ethernet0/0",
				"no shutdown",
				"end",
				"configure terminal",
				"interface Ethernet0/2",
				"no shutdown",
				"end",
			},
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			got := iolFixupCommands(test.interfaces)
			if len(got) != len(test.want) {
				t.Fatalf("commands = %v, want %v", got, test.want)
			}
			for index := range test.want {
				if got[index] != test.want[index] {
					t.Fatalf("commands = %v, want %v", got, test.want)
				}
				if strings.ContainsAny(got[index], "\r\n") {
					t.Fatalf("command %q must not carry its own terminator", got[index])
				}
			}
		})
	}
}

// newTestConsoleReader builds a probe over an in-memory console so the dialog
// and replay flow can be tested without a pty.
func newTestConsoleReader(node *Node) (*consoleReader, *bytes.Buffer) {
	output := &bytes.Buffer{}

	return &consoleReader{
		node:    node,
		session: &PTYSession{node: node, writeTo: output},
		chunks:  make(chan []byte, 16),
		closed:  make(chan struct{}),
	}, output
}

func TestConsoleReaderAnswersIOLFirstBootDialogs(t *testing.T) {
	reader, output := newTestConsoleReader(&Node{Spec: Spec{ID: "node1", Engine: "iol"}})
	reader.chunks <- []byte("\r\nWould you like to enter the initial configuration dialog? [yes/no]: ")
	reader.chunks <- []byte("Press RETURN to get started!\r\n")
	reader.chunks <- []byte("\r\n\r\nR1#")

	if !reader.awaitPrompt(time.Second) {
		t.Fatal("prompt was not detected")
	}
	if got := output.String(); got != "no\r\r" {
		t.Fatalf("console input = %q, want %q", got, "no\r\r")
	}
}

func TestConsoleReaderSendReplaysOneBurst(t *testing.T) {
	reader, output := newTestConsoleReader(&Node{Spec: Spec{ID: "node1", Engine: "iol"}})
	reader.chunks <- []byte("\r\nR1#")
	if !reader.awaitPrompt(time.Second) {
		t.Fatal("initial prompt was not detected")
	}

	go func() {
		time.Sleep(50 * time.Millisecond)
		reader.chunks <- []byte("\r\nR1(config)#")
	}()
	if !reader.send("configure terminal", 2*time.Second) {
		t.Fatal("prompt after the replayed command was not detected")
	}
	if got := output.String(); got != "configure terminal\r" {
		t.Fatalf("console input = %q, want %q", got, "configure terminal\r")
	}

	if reader.send("end", 20*time.Millisecond) {
		t.Fatal("send must report a timeout when no prompt follows")
	}
}

func TestConsolePromptSeen(t *testing.T) {
	tests := []struct {
		name string
		text string
		want bool
	}{
		{name: "privileged prompt", text: "R1#", want: true},
		{name: "config prompt", text: "R1(config)#", want: true},
		{name: "interface prompt", text: "R1(config-if)#", want: true},
		{name: "vpcs prompt", text: "VPCS>", want: true},
		{name: "prompt with trailing spaces", text: "R1#   ", want: true},
		{name: "hostname with a dot", text: "lab-sw1.edge#", want: true},
		{name: "boot notice", text: "Press RETURN to get started!", want: false},
		{name: "setup question", text: "Would you like to enter the initial configuration dialog? [yes/no]: ", want: false},
		{name: "empty", text: "", want: false},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if got := consolePromptSeen(test.text); got != test.want {
				t.Fatalf("consolePromptSeen(%q) = %v, want %v", test.text, got, test.want)
			}
		})
	}
}

func TestConsoleReaderTextStripsControlSequences(t *testing.T) {
	reader, _ := newTestConsoleReader(&Node{Spec: Spec{ID: "node1"}})
	reader.push([]byte("\x1b[1mR1\x1b[0m#\r\n"))

	if got := reader.text(); got != "R1#\n" {
		t.Fatalf("text = %q, want %q", got, "R1#\n")
	}
	if !consolePromptSeen(reader.text()) {
		t.Fatal("prompt must be visible after stripping control sequences")
	}
}
