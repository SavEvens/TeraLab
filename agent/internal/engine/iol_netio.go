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
	"syscall"

	"golang.org/x/sys/unix"
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

// iolSocketRoot is the parent of IOL's hard-coded per-uid netio and layer-1
// socket directories. The binary appends the real uid itself, so the agent
// cannot relocate the sockets into the run directory; a distinct per-lab uid
// makes /tmp/netio<uid> a private namespace. Tests point the agent half at a
// temporary root.
var iolSocketRoot = "/tmp"

func iolNetioDirectory(uid int) string {
	return filepath.Join(iolSocketRoot, fmt.Sprintf("netio%d", uid))
}

func iolNetioPath(uid int, appID int) string {
	return filepath.Join(iolNetioDirectory(uid), strconv.Itoa(appID))
}

// prepareIOSocketDir creates or validates one fixed per-uid IOL socket
// directory (netio<uid> or netl1<uid>). The path is a security boundary: a
// compromised lab engine shares /tmp and can plant a symlink with that name, so
// the agent must never create a parent chain through it, follow a planted link,
// or chown the link target. A missing directory is created one level at a time
// and then opened once; every ownership/mode change after that goes through the
// open descriptor, so a uid that owns the parent cannot redirect it by swapping
// the path for a symlink. An existing directory must already be a real
// directory owned by the lab uid, and is never repaired or removed.
func prepareIOSocketDir(path, expected string, identity labIdentity) error {
	if filepath.Clean(path) != filepath.Clean(expected) {
		return fmt.Errorf("refusing unexpected iol socket directory %q (expected %q)", path, expected)
	}
	// Single level only: MkdirAll would silently traverse a planted symlink in
	// /tmp. EEXIST means the directory already exists (or another caller raced
	// us); it is re-validated as pre-existing below. Any other error fails
	// closed.
	created := false
	if err := os.Mkdir(path, 0o770); err != nil {
		if !errors.Is(err, os.ErrExist) {
			return fmt.Errorf("create iol socket directory %q: %w", path, err)
		}
	} else {
		created = true
	}

	file, err := openIOSocketDir(path)
	if err != nil {
		return err
	}
	defer file.Close()

	return secureIOSocketDir(path, file, identity, created)
}

// openIOSocketDir opens the socket directory itself without following a final
// symlink and without accepting a non-directory. It is the only path lookup
// after creation: from here the ownership and mode changes use the descriptor,
// which is pinned to the inode that was opened.
func openIOSocketDir(path string) (*os.File, error) {
	file, err := os.OpenFile(path, os.O_RDONLY|syscall.O_DIRECTORY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		if errors.Is(err, syscall.ELOOP) || errors.Is(err, syscall.ENOTDIR) {
			return nil, fmt.Errorf("iol socket directory %q is not a real directory", path)
		}

		return nil, fmt.Errorf("open iol socket directory %q: %w", path, err)
	}

	return file, nil
}

// secureIOSocketDir applies the owner and private mode to an already-open
// directory. created distinguishes a directory this call made (owned by the
// agent until the Chown) from a pre-existing one, which must already be owned by
// the lab uid before the agent adopts it.
func secureIOSocketDir(path string, file *os.File, identity labIdentity, created bool) error {
	uid := int(identity.UID)
	gid := int(identity.GID)

	info, err := file.Stat()
	if err != nil {
		return fmt.Errorf("stat iol socket directory %q: %w", path, err)
	}
	if err := requireRealDirectory(path, info); err != nil {
		return err
	}
	// Only an existing, pre-agent directory has to be owned by the lab uid
	// before we touch it. A directory we just created belongs to the agent
	// until the Chown below.
	if !created && os.Geteuid() == 0 {
		if err := requireDirectoryOwner(path, info, uid); err != nil {
			return err
		}
	}
	if os.Geteuid() == 0 {
		if err := file.Chown(uid, gid); err != nil {
			return fmt.Errorf("own iol socket directory %q: %w", path, err)
		}
	}
	if err := file.Chmod(0o770); err != nil {
		return fmt.Errorf("secure iol socket directory %q: %w", path, err)
	}
	// Re-verify on the same descriptor after the ownership change: the inode
	// must still be a real directory and must now belong to the lab uid.
	info, err = file.Stat()
	if err != nil {
		return fmt.Errorf("restat iol socket directory %q: %w", path, err)
	}
	if err := requireRealDirectory(path, info); err != nil {
		return err
	}
	if os.Geteuid() == 0 {
		if err := requireDirectoryOwner(path, info, uid); err != nil {
			return err
		}
	}

	return nil
}

