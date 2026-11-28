// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"context"
	"encoding/binary"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

// IOL layer-1 keepalives use a channel separate from the netio data socket:
// the binary hard-codes /tmp/netl1<uid>/L<instance> (independent of TMPDIR)
// and exchanges 8-byte datagrams there. TeraLab impersonates the NETMAP peer
// on that channel so IOS reports the line protocol from the real topology.
//
// Protocol reference: GNS3 issue #14 and PR #2826 (GPL-3.0 project). Only the
// wire format and socket layout are used; this implementation is independent.
const (
	iouL1MessageSize   = 8
	iouL1MessageType   = 3
	iouL1Channel       = 0
	iouL1Interval      = time.Second
	iouL1ProbeTimeout  = 3 * time.Second
	iouL1ReadBufferLen = 256
)

// iolL1Directory is the directory IOL uses for layer-1 keepalive sockets.
func iolL1Directory(uid int) string {
	return filepath.Join(iolSocketRoot, fmt.Sprintf("netl1%d", uid))
}

// iolL1Path is one instance endpoint on the layer-1 channel. The file name is
// "L1" followed by the instance id (e.g. L1299), not "L" + id.
func iolL1Path(uid, instance int) string {
	return filepath.Join(iolL1Directory(uid), fmt.Sprintf("L1%d", instance))
}

// iouL1Keepalive is the 8-byte message IOL exchanges on the L1 channel.
//
//	0-1 destination instance (big-endian)
//	2-3 source instance (big-endian)
//	4   destination interface: unit/port in the high nibble, bay in the low
//	5   source interface: same encoding
//	6   message type (0x03)
//	7   channel (0x00)
type iouL1Keepalive struct {
	Destination          uint16
	Source               uint16
	DestinationInterface byte
	SourceInterface      byte
	Type                 byte
	Channel              byte
}

func (message iouL1Keepalive) marshal() []byte {
	buffer := make([]byte, iouL1MessageSize)
	binary.BigEndian.PutUint16(buffer[0:2], message.Destination)
	binary.BigEndian.PutUint16(buffer[2:4], message.Source)
	buffer[4] = message.DestinationInterface
	buffer[5] = message.SourceInterface
	buffer[6] = message.Type
	buffer[7] = message.Channel

	return buffer
}

func parseIOUL1Keepalive(data []byte) (iouL1Keepalive, bool) {
	if len(data) != iouL1MessageSize {
		return iouL1Keepalive{}, false
	}

	return iouL1Keepalive{
		Destination:          binary.BigEndian.Uint16(data[0:2]),
		Source:               binary.BigEndian.Uint16(data[2:4]),
		DestinationInterface: data[4],
		SourceInterface:      data[5],
		Type:                 data[6],
		Channel:              data[7],
	}, true
}

// iolL1Responder answers IOL's layer-1 keepalives for interfaces that have a
// bridge attached and stays silent for unlinked interfaces, so the line
// protocol follows the topology.
type iolL1Responder struct {
	conn      *net.UnixConn
	local     string
	target    *net.UnixAddr
	appID     int
	peerID    int
	linked    []bool
	interval  time.Duration
	done      chan struct{}
	closeOnce sync.Once
}

// startIOLL1 prepares the per-node endpoints. It removes a stale endpoint for
// both sides: a crashed run (or an internal IOS restart) can leave the
// instance's socket behind, which would make IOL's bind fail silently.
func startIOLL1(appID, peerID int, identity labIdentity, linked []bool) (*iolL1Responder, error) {
	uid := int(identity.UID)
	directory := iolL1Directory(uid)
	if err := prepareIOSocketDir(directory, iolL1Directory(uid), identity); err != nil {
		return nil, err
	}

	return startIOLL1In(directory, appID, peerID, linked, identity)
}

func iolL1PathIn(directory string, instance int) string {
	return filepath.Join(directory, fmt.Sprintf("L1%d", instance))
}

func startIOLL1In(directory string, appID, peerID int, linked []bool, identity labIdentity) (*iolL1Responder, error) {
	return startIOLL1WithInterval(directory, appID, peerID, linked, identity, iouL1Interval)
}

