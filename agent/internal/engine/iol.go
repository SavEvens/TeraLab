// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"crypto/sha256"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"time"

	"github.com/creack/pty"
)

// BuildIOLArgs renders argv for an IOL instance.
//
// IOL is a user-supplied binary; TeraLab never redistributes it.
func BuildIOLArgs(spec Spec, appID int) ([]string, error) {
	if spec.Ethernet != 0 {
		return nil, errors.New("iol ethernet bridging is not implemented; set ethernet to 0")
	}
	if appID < 1 || appID > 1023 {
		return nil, errors.New("iol application id must be between 1 and 1023")
	}

	return []string{
		"-e", "0",
		"-s", "0",
		"-m", strconv.Itoa(spec.RAM),
		"-n", strconv.Itoa(spec.NVRAM),
		strconv.Itoa(appID),
	}, nil
}

// iolAppID derives a stable instance identifier for a node.
func iolAppID(spec Spec) int {
	sum := sha256.Sum256([]byte("iol:" + spec.LabID + ":" + spec.ID))

	return (int(sum[0])<<8|int(sum[1]))%1023 + 1
}

func (m *Manager) startIOL(spec Spec, runDir string) (NodeInfo, error) {
	info, err := os.Stat(spec.Image)
	if err != nil {
		return NodeInfo{}, fmt.Errorf("%w: iol image is missing", ErrEngineUnavailable)
	}
	if info.Mode()&0o111 == 0 {
		return NodeInfo{}, fmt.Errorf("%w: iol image is not executable", ErrEngineUnavailable)
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
	if appID < 1 {
		appID = 1
	}
	args, err := BuildIOLArgs(spec, appID)
	if err != nil {
		return NodeInfo{}, err
	}
	if err := m.prepareIOLFiles(runDir, iourc); err != nil {
		return NodeInfo{}, err
	}

	ptmx, tty, err := pty.Open()
	if err != nil {
		return NodeInfo{}, fmt.Errorf("open iol pty: %w", err)
	}
	logFile, err := os.OpenFile(filepath.Join(runDir, "engine.log"), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o640)
	if err != nil {
		_ = ptmx.Close()
		_ = tty.Close()
		return NodeInfo{}, fmt.Errorf("open iol log: %w", err)
	}

	command := exec.Command(spec.Image, args...)
	command.Dir = runDir
	command.Env = []string{
		"PATH=/usr/local/bin:/usr/bin:/bin",
		"HOME=" + runDir,
		"IOURC=" + iourc,
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
		return NodeInfo{}, fmt.Errorf("start iol: %w", err)
	}
	_ = tty.Close()
	_ = logFile.Close()

	cgroupPath, err := m.applyCgroup(command.Process.Pid, spec)
	if err != nil {
		_ = command.Process.Kill()
		_ = command.Wait()
		_ = ptmx.Close()
		return NodeInfo{}, err
	}

	node := &Node{
		Spec:        spec,
		Cmd:         command,
		PTY:         ptmx,
		RunDir:      runDir,
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
func (m *Manager) prepareIOLFiles(runDir, iourc string) error {
	licence, err := os.ReadFile(iourc)
	if err != nil {
		return fmt.Errorf("%w: cannot read iourc: %v", ErrEngineUnavailable, err)
	}
	licencePath := filepath.Join(runDir, "iourc")
	if err := os.WriteFile(licencePath, licence, 0o640); err != nil {
		return fmt.Errorf("write iol licence: %w", err)
	}
	m.chown(licencePath)

	netmap := filepath.Join(runDir, "NETMAP")
	if err := os.WriteFile(netmap, []byte{}, 0o640); err != nil {
		return fmt.Errorf("write iol netmap: %w", err)
	}
	m.chown(netmap)

	return nil
}
