// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

// Package origin validates and compares browser origins for the console
// WebSocket handshake. Only scheme://host[:port] values are accepted: paths,
// credentials, queries, fragments, and wildcards are configuration errors so a
// typo can never widen the console allowlist.
package origin

import (
	"errors"
	"fmt"
	"net"
	"net/url"
	"strconv"
	"strings"
)

// Parse validates a single browser origin and returns its canonical form.
//
// The canonical form lower-cases the scheme and host and drops the scheme's
// default port, matching how browsers serialise an Origin header. An empty
// value, a wildcard, a relative URL, userinfo, a path, a query, or a fragment
// is rejected.
func Parse(raw string) (string, error) {
	value := strings.TrimSpace(raw)
	if value == "" {
		return "", errors.New("origin is empty")
	}
	if value == "*" {
		return "", errors.New("wildcard origins are not allowed")
	}
	parsed, err := url.Parse(value)
	if err != nil {
		return "", fmt.Errorf("parse origin: %w", err)
	}
	scheme := strings.ToLower(parsed.Scheme)
	if scheme != "http" && scheme != "https" {
		return "", fmt.Errorf("origin scheme %q must be http or https", parsed.Scheme)
	}
	if parsed.User != nil {
		return "", errors.New("origin must not carry userinfo")
	}
	if parsed.Host == "" {
		return "", errors.New("origin has no host")
	}
	if parsed.Path != "" && parsed.Path != "/" {
		return "", errors.New("origin must not carry a path")
	}
	if parsed.RawQuery != "" || parsed.Fragment != "" {
		return "", errors.New("origin must not carry a query or fragment")
	}
	host := strings.ToLower(parsed.Hostname())
	if host == "" {
		return "", errors.New("origin has no host")
	}
	if strings.ContainsAny(host, `\@`) {
		return "", errors.New("origin host contains invalid characters")
	}
	port := parsed.Port()
	if port != "" {
		number, err := strconv.Atoi(port)
		if err != nil || number < 1 || number > 65535 {
			return "", fmt.Errorf("origin port %q is not a valid TCP port", port)
		}
	}
	if (scheme == "http" && port == "80") || (scheme == "https" && port == "443") {
		port = ""
	}

	return scheme + "://" + joinHostPort(host, port), nil
}

func joinHostPort(host, port string) string {
	if port != "" {
		return net.JoinHostPort(host, port)
	}
	if strings.Contains(host, ":") {
		// IPv6 literal without an explicit port.
		return "[" + host + "]"
	}

	return host
}

// Allows reports whether requestOrigin is acceptable for a request received
// with requestHost.
//
// With an empty allowlist the handshake is same-origin only: the origin host
// (including a non-default port) must equal the request Host. With a
// non-empty allowlist only the listed origins are accepted; the same-origin
// default is not added implicitly. A missing or malformed Origin is always
// rejected.
func Allows(allowlist []string, requestOrigin, requestHost string) bool {
	canonicalOrigin, err := Parse(requestOrigin)
	if err != nil {
		return false
	}
	if len(allowlist) == 0 {
		requestCanonical, err := parseOriginHost(requestOrigin)
		if err != nil {
			return false
		}

		return strings.EqualFold(requestCanonical, strings.TrimSpace(requestHost))
	}
	for _, entry := range allowlist {
		allowed, err := Parse(entry)
		if err != nil {
			continue
		}
		if allowed == canonicalOrigin {
			return true
		}
	}

	return false
}

// parseOriginHost returns the host[:port] component of a valid origin without
// applying default-port normalisation. It is used for same-origin comparison
// against the request Host, which has no scheme of its own.
func parseOriginHost(raw string) (string, error) {
	parsed, err := url.Parse(strings.TrimSpace(raw))
	if err != nil {
		return "", fmt.Errorf("parse origin: %w", err)
	}
	scheme := strings.ToLower(parsed.Scheme)
	if scheme != "http" && scheme != "https" {
		return "", fmt.Errorf("origin scheme %q must be http or https", parsed.Scheme)
	}
	if parsed.User != nil || parsed.Host == "" {
		return "", errors.New("origin must name a host without userinfo")
	}
	if parsed.Path != "" && parsed.Path != "/" {
		return "", errors.New("origin must not carry a path")
	}
	if parsed.RawQuery != "" || parsed.Fragment != "" {
		return "", errors.New("origin must not carry a query or fragment")
	}

	return parsed.Host, nil
}
