// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"encoding/binary"
	"errors"
	"net"
	"os"
	"path/filepath"
	"strconv"
	"testing"
	"time"
)

const (
	testL1AppID  = 900
	testL1PeerID = 901
)

func bindTestL1(t *testing.T, directory string, instance int) *net.UnixConn {
	t.Helper()
	path := filepath.Join(directory, "L1"+strconv.Itoa(instance))
	_ = os.Remove(path)
	conn, err := net.ListenUnixgram("unixgram", &net.UnixAddr{Name: path, Net: "unixgram"})
	if err != nil {
		t.Fatalf("bind test socket %s: %v", path, err)
	}
	t.Cleanup(func() { _ = conn.Close() })
	_ = conn.SetReadDeadline(time.Now().Add(2 * time.Second))

	return conn
}

func readKeepalive(t *testing.T, conn *net.UnixConn, timeout time.Duration) (iouL1Keepalive, bool) {
	t.Helper()
	_ = conn.SetReadDeadline(time.Now().Add(timeout))
	buffer := make([]byte, 64)
	count, _, err := conn.ReadFromUnix(buffer)
	if err != nil {
		if errors.Is(err, os.ErrDeadlineExceeded) {
			return iouL1Keepalive{}, false
		}
		t.Fatalf("read keepalive: %v", err)
	}
	message, ok := parseIOUL1Keepalive(buffer[:count])
	if !ok {
		t.Fatalf("unexpected keepalive length %d", count)
	}

	return message, true
}

func TestIOUL1KeepaliveMarshal(t *testing.T) {
	message := iouL1Keepalive{
		Destination:          testL1AppID,
		Source:               testL1PeerID,
		DestinationInterface: 0x10,
		SourceInterface:      0x20,
		Type:                 iouL1MessageType,
		Channel:              0,
	}
	buffer := message.marshal()
	if len(buffer) != iouL1MessageSize {
		t.Fatalf("length = %d", len(buffer))
	}
	if binary.BigEndian.Uint16(buffer[0:2]) != testL1AppID {
		t.Fatalf("destination = %x", buffer[0:2])
	}
	if buffer[4] != 0x10 || buffer[5] != 0x20 || buffer[6] != 0x03 || buffer[7] != 0x00 {
		t.Fatalf("unexpected payload: % x", buffer)
	}
	parsed, ok := parseIOUL1Keepalive(buffer)
	if !ok || parsed != message {
		t.Fatalf("round trip mismatch: %+v %v", parsed, ok)
	}
	if _, ok := parseIOUL1Keepalive(buffer[:7]); ok {
		t.Fatal("short payload should not parse")
	}
}

func TestIOLPortByteMatchesKeepaliveEncoding(t *testing.T) {
	cases := map[int]byte{0: 0x00, 1: 0x10, 2: 0x20, 3: 0x30, 4: 0x01, 5: 0x11, 15: 0x33}
	for index, want := range cases {
		if got := iolPortByte(index); got != want {
			t.Fatalf("iolPortByte(%d) = %#x, want %#x", index, got, want)
		}
		if got := iolInterfaceIndex(want); got != index {
			t.Fatalf("iolInterfaceIndex(%#x) = %d, want %d", want, got, index)
		}
	}
}

func TestIOLL1ResponderEchoesLinkedInterface(t *testing.T) {
	directory := t.TempDir()
	responder, err := startIOLL1WithInterval(directory, testL1AppID, testL1PeerID, []bool{true, false}, 0, time.Hour)
	if err != nil {
		t.Fatalf("start responder: %v", err)
	}
	t.Cleanup(func() { _ = responder.Close() })

	instance := bindTestL1(t, directory, testL1AppID)
	request := iouL1Keepalive{
		Destination:          testL1PeerID,
		Source:               testL1AppID,
		DestinationInterface: 0x00,
		SourceInterface:      0x00,
		Type:                 iouL1MessageType,
	}
	if _, err := instance.WriteToUnix(request.marshal(), &net.UnixAddr{Name: filepath.Join(directory, "L1"+strconv.Itoa(testL1PeerID)), Net: "unixgram"}); err != nil {
		t.Fatalf("send keepalive: %v", err)
	}

	reply, ok := readKeepalive(t, instance, time.Second)
	if !ok {
		t.Fatal("expected an echoed keepalive")
	}
	want := iouL1Keepalive{
		Destination:          testL1AppID,
		Source:               testL1PeerID,
		DestinationInterface: 0x00,
		SourceInterface:      0x00,
		Type:                 iouL1MessageType,
	}
	if reply != want {
		t.Fatalf("reply = %+v, want %+v", reply, want)
	}
}

func TestIOLL1ResponderIgnoresUnlinkedInterface(t *testing.T) {
	directory := t.TempDir()
	responder, err := startIOLL1WithInterval(directory, testL1AppID, testL1PeerID, []bool{false, true}, 0, time.Hour)
	if err != nil {
		t.Fatalf("start responder: %v", err)
	}
	t.Cleanup(func() { _ = responder.Close() })

	instance := bindTestL1(t, directory, testL1AppID)
	request := iouL1Keepalive{
		Destination:          testL1PeerID,
		Source:               testL1AppID,
		DestinationInterface: 0x00,
		SourceInterface:      0x00, // Ethernet0/0, unlinked
		Type:                 iouL1MessageType,
	}
	if _, err := instance.WriteToUnix(request.marshal(), &net.UnixAddr{Name: filepath.Join(directory, "L1"+strconv.Itoa(testL1PeerID)), Net: "unixgram"}); err != nil {
		t.Fatalf("send keepalive: %v", err)
	}

	if reply, ok := readKeepalive(t, instance, 300*time.Millisecond); ok {
		t.Fatalf("unlinked interface should not be answered: %+v", reply)
	}
}

