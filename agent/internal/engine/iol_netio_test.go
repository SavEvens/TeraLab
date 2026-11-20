// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"encoding/binary"
	"strings"
	"testing"
)

func TestIOLPortEncoding(t *testing.T) {
	for index := 0; index < 32; index++ {
		port := iolPortByte(index)
		if got := iolInterfaceIndex(port); got != index {
			t.Fatalf("port round-trip failed for %d: %d", index, got)
		}
	}
	if iolPortByte(0) != 0x00 || iolPortByte(1) != 0x10 || iolPortByte(3) != 0x30 {
		t.Fatalf("unexpected port bytes: %x %x %x", iolPortByte(0), iolPortByte(1), iolPortByte(3))
	}
	if iolPortByte(4) != 0x01 || iolPortByte(5) != 0x11 || iolPortByte(15) != 0x33 {
		t.Fatalf("unexpected adapter mapping: %x %x %x", iolPortByte(4), iolPortByte(5), iolPortByte(15))
	}
}

func TestIOUHeader(t *testing.T) {
	header := buildIOUHeader(846, int(iolPortByte(1)), 42, int(iolPortByte(1)))
	if len(header) != iouHeaderSize {
		t.Fatalf("unexpected header size: %d", len(header))
	}
	if got := int(binary.BigEndian.Uint16(header[0:2]) & 0x7ff); got != 846 {
		t.Fatalf("unexpected destination id: %d", got)
	}
	if got := int(binary.BigEndian.Uint16(header[2:4]) & 0x7ff); got != 42 {
		t.Fatalf("unexpected source id: %d", got)
	}
	if header[4] != 0x10 || header[5] != 0x10 {
		t.Fatalf("unexpected ports: %x %x", header[4], header[5])
	}
	if header[iouMsgType] != iouMsgData {
		t.Fatalf("unexpected message type: %d", header[iouMsgType])
	}
}

func TestIOLPeerIDIsStableAndDistinct(t *testing.T) {
	for appID := 1; appID <= 1023; appID++ {
		peer := iolPeerID(appID)
		if peer < 1 || peer > 1023 {
			t.Fatalf("peer id out of range for %d: %d", appID, peer)
		}
		if peer == appID {
			t.Fatalf("peer id collides with app id %d", appID)
		}
		if peer != iolPeerID(appID) {
			t.Fatal("peer id must be deterministic")
		}
	}
}

func TestIOLNetmapLine(t *testing.T) {
	line := iolNetmapLine(846, 134, 3)
	if !strings.HasPrefix(line, "846:0/3") || !strings.Contains(line, "134:0/3") {
		t.Fatalf("unexpected netmap line: %s", line)
	}
	line = iolNetmapLine(846, 134, 4)
	if !strings.HasPrefix(line, "846:1/0") || !strings.Contains(line, "134:1/0") {
		t.Fatalf("unexpected second-adapter netmap line: %s", line)
	}
}
