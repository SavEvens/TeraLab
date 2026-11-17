// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"errors"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"time"
)

// BuildDynamipsArgs renders direct-mode argv for a Dynamips platform.
//
// The IOS image is user-supplied; TeraLab never bundles or redistributes it.
func BuildDynamipsArgs(spec Spec, taps []string, consolePort int) ([]string, error) {
	if len(taps) != spec.Ethernet {
		return nil, errors.New("tap count does not match ethernet count")
	}
	if len(spec.Slots) != spec.Ethernet {
		return nil, errors.New("dynamips slots must match ethernet count")
	}

	args := []string{
		"-P", spec.Platform,
		"-r", strconv.Itoa(spec.RAM),
		"-n", strconv.Itoa(spec.NVRAM),
		"-p", strconv.Itoa(consolePort),
	}
	for index, slot := range spec.Slots {
		args = append(args, "-s", slot+":"+taps[index])
	}
	args = append(args, spec.Image)

	return args, nil
}

func (m *Manager) startDynamips(spec Spec, runDir string) (NodeInfo, error) {
	if _, err := os.Stat(m.cfg.DynamipsBinary); err != nil {
		return NodeInfo{}, fmt.Errorf(
			"%w: dynamips is not installed; install the separate teralab-dynamips package",
			ErrEngineUnavailable,
		)
	}

	taps := make([]string, 0, spec.Ethernet)
	for index := 0; index < spec.Ethernet; index++ {
		tap := spec.TapName(index)
		if err := m.net.CreateTap(tap, spec.Networks[index]); err != nil {
			m.cleanupTaps(taps)
			return NodeInfo{}, err
		}
		taps = append(taps, tap)
	}

	consolePort, err := freeTCPPort()
	if err != nil {
		m.cleanupTaps(taps)
		return NodeInfo{}, err
	}
	args, err := BuildDynamipsArgs(spec, taps, consolePort)
	if err != nil {
		m.cleanupTaps(taps)
		return NodeInfo{}, err
	}

	logFile, err := os.OpenFile(filepath.Join(runDir, "engine.log"), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o640)
	if err != nil {
		m.cleanupTaps(taps)
		return NodeInfo{}, fmt.Errorf("open dynamips log: %w", err)
	}

	command := exec.Command(m.cfg.DynamipsBinary, args...)
	command.Dir = runDir
	command.Stdout = logFile
	command.Stderr = logFile
	command.Env = []string{"PATH=/usr/local/bin:/usr/bin:/bin", "HOME=" + runDir}
	m.limitProcess(command)

	if err := command.Start(); err != nil {
		_ = logFile.Close()
		m.cleanupTaps(taps)
		return NodeInfo{}, fmt.Errorf("start dynamips: %w", err)
	}
	_ = logFile.Close()

	if err := waitForTCP(consolePort, 10*time.Second); err != nil {
		_ = command.Process.Kill()
		_ = command.Wait()
		m.cleanupTaps(taps)
		return NodeInfo{}, fmt.Errorf("dynamips console did not start: %w", err)
	}

	cgroupPath, err := m.applyCgroup(command.Process.Pid, spec)
	if err != nil {
		_ = command.Process.Kill()
		_ = command.Wait()
		m.cleanupTaps(taps)
		return NodeInfo{}, err
	}

	node := &Node{
		Spec:        spec,
		Cmd:         command,
		RunDir:      runDir,
		Taps:        taps,
		CgroupPath:  cgroupPath,
		ConsolePort: consolePort,
		State:       "running",
		Started:     time.Now(),
		Done:        make(chan struct{}),
	}

	return m.register(node)
}

func freeTCPPort() (int, error) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return 0, err
	}
	port := listener.Addr().(*net.TCPAddr).Port
	_ = listener.Close()

	return port, nil
}

func waitForTCP(port int, timeout time.Duration) error {
	address := "127.0.0.1:" + strconv.Itoa(port)
	deadline := time.Now().Add(timeout)
	for {
		connection, err := net.DialTimeout("tcp", address, 250*time.Millisecond)
		if err == nil {
			_ = connection.Close()
			return nil
		}
		if time.Now().After(deadline) {
			return err
		}
		time.Sleep(100 * time.Millisecond)
	}
}