func TestIOLL1ResponderIgnoresInvalidMessages(t *testing.T) {
	directory := t.TempDir()
	responder, err := startIOLL1WithInterval(directory, testL1AppID, testL1PeerID, []bool{true, true}, 0, time.Hour)
	if err != nil {
		t.Fatalf("start responder: %v", err)
	}
	t.Cleanup(func() { _ = responder.Close() })

	instance := bindTestL1(t, directory, testL1AppID)
	peer := &net.UnixAddr{Name: filepath.Join(directory, "L1"+strconv.Itoa(testL1PeerID)), Net: "unixgram"}
	valid := iouL1Keepalive{Destination: testL1PeerID, Source: testL1AppID, Type: iouL1MessageType}
	cases := map[string][]byte{
		"short": valid.marshal()[:7],
		"wrong destination": func() []byte {
			bad := valid
			bad.Destination = testL1PeerID + 1
			return bad.marshal()
		}(),
		"wrong source": func() []byte {
			bad := valid
			bad.Source = testL1AppID + 1
			return bad.marshal()
		}(),
		"wrong type": func() []byte {
			bad := valid
			bad.Type = 1
			return bad.marshal()
		}(),
		"unknown interface": func() []byte {
			bad := valid
			bad.SourceInterface = 0x40
			return bad.marshal()
		}(),
	}
	for name, payload := range cases {
		if _, err := instance.WriteToUnix(payload, peer); err != nil {
			t.Fatalf("%s: send: %v", name, err)
		}
		if reply, ok := readKeepalive(t, instance, 200*time.Millisecond); ok {
			t.Fatalf("%s: unexpected reply %+v", name, reply)
		}
	}
}

func TestIOLL1ResponderSendsKeepalivesForLinkedPorts(t *testing.T) {
	directory := t.TempDir()
	linked := []bool{true, false, true}
	responder, err := startIOLL1WithInterval(directory, testL1AppID, testL1PeerID, linked, 0, 20*time.Millisecond)
	if err != nil {
		t.Fatalf("start responder: %v", err)
	}
	t.Cleanup(func() { _ = responder.Close() })

	instance := bindTestL1(t, directory, testL1AppID)
	seen := map[byte]iouL1Keepalive{}
	deadline := time.Now().Add(time.Second)
	for len(seen) < 2 && time.Now().Before(deadline) {
		message, ok := readKeepalive(t, instance, time.Until(deadline))
		if !ok {
			break
		}
		seen[message.SourceInterface] = message
	}
	if len(seen) != 2 {
		t.Fatalf("expected keepalives for two linked ports, got %v", seen)
	}
	for _, port := range []byte{0x00, 0x20} {
		message, ok := seen[port]
		if !ok {
			t.Fatalf("missing keepalive for interface %#x", port)
		}
		if message.Destination != testL1AppID || message.Source != testL1PeerID || message.Type != iouL1MessageType {
			t.Fatalf("unexpected keepalive %+v", message)
		}
	}
	if _, ok := seen[0x10]; ok {
		t.Fatal("unlinked interface 0x10 must not receive a keepalive")
	}
}

func TestIOLL1ResponderRemovesStaleSocketsAndCloses(t *testing.T) {
	directory := t.TempDir()
	for _, instance := range []int{testL1AppID, testL1PeerID} {
		if err := os.WriteFile(filepath.Join(directory, "L1"+strconv.Itoa(instance)), []byte("stale"), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	responder, err := startIOLL1In(directory, testL1AppID, testL1PeerID, []bool{true}, 0)
	if err != nil {
		t.Fatalf("start responder: %v", err)
	}
	if _, err := os.Stat(filepath.Join(directory, "L1"+strconv.Itoa(testL1AppID))); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("stale instance socket was not removed: %v", err)
	}
	local := responder.local
	if err := responder.Close(); err != nil {
		t.Fatalf("close: %v", err)
	}
	if err := responder.Close(); err != nil {
		t.Fatalf("second close: %v", err)
	}
	if _, err := os.Stat(local); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("responder socket was not removed: %v", err)
	}
}

func TestIOLImageL1Advertised(t *testing.T) {
	usage := "Options:\n-e Number of Ethernet interfaces (default 2)\n-l Enable Layer 1 keepalive messages\n-u UDP port base\n"
	if !iolL1Advertised(usage) {
		t.Fatal("expected -l to be detected")
	}
	if iolL1Advertised("Usage: iou [options]\n-e 2\n") {
		t.Fatal("usage without -l must not advertise keepalives")
	}
	if iolL1Advertised("") {
		t.Fatal("empty usage must not advertise keepalives")
	}
	if !iolL1Advertised("  -l\tEnable Layer 1 keepalives\n") {
		t.Fatal("expected -l line to be detected")
	}
}