// secureIOLSocketFile applies the socket mode and ownership to the filesystem
// inode behind a freshly bound Unix socket path.
//
// The bound connection's descriptor cannot be used for this: fstat/fchmod on a
// bound Unix socket address the sockfs inode, not the directory entry that
// bind(2) created, so conn.File().Chmod would silently leave the pathname's
// mode and owner untouched. Instead the directory entry is opened once with
// O_PATH|O_NOFOLLOW, which pins the filesystem inode without following a
// planted symlink, and chmod/chown go through /proc/self/fd, which resolves to
// that pinned inode. Replacing the path afterwards cannot redirect them.
func secureIOLSocketFile(local string, uid, gid int) error {
	file, err := os.OpenFile(local, unix.O_PATH|unix.O_NOFOLLOW, 0)
	if err != nil {
		return fmt.Errorf("open iol socket inode: %w", err)
	}
	defer file.Close()

	info, err := file.Stat()
	if err != nil {
		return fmt.Errorf("stat iol socket inode: %w", err)
	}
	if info.Mode()&os.ModeSocket == 0 {
		return fmt.Errorf("iol socket path %q is not a socket", local)
	}

	// /proc/self/fd/<fd> is the pinned inode, not a path lookup in the
	// lab-owned directory.
	proc := fmt.Sprintf("/proc/self/fd/%d", file.Fd())
	if err := os.Chmod(proc, 0o660); err != nil {
		return fmt.Errorf("set iol socket mode: %w", err)
	}
	if uid > 0 {
		if err := os.Chown(proc, uid, gid); err != nil {
			// Tests and dev runs are unprivileged; production runs as root
			// and a failed ownership change is fatal.
			if os.Geteuid() == 0 {
				return fmt.Errorf("own iol socket: %w", err)
			}
		}
	}

	return nil
}

// requireRealDirectory rejects a symlink or non-directory for the inode behind
// an already-open descriptor. The open itself uses O_NOFOLLOW|O_DIRECTORY, so
// this is a second check on the pinned inode rather than a path lookup.
func requireRealDirectory(path string, info os.FileInfo) error {
	if info.Mode()&os.ModeSymlink != 0 || !info.IsDir() {
		return fmt.Errorf("iol socket directory %q is not a real directory", path)
	}

	return nil
}

// requireDirectoryOwner rejects a directory that is not owned by the lab uid.
func requireDirectoryOwner(path string, info os.FileInfo, uid int) error {
	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		return fmt.Errorf("cannot determine ownership of iol socket directory %q", path)
	}
	if int(stat.Uid) != uid {
		return fmt.Errorf(
			"iol socket directory %q is owned by uid %d, not the lab uid %d",
			path,
			stat.Uid,
			uid,
		)
	}

	return nil
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

func startIOLNetio(appID, peerID int, identity labIdentity, tapNames []string) (*iolNetioRelay, error) {
	uid := int(identity.UID)
	directory := iolNetioDirectory(uid)
	if err := prepareIOSocketDir(directory, iolNetioDirectory(uid), identity); err != nil {
		return nil, err
	}
	local := iolNetioPath(uid, peerID)
	_ = os.Remove(local)
	address := &net.UnixAddr{Name: local, Net: "unixgram"}
	conn, err := net.ListenUnixgram("unixgram", address)
	if err != nil {
		return nil, fmt.Errorf("bind iol netio socket: %w", err)
	}
	if err := secureIOLSocketFile(local, uid, int(identity.GID)); err != nil {
		_ = conn.Close()
		_ = os.Remove(local)

		return nil, err
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
		tap, err := openTapFile(tapName)
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
