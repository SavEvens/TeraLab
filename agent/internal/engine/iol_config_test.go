// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"strings"
	"testing"
)

const l2SampleConfig = `!
version 15.2
service timestamps debug datetime msec
!
hostname SW1
!
spanning-tree mode pvst
!
interface Ethernet0/0
 switchport trunk encapsulation dot1q
 switchport mode trunk
!
interface Vlan10
 ip address 10.0.0.1 255.255.255.0
!
interface Vlan20
 ip address 10.0.1.1 255.255.255.0
!
vlan 10
vlan 20
vlan 30
!
line con 0
 stopbits 1
line vty 0 4
 login
!
end
`

func TestEnsureVlanDatabaseMode(t *testing.T) {
	tests := []struct {
		name       string
		config     string
		want       string
		wantChange bool
	}{
		{
			name:       "single vlan",
			config:     "vlan 10\n",
			want:       "vtp mode transparent\nvlan 10\n",
			wantChange: true,
		},
		{
			name:       "vlan list",
			config:     "vlan 10,20,30\n",
			want:       "vtp mode transparent\nvlan 10,20,30\n",
			wantChange: true,
		},
		{
			name:       "vlan range",
			config:     "vlan 10-12\n",
			want:       "vtp mode transparent\nvlan 10-12\n",
			wantChange: true,
		},
		{
			name:       "vlan list with spaces",
			config:     "vlan 10, 20\n",
			want:       "vtp mode transparent\nvlan 10, 20\n",
			wantChange: true,
		},
		{
			name:       "inserted before the first vlan only",
			config:     l2SampleConfig,
			want:       strings.Replace(l2SampleConfig, "vlan 10\nvlan 20\nvlan 30\n", "vtp mode transparent\nvlan 10\nvlan 20\nvlan 30\n", 1),
			wantChange: true,
		},
		{
			name:       "already transparent before the vlan",
			config:     "vtp mode transparent\nvlan 10\n",
			want:       "vtp mode transparent\nvlan 10\n",
			wantChange: false,
		},
		{
			name:       "already transparent after the vlan",
			config:     "vlan 10\nvtp mode transparent\n",
			want:       "vlan 10\nvtp mode transparent\n",
			wantChange: false,
		},
		{
			name:       "transparent in a different case",
			config:     "VTP Mode Transparent\nvlan 10\n",
			want:       "VTP Mode Transparent\nvlan 10\n",
			wantChange: false,
		},
		{
			name:       "ipv4 only config",
			config:     "interface Ethernet0/0\n ip address 10.0.0.1 255.255.255.0\n no shutdown\n",
			want:       "interface Ethernet0/0\n ip address 10.0.0.1 255.255.255.0\n no shutdown\n",
			wantChange: false,
		},
		{
			name:       "switchport vlan references are not definitions",
			config:     "interface Ethernet0/1\n switchport access vlan 10\n switchport trunk allowed vlan 10,20\n switchport trunk native vlan 10\n",
			want:       "interface Ethernet0/1\n switchport access vlan 10\n switchport trunk allowed vlan 10,20\n switchport trunk native vlan 10\n",
			wantChange: false,
		},
		{
			name:       "svi interface is not a definition",
			config:     "interface Vlan10\n ip address 10.0.0.1 255.255.255.0\n",
			want:       "interface Vlan10\n ip address 10.0.0.1 255.255.255.0\n",
			wantChange: false,
		},
		{
			name:       "vlan internal allocation policy is not a definition",
			config:     "vlan internal allocation policy ascending\n",
			want:       "vlan internal allocation policy ascending\n",
			wantChange: false,
		},
		{
			name:       "comments are ignored",
			config:     "! vlan 10\n!\n",
			want:       "! vlan 10\n!\n",
			wantChange: false,
		},
		{
			name:       "vtp server without vlans",
			config:     "vtp mode server\nvtp domain lab\n",
			want:       "vtp mode server\nvtp domain lab\n",
			wantChange: false,
		},
		{
			name:       "empty config",
			config:     "",
			want:       "",
			wantChange: false,
		},
		{
			name:       "no trailing newline is preserved",
			config:     "hostname SW1\nvlan 10",
			want:       "hostname SW1\nvtp mode transparent\nvlan 10",
			wantChange: true,
		},
		{
			name:       "crlf line endings are preserved",
			config:     "hostname SW1\r\nvlan 10\r\n",
			want:       "hostname SW1\r\nvtp mode transparent\r\nvlan 10\r\n",
			wantChange: true,
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			got, changed := EnsureVlanDatabaseMode(test.config)
			if changed != test.wantChange {
				t.Fatalf("changed = %v, want %v", changed, test.wantChange)
			}
			if got != test.want {
				t.Fatalf("config mismatch\n got: %q\nwant: %q", got, test.want)
			}
			if !test.wantChange && got != test.config {
				t.Fatal("unchanged input must be returned byte-identically")
			}
			if test.wantChange && strings.Count(got, vtpTransparentLine) != 1 {
				t.Fatalf("expected exactly one %q line, got %q", vtpTransparentLine, got)
			}

			again, changedAgain := EnsureVlanDatabaseMode(got)
			if changedAgain || again != got {
				t.Fatalf("not idempotent: changed = %v, output %q", changedAgain, again)
			}
		})
	}
}

func TestIsIOLSwitchImage(t *testing.T) {
	tests := []struct {
		image string
		want  bool
	}{
		{image: "x86_64_crb_linux_l2-adventerprisek9-ms.bin", want: true},
		{image: "/var/lib/teralab/images/iol/x86_64_crb_linux_l2-adventerprisek9-ms.bin", want: true},
		{image: "i86bi-linux-l2-adventerprisek9-15.2d.bin", want: true},
		{image: "X86_64_CRB_LINUX_L2-ADVENTERPRISEK9-MS.BIN", want: true},
		{image: "x86_64_crb_linux-adventerprisek9-ms.bin", want: false},
		{image: "x86_64_crb_linux-ipbasek9-ms.bin", want: false},
		{image: "iol_l3.bin", want: false},
		{image: "", want: false},
	}

	for _, test := range tests {
		if got := isIOLSwitchImage(test.image); got != test.want {
			t.Fatalf("isIOLSwitchImage(%q) = %v, want %v", test.image, got, test.want)
		}
	}
}