func startIOLL1WithInterval(directory string, appID, peerID int, linked []bool, identity labIdentity, interval time.Duration) (*iolL1Responder, error) {
	uid := int(identity.UID)
	// IOL binds L<appID>; a stale file from a crashed run (or an internal IOS
	// restart) would make that bind fail silently.
	_ = os.Remove(iolL1PathIn(directory, appID))

	local := iolL1PathIn(directory, peerID)
	_ = os.Remove(local)
	address := &net.UnixAddr{Name: local, Net: "unixgram"}
	conn, err := net.ListenUnixgram("unixgram", address)
	if err != nil {
		return nil, fmt.Errorf("bind iol l1 keepalive socket: %w", err)
	}
	if err := secureIOLSocketFile(local, uid, int(identity.GID)); err != nil {
		_ = conn.Close()
		_ = os.Remove(local)

		return nil, err
	}

	responder := &iolL1Responder{
		conn:     conn,
		local:    local,
		target:   &net.UnixAddr{Name: iolL1PathIn(directory, appID), Net: "unixgram"},
		appID:    appID,
		peerID:   peerID,
		linked:   append([]bool(nil), linked...),
		interval: interval,
		done:     make(chan struct{}),
	}

	go responder.readLoop()
	go responder.tickLoop()

	return responder, nil
}

// readLoop echoes keepalives the instance sends to its NETMAP peer, but only
// for interfaces that are actually linked.
func (r *iolL1Responder) readLoop() {
	buffer := make([]byte, iouL1ReadBufferLen)
	for {
		count, _, err := r.conn.ReadFromUnix(buffer)
		if err != nil {
			return
		}
		message, ok := parseIOUL1Keepalive(buffer[:count])
		if !ok || message.Type != iouL1MessageType || message.Channel != iouL1Channel {
			continue
		}
		if int(message.Destination) != r.peerID || int(message.Source) != r.appID {
			continue
		}
		index := iolInterfaceIndex(message.SourceInterface)
		if index < 0 || index >= len(r.linked) || !r.linked[index] {
			continue
		}
		reply := iouL1Keepalive{
			Destination:          message.Source,
			Source:               message.Destination,
			DestinationInterface: message.SourceInterface,
			SourceInterface:      message.DestinationInterface,
			Type:                 message.Type,
			Channel:              message.Channel,
		}
		_, _ = r.conn.WriteToUnix(reply.marshal(), r.target)
	}
}

// tickLoop keeps linked interfaces up. IOS drops the line protocol after a
// few seconds without keepalives, so one message per linked port per second.
func (r *iolL1Responder) tickLoop() {
	ticker := time.NewTicker(r.interval)
	defer ticker.Stop()
	for {
		select {
		case <-r.done:
			return
		case <-ticker.C:
			r.sendKeepalives()
		}
	}
}

func (r *iolL1Responder) sendKeepalives() {
	for index, linked := range r.linked {
		if !linked {
			continue
		}
		port := iolPortByte(index)
		message := iouL1Keepalive{
			Destination:          uint16(r.appID),
			Source:               uint16(r.peerID),
			DestinationInterface: port,
			SourceInterface:      port,
			Type:                 iouL1MessageType,
			Channel:              iouL1Channel,
		}
		// The instance creates its endpoint during bootstrap; sends before
		// that (or during shutdown) fail harmlessly.
		_, _ = r.conn.WriteToUnix(message.marshal(), r.target)
	}
}

func (r *iolL1Responder) Close() error {
	r.closeOnce.Do(func() {
		close(r.done)
		_ = r.conn.Close()
		_ = os.Remove(r.local)
	})

	return nil
}

// iolImageSupportsL1 probes the image's usage output for the -l option.
// IOL prints the usage before loading a licence, so this is cheap. The probe
// runs with the engine user's privileges: the image is user-supplied and must
// never execute as root.
func (m *Manager) iolImageSupportsL1(identity labIdentity, image string) bool {
	ctx, cancel := context.WithTimeout(context.Background(), iouL1ProbeTimeout)
	defer cancel()
	command := exec.CommandContext(ctx, image, "-h")
	command.Env = []string{
		"PATH=/usr/local/bin:/usr/bin:/bin",
		"LD_LIBRARY_PATH=" + filepath.Dir(image),
		"TERM=dumb",
	}
	identity.apply(command)
	output, _ := command.CombinedOutput()

	return iolL1Advertised(string(output))
}

// iolL1Advertised recognises the -l option in IOL usage text.
func iolL1Advertised(usage string) bool {
	lower := strings.ToLower(usage)
	if strings.Contains(lower, "layer 1 keepalive") {
		return true
	}
	for _, line := range strings.Split(usage, "\n") {
		if strings.HasPrefix(strings.TrimSpace(line), "-l") {
			return true
		}
	}

	return false
}
