// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package origin

import "testing"

func TestParseCanonicalisesOrigins(t *testing.T) {
	tests := []struct {
		name string
		raw  string
		want string
	}{
		{name: "http host", raw: "http://lab.example", want: "http://lab.example"},
		{name: "https host", raw: "https://lab.example", want: "https://lab.example"},
		{name: "uppercase scheme and host", raw: "HTTPS://Lab.Example", want: "https://lab.example"},
		{name: "explicit port", raw: "https://lab.example:8443", want: "https://lab.example:8443"},
		{name: "default https port", raw: "https://lab.example:443", want: "https://lab.example"},
		{name: "default http port", raw: "http://lab.example:80", want: "http://lab.example"},
		{name: "trailing slash", raw: " https://lab.example/ ", want: "https://lab.example"},
		{name: "ipv4", raw: "http://127.0.0.1:8091", want: "http://127.0.0.1:8091"},
		{name: "ipv6", raw: "http://[::1]:8091", want: "http://[::1]:8091"},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			got, err := Parse(test.raw)
			if err != nil {
				t.Fatalf("Parse(%q): %v", test.raw, err)
			}
			if got != test.want {
				t.Fatalf("Parse(%q) = %q, want %q", test.raw, got, test.want)
			}
		})
	}
}

func TestParseRejectsUnsafeOrigins(t *testing.T) {
	tests := []string{
		"",
		"   ",
		"*",
		"null",
		"lab.example",
		"//lab.example",
		"ftp://lab.example",
		"file:///etc/passwd",
		"https://",
		"https://user@lab.example",
		"https://lab.example/path",
		"https://lab.example?query=1",
		"https://lab.example#fragment",
		"https://lab.example:bad",
		"https://lab.example:0",
		"https://lab.example:65536",
		"https://lab.example\\@evil.example",
	}
	for _, raw := range tests {
		t.Run(raw, func(t *testing.T) {
			if got, err := Parse(raw); err == nil {
				t.Fatalf("Parse(%q) unexpectedly succeeded with %q", raw, got)
			}
		})
	}
}

func TestAllowsSameOriginByDefault(t *testing.T) {
	tests := []struct {
		name        string
		origin      string
		requestHost string
		want        bool
	}{
		{name: "same host and port", origin: "http://127.0.0.1:8091", requestHost: "127.0.0.1:8091", want: true},
		{name: "case insensitive", origin: "HTTP://Lab.Example:8091", requestHost: "lab.example:8091", want: true},
		{name: "different host", origin: "https://evil.example", requestHost: "lab.example", want: false},
		{name: "different port", origin: "https://lab.example:8443", requestHost: "lab.example", want: false},
		{name: "missing origin", origin: "", requestHost: "lab.example", want: false},
		{name: "missing host", origin: "https://lab.example", requestHost: "", want: false},
		{name: "malformed origin", origin: "https://lab.example/path", requestHost: "lab.example", want: false},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if got := Allows(nil, test.origin, test.requestHost); got != test.want {
				t.Fatalf("Allows(nil, %q, %q) = %v, want %v", test.origin, test.requestHost, got, test.want)
			}
		})
	}
}

func TestAllowsExplicitAllowlist(t *testing.T) {
	allowlist := []string{"https://lab.example", "https://alt.example:8443"}
	tests := []struct {
		name   string
		origin string
		want   bool
	}{
		{name: "first entry", origin: "https://lab.example", want: true},
		{name: "second entry with port", origin: "https://alt.example:8443", want: true},
		{name: "default port canonical", origin: "https://lab.example:443", want: true},
		{name: "unknown host", origin: "https://evil.example", want: false},
		{name: "wrong scheme", origin: "http://lab.example", want: false},
		{name: "missing origin", origin: "", want: false},
		// An explicit allowlist replaces, rather than extends, the
		// same-origin default.
		{name: "request host alone does not grant access", origin: "http://127.0.0.1:8091", want: false},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if got := Allows(allowlist, test.origin, "127.0.0.1:8091"); got != test.want {
				t.Fatalf("Allows(%#v, %q) = %v, want %v", allowlist, test.origin, got, test.want)
			}
		})
	}
}

func TestAllowsIgnoresInvalidAllowlistEntries(t *testing.T) {
	allowlist := []string{"*", "https://lab.example/path", "https://lab.example"}
	if !Allows(allowlist, "https://lab.example", "127.0.0.1:8091") {
		t.Fatal("a valid entry after invalid entries must still match")
	}
	if Allows(allowlist, "https://evil.example", "127.0.0.1:8091") {
		t.Fatal("invalid entries must never match")
	}
}
