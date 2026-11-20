// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"crypto/sha256"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/creack/pty"
)

// BuildIOLArgs renders argv for an IOL instance.
//
// IOL is a user-supplied binary; TeraLab never redistributes it. The -l flag
// enables layer-1 keepalives, which the agent answers on a separate channel
// so interface state follows the topology.
func BuildIOLArgs(spec Spec, appID int, l1Keepalive bool) ([]string, error) {
	if appID < 1 || appID > 1023 {
		return nil, errors.New("iol application id must be between 1 and 1023")
	}

	args := []string{
		"-e", strconv.Itoa(spec.Ethernet),
		"-s", "0",
		"-m", strconv.Itoa(spec.RAM),
		"-n", strconv.Itoa(spec.NVRAM),
	}
	if l1Keepalive {
		args = append(args, "-l")
	}

	return append(args, strconv.Itoa(appID)), nil
}

// buildNETMAP renders IOL interface netmap lines: local UDP port, peer host,
// peer UDP port. IOL listens on the local port and sends to the peer.
func buildNETMAP(pairs [][2]int) string {
	var builder strings.Builder
	for _, pair := range pairs {
		fmt.Fprintf(&builder, "%d:127.0.0.1:%d\n", pair[0], pair[1])
	}

	return builder.String()
}

// iolAppID derives a stable instance identifier for a node.
func iolAppID(spec Spec) int {
	sum := sha256.Sum256([]byte("iol:" + spec.LabID + ":" + spec.ID))

	return (int(sum[0])<<8|int(sum[1]))%1023 + 1
}

func (m *Manager) startIOL(spec Spec, runDir string) (NodeInfo, error) {
	if err := ensureExecutable(spec.Image); err != nil {
		return NodeInfo{}, fmt.Errorf("%w: iol image is not executable: %v", ErrEngineUnavailable, err)
	}
	iourc := spec.Iourc
	if iourc == "" {
		iourc = m.cfg.IOLIourc
	}
	if _, err := os.Stat(iourc); err != nil {
		return NodeInfo{}, fmt.Errorf(
			"%w: iourc not found at %s; install the user-supplied licence file",
			ErrEngineUnavailable,
			iourc,
		)
	}

	appID := iolAppID(spec)
	peerID := iolPeerID(appID)
	l1Keepalive := m.iolImageSupportsL1(spec.Image)
	if !l1Keepalive {
		m.logger.Info("iol image does not advertise layer-1 keepalives; interface state will not follow the topology",
			"image", spec.Image, "node", spec.ID)
	}
	args, err := BuildIOLArgs(spec, appID, l1Keepalive)
	if err != nil {
		return NodeInfo{}, err
	}

	taps := make([]string, 0, spec.Ethernet)
	linked := make([]bool, spec.Ethernet)
	netmapLines := make([]string, 0, spec.Ethernet)
	for index := 0; index < spec.Ethernet; index++ {
		tap := spec.TapName(index)
		if err := m.net.CreateTap(tap, spec.Networks[index]); err != nil {
			m.cleanupTaps(taps)
			return NodeInfo{}, err
		}
		taps = append(taps, tap)
		linked[index] = strings.TrimSpace(spec.Networks[index]) != ""
		netmapLines = append(netmapLines, iolNetmapLine(appID, peerID, index))
	}

	relay, err := startIOLNetio(appID, peerID, int(m.cfg.VMUID), taps)
	if err != nil {
		m.cleanupTaps(taps)
		return NodeInfo{}, err
	}
	relays := []io.Closer{relay}
	var l1 *iolL1Responder
	if l1Keepalive {
		l1, err = startIOLL1(appID, peerID, int(m.cfg.VMUID), linked)
		if err != nil {
			_ = relay.Close()
			m.cleanupTaps(taps)
			return NodeInfo{}, err
		}
		relays = append(relays, l1)
	}
	cleanup := func() {
		for _, closer := range relays {
			_ = closer.Close()
		}
		m.cleanupTaps(taps)
	}

	if err := m.prepareIOLFiles(runDir, iourc, strings.Join(netmapLines, "\n")+"\n"); err != nil {
		cleanup()
		return NodeInfo{}, err
	}

	if spec.Config != "" {
		if err := os.WriteFile(filepath.Join(runDir, "startup-config"), []byte(spec.Config), 0o640); err != nil {
			cleanup()
			return NodeInfo{}, fmt.Errorf("write iol startup configuration: %w", err)
		}
		m.chown(filepath.Join(runDir, "startup-config"))
		args = append(args, "-c", "startup-config")
	}

	ptmx, tty, err := pty.Open()
	if err != nil {
		cleanup()
		return NodeInfo{}, fmt.Errorf("open iol pty: %w", err)
	}
	logFile, err := os.OpenFile(filepath.Join(runDir, "engine.log"), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o640)
	if err != nil {
		_ = ptmx.Close()
		_ = tty.Close()
		cleanup()
		return NodeInfo{}, fmt.Errorf("open iol log: %w", err)
	}

	command := exec.Command(spec.Image, args...)
	command.Dir = runDir
	command.Env = []string{
		"PATH=/usr/local/bin:/usr/bin:/bin",
		"HOME=" + runDir,
		"IOURC=" + filepath.Join(runDir, "iourc"),
		"LD_LIBRARY_PATH=" + filepath.Dir(spec.Image),
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
		cleanup()
		return NodeInfo{}, fmt.Errorf("start iol: %w", err)
	}
	_ = tty.Close()
	_ = logFile.Close()

	cgroupPath, err := m.applyCgroup(command.Process.Pid, spec)
	if err != nil {
		_ = command.Process.Kill()
		_ = command.Wait()
		_ = ptmx.Close()
		cleanup()
		return NodeInfo{}, err
	}

	node := &Node{
		Spec:        spec,
		Cmd:         command,
		PTY:         ptmx,
		Relays:      relays,
		RunDir:      runDir,
		Taps:        taps,
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

// prepareIOLFiles places the licence and interface map where IOL expects them.
func (m *Manager) prepareIOLFiles(runDir, iourc, netmap string) error {
	licence, err := os.ReadFile(iourc)
	if err != nil {
		return fmt.Errorf("%w: cannot read iourc: %v", ErrEngineUnavailable, err)
	}
	licencePath := filepath.Join(runDir, "iourc")
	if err := os.WriteFile(licencePath, licence, 0o640); err != nil {
		return fmt.Errorf("write iol licence: %w", err)
	}
	m.chown(licencePath)

	netmapPath := filepath.Join(runDir, "NETMAP")
	if err := os.WriteFile(netmapPath, []byte(netmap), 0o640); err != nil {
		return fmt.Errorf("write iol netmap: %w", err)
	}
	m.chown(netmapPath)

	return nil
}

// ensureExecutable repairs uploaded engine binaries stored without an exec bit.
func ensureExecutable(path string) error {
	info, err := os.Stat(path)
	if err != nil {
		return err
	}
	if info.Mode()&0o111 != 0 {
		return nil
	}

	return os.Chmod(path, info.Mode().Perm()|0o111)
}
