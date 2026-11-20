// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"strconv"
	"sync"
)

const (
	iouHeaderSize = 8
	iouMsgType    = 6
	iouMsgData    = 1
)

// iolPeerID derives the synthetic peer node id used in the IOL NETMAP.
func iolPeerID(appID int) int {
	peer := ((appID + 500) % 1023) + 1
	if peer == appID {
		peer = (peer % 1023) + 1
	}

	return peer
}

func packIOUAppID(id int) uint16 {
	return uint16(id & 0x7ff)
}

// iolPortByte encodes an interface index the way Cisco IOL numbers ports:
// four ports per adapter, the port in the high nibble and the adapter in the
// low nibble (Ethernet0/0 = 0x00, Ethernet0/1 = 0x10, Ethernet1/0 = 0x01).
func iolPortByte(index int) byte {
	bay := index / 4
	unit := index % 4

	return byte(bay | unit<<4)
}

func iolInterfaceIndex(port byte) int {
	bay := int(port & 0x0f)
	unit := int(port >> 4)

	return bay*4 + unit
}

func buildIOUHeader(dstID, dstPort, srcID, srcPort int) []byte {
	header := make([]byte, iouHeaderSize)
	binary.BigEndian.PutUint16(header[0:2], packIOUAppID(dstID))
	binary.BigEndian.PutUint16(header[2:4], packIOUAppID(srcID))
	header[4] = byte(dstPort)
	header[5] = byte(srcPort)
	header[iouMsgType] = iouMsgData

	return header
}

func iolNetioPath(uid int, appID int) string {
	return fmt.Sprintf("/tmp/netio%d/%d", uid, appID)
}

// iolNetioRelay bridges an IOL instance's Unix datagram netio socket to taps.
type iolNetioRelay struct {
	conn     *net.UnixConn
	local    string
	target   *net.UnixAddr
	appID    int
	peerID   int
	taps     []*os.File
	done     chan struct{}
	closeOne sync.Once
}

func startIOLNetio(appID, peerID, uid int, tapNames []string) (*iolNetioRelay, error) {
	directory := filepath.Dir(iolNetioPath(uid, peerID))
	if err := os.MkdirAll(directory, 0o770); err != nil {
		return nil, fmt.Errorf("create iol netio directory: %w", err)
	}
	if uid > 0 {
		_ = os.Chown(directory, uid, uid)
	}
	local := iolNetioPath(uid, peerID)
	_ = os.Remove(local)
	address := &net.UnixAddr{Name: local, Net: "unixgram"}
	conn, err := net.ListenUnixgram("unixgram", address)
	if err != nil {
		return nil, fmt.Errorf("bind iol netio socket: %w", err)
	}
	_ = os.Chmod(local, 0o660)
	if uid > 0 {
		_ = os.Chown(local, uid, uid)
	}

	relay := &iolNetioRelay{
		conn:   conn,
		local:  local,
		target: &net.UnixAddr{Name: iolNetioPath(uid, appID), Net: "unixgram"},
		appID:  appID,
		peerID: peerID,
		done:   make(chan struct{}),
	}
	for _, tapName := range tapNames {
		tap, err := openTap(tapName)
		if err != nil {
			_ = relay.Close()
			return nil, err
		}
		relay.taps = append(relay.taps, tap)
	}

	go relay.readNetio()
	for index, tap := range relay.taps {
		go relay.readTap(index, tap)
	}

	return relay, nil
}

func (r *iolNetioRelay) readNetio() {
	buffer := make([]byte, 65536)
	for {
		count, _, err := r.conn.ReadFromUnix(buffer)
		if err != nil {
			return
		}
		if count <= iouHeaderSize {
			continue
		}
		if int(binary.BigEndian.Uint16(buffer[0:2])&0x7ff) != r.peerID {
			continue
		}
		index := iolInterfaceIndex(buffer[4])
		if index < 0 || index >= len(r.taps) {
			continue
		}
		if _, err := r.taps[index].Write(buffer[iouHeaderSize:count]); err != nil {
			return
		}
	}
}

func (r *iolNetioRelay) readTap(index int, tap *os.File) {
	buffer := make([]byte, 65536)
	header := buildIOUHeader(r.appID, int(iolPortByte(index)), r.peerID, int(iolPortByte(index)))
	frame := make([]byte, 0, iouHeaderSize+65536)
	for {
		count, err := tap.Read(buffer)
		if err != nil {
			return
		}
		frame = append(frame[:0], header...)
		frame = append(frame, buffer[:count]...)
		if _, err := r.conn.WriteToUnix(frame, r.target); err != nil {
			if errors.Is(err, os.ErrNotExist) {
				continue
			}
			return
		}
	}
}

func (r *iolNetioRelay) Close() error {
	r.closeOne.Do(func() {
		close(r.done)
		_ = r.conn.Close()
		_ = os.Remove(r.local)
		for _, tap := range r.taps {
			_ = tap.Close()
		}
	})

	return nil
}

var _ io.Closer = (*iolNetioRelay)(nil)

// iolNetmapLine renders one NETMAP segment pairing the IOL node with the
// relay. IOL addresses ports as adapter/port with four ports per adapter.
func iolNetmapLine(appID, peerID, index int) string {
	bay := index / 4
	unit := index % 4
	port := strconv.Itoa(bay) + "/" + strconv.Itoa(unit)

	return strconv.Itoa(appID) + ":" + port +
		"   " + strconv.Itoa(peerID) + ":" + port +
		"  /* " + port + " */"
}
