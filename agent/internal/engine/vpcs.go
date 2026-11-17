// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"crypto/sha256"
	"errors"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"time"

	"github.com/creack/pty"
	"golang.org/x/sys/unix"
)

// vpcsRelay bridges a VPCS UDP socket pair to an Ethernet tap.
//
// VPCS exchanges raw Ethernet frames over UDP in its default mode; the relay
// gives it a real lab-bridge interface while keeping all traffic on loopback.
type vpcsRelay struct {
	udp    *net.UDPConn
	tap    *os.File
	target *net.UDPAddr
}

func (r *vpcsRelay) Close() error {
	_ = r.udp.Close()
	if r.tap != nil {
		return r.tap.Close()
	}

	return nil
}

func startVPCSRelay(tap string, listenPort, targetPort int) (*vpcsRelay, error) {
	udp, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: listenPort})
	if err != nil {
		return nil, fmt.Errorf("bind vpcs relay: %w", err)
	}
	tapFile, err := openTap(tap)
	if err != nil {
		_ = udp.Close()
		return nil, err
	}

	relay := &vpcsRelay{
		udp:    udp,
		tap:    tapFile,
		target: &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: targetPort},
	}

	go func() {
		buffer := make([]byte, 65536)
		for {
			read, readErr := tapFile.Read(buffer)
			if readErr != nil {
				return
			}
			if _, writeErr := udp.WriteToUDP(buffer[:read], relay.target); writeErr != nil {
				return
			}
		}
	}()

	go func() {
		buffer := make([]byte, 65536)
		for {
			read, _, readErr := udp.ReadFromUDP(buffer)
			if readErr != nil {
				return
			}
			if _, writeErr := tapFile.Write(buffer[:read]); writeErr != nil {
				return
			}
		}
	}()

	return relay, nil
}

func openTap(name string) (*os.File, error) {
	fd, err := unix.Open("/dev/net/tun", unix.O_RDWR|unix.O_CLOEXEC, 0)
	if err != nil {
		return nil, fmt.Errorf("open tun device: %w", err)
	}
	ifreq, err := unix.NewIfreq(name)
	if err != nil {
		_ = unix.Close(fd)
		return nil, fmt.Errorf("tap name: %w", err)
	}
	ifreq.SetUint16(unix.IFF_TAP | unix.IFF_NO_PI)
	if err := unix.IoctlIfreq(fd, unix.TUNSETIFF, ifreq); err != nil {
		_ = unix.Close(fd)
		return nil, fmt.Errorf("attach to tap %s: %w", name, err)
	}

	return os.NewFile(uintptr(fd), name), nil
}

func freeUDPPortPair() (int, int, error) {
	first, err := freeUDPPort()
	if err != nil {
		return 0, 0, err
	}
	for attempt := 0; attempt < 20; attempt++ {
		second, err := freeUDPPort()
		if err != nil {
			return 0, 0, err
		}
		if second != first {
			return first, second, nil
		}
	}

	return 0, 0, errors.New("cannot reserve two vpcs udp ports")
}

func freeUDPPort() (int, error) {
	connection, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		return 0, err
	}
	port := connection.LocalAddr().(*net.UDPAddr).Port
	_ = connection.Close()

	return port, nil
}

func vpcsMACOffset(spec Spec) int {
	sum := sha256.Sum256([]byte("vpcs:" + spec.LabID + ":" + spec.ID))

	return int(sum[0])
}

func (m *Manager) startVPCS(spec Spec, runDir string) (NodeInfo, error) {
	if _, err := os.Stat(m.cfg.VPCSBinary); err != nil {
		return NodeInfo{}, fmt.Errorf("%w: vpcs binary not found at %s", ErrEngineUnavailable, m.cfg.VPCSBinary)
	}

	tap := spec.TapName(0)
	bridge := spec.Networks[0]
	if err := m.net.CreateTap(tap, bridge); err != nil {
		return NodeInfo{}, err
	}

	listenPort, sendPort, err := freeUDPPortPair()
	if err != nil {
		m.cleanupTaps([]string{tap})
		return NodeInfo{}, err
	}
	relay, err := startVPCSRelay(tap, sendPort, listenPort)
	if err != nil {
		m.cleanupTaps([]string{tap})
		return NodeInfo{}, err
	}

	ptmx, tty, err := pty.Open()
	if err != nil {
		_ = relay.Close()
		m.cleanupTaps([]string{tap})
		return NodeInfo{}, fmt.Errorf("open vpcs pty: %w", err)
	}
	logFile, err := os.OpenFile(filepath.Join(runDir, "engine.log"), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o640)
	if err != nil {
		_ = ptmx.Close()
		_ = tty.Close()
		_ = relay.Close()
		m.cleanupTaps([]string{tap})
		return NodeInfo{}, fmt.Errorf("open vpcs log: %w", err)
	}

	command := exec.Command(
		m.cfg.VPCSBinary,
		"-i", "1",
		"-s", fmt.Sprint(listenPort),
		"-c", fmt.Sprint(sendPort),
		"-t", "127.0.0.1",
		"-m", fmt.Sprint(vpcsMACOffset(spec)),
	)
	command.Dir = runDir
	command.Env = []string{
		"PATH=/usr/local/bin:/usr/bin:/bin",
		"HOME=" + runDir,
		"TERM=vt100",
	}
	command.Stdin = tty
	command.Stdout = tty
	command.Stderr = logFile
	m.limitProcess(command)

	if err := command.Start(); err != nil {
		_ = logFile.Close()
		_ = ptmx.Close()
		_ = tty.Close()
		_ = relay.Close()
		m.cleanupTaps([]string{tap})
		return NodeInfo{}, fmt.Errorf("start vpcs: %w", err)
	}
	_ = tty.Close()
	_ = logFile.Close()

	cgroupPath, err := m.applyCgroup(command.Process.Pid, spec)
	if err != nil {
		_ = command.Process.Kill()
		_ = command.Wait()
		_ = ptmx.Close()
		_ = relay.Close()
		m.cleanupTaps([]string{tap})
		return NodeInfo{}, err
	}

	node := &Node{
		Spec:        spec,
		Cmd:         command,
		PTY:         ptmx,
		Relay:       relay,
		RunDir:      runDir,
		Taps:        []string{tap},
		CgroupPath:  cgroupPath,
		State:       "running",
		Started:     time.Now(),
		Done:        make(chan struct{}),
		consoleDone: make(chan struct{}),
		subscribers: map[chan []byte]struct{}{},
	}
	go m.pumpPTY(node)

	return m.register(node)
}
