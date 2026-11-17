// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/config"
	"github.com/creack/pty"
)

type NodeInfo struct {
	ID      string    `json:"id"`
	LabID   string    `json:"lab_id,omitempty"`
	Name    string    `json:"name"`
	State   string    `json:"state"`
	PID     int       `json:"pid,omitempty"`
	Started time.Time `json:"started,omitempty"`
	RunDir  string    `json:"run_dir"`
	Taps    []string  `json:"taps,omitempty"`
	Console string    `json:"console"`
	Cgroup  string    `json:"cgroup,omitempty"`
}

type Node struct {
	Spec       Spec
	Cmd        *exec.Cmd
	PTY        *os.File
	RunDir     string
	Overlay    string
	Taps       []string
	CgroupPath string
	State      string
	Started    time.Time
	Done       chan struct{}
}

type Manager struct {
	cfg    config.Config
	logger *slog.Logger
	mu     sync.Mutex
	nodes  map[string]*Node
}

func NewManager(cfg config.Config, logger *slog.Logger) *Manager {
	return &Manager{
		cfg:    cfg,
		logger: logger,
		nodes:  map[string]*Node{},
	}
}

func (m *Manager) Start(spec Spec) (NodeInfo, error) {
	spec.Normalize()
	if err := spec.Validate(m.cfg.ImagesDir); err != nil {
		return NodeInfo{}, err
	}
	if m.cfg.VMUID == 0 && !m.cfg.AllowRootVM {
		return NodeInfo{}, errors.New("teralab-vm user is missing; refusing to run QEMU as root")
	}

	m.mu.Lock()
	if existing, ok := m.nodes[spec.ID]; ok && existing.State == "running" {
		m.mu.Unlock()
		return NodeInfo{}, errors.New("node is already running")
	}
	m.mu.Unlock()

	runDir, err := m.runDir(spec)
	if err != nil {
		return NodeInfo{}, err
	}
	if err := os.MkdirAll(runDir, 0o750); err != nil {
		return NodeInfo{}, fmt.Errorf("create run directory: %w", err)
	}
	m.chown(runDir)

	overlay := ""
	if spec.Image != "" {
		overlay = filepath.Join(runDir, "overlay.qcow2")
		if err := m.createOverlay(spec.Image, overlay); err != nil {
			return NodeInfo{}, err
		}
	}

	taps := make([]string, 0, spec.Ethernet)
	for index := 0; index < spec.Ethernet; index++ {
		tap := spec.TapName(index)
		bridge := spec.Networks[index]
		if err := m.createTap(tap, bridge); err != nil {
			m.cleanupTaps(taps)
			return NodeInfo{}, err
		}
		taps = append(taps, tap)
	}

	ptmx, tty, err := pty.Open()
	if err != nil {
		m.cleanupTaps(taps)
		return NodeInfo{}, fmt.Errorf("open pty: %w", err)
	}
	logFile, err := os.OpenFile(filepath.Join(runDir, "qemu.log"), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o640)
	if err != nil {
		_ = ptmx.Close()
		_ = tty.Close()
		m.cleanupTaps(taps)
		return NodeInfo{}, fmt.Errorf("open qemu log: %w", err)
	}

	args, err := BuildQEMUArgs(spec, taps, overlay)
	if err != nil {
		_ = logFile.Close()
		_ = ptmx.Close()
		_ = tty.Close()
		m.cleanupTaps(taps)
		return NodeInfo{}, err
	}

	command := exec.Command(m.cfg.QEMUBinary, args...)
	command.Stdin = tty
	command.Stdout = tty
	command.Stderr = logFile
	command.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	if m.cfg.VMUID > 0 {
		command.SysProcAttr.Credential = &syscall.Credential{
			Uid:    m.cfg.VMUID,
			Gid:    m.cfg.VMGID,
			Groups: m.cfg.VMGroups,
		}
	}

	if err := command.Start(); err != nil {
		_ = logFile.Close()
		_ = ptmx.Close()
		_ = tty.Close()
		m.cleanupTaps(taps)
		return NodeInfo{}, fmt.Errorf("start qemu: %w", err)
	}
	_ = tty.Close()
	_ = logFile.Close()

	cgroupPath, err := m.applyCgroup(command.Process.Pid, spec)
	if err != nil {
		_ = command.Process.Kill()
		_ = command.Wait()
		_ = ptmx.Close()
		m.cleanupTaps(taps)
		return NodeInfo{}, err
	}

	node := &Node{
		Spec:       spec,
		Cmd:        command,
		PTY:        ptmx,
		RunDir:     runDir,
		Overlay:    overlay,
		Taps:       taps,
		CgroupPath: cgroupPath,
		State:      "running",
		Started:    time.Now(),
		Done:       make(chan struct{}),
	}

	m.mu.Lock()
	m.nodes[spec.ID] = node
	m.mu.Unlock()

	go m.wait(node)
	m.logger.Info("node started", "id", spec.ID, "pid", command.Process.Pid, "run_dir", runDir)

	return m.info(node), nil
}

func (m *Manager) Stop(id string) error {
	m.mu.Lock()
	node, ok := m.nodes[id]
	if !ok {
		m.mu.Unlock()
		return errors.New("node not found")
	}
	if node.State != "running" {
		m.mu.Unlock()
		return nil
	}
	pid := node.Cmd.Process.Pid
	m.mu.Unlock()

	_ = syscall.Kill(-pid, syscall.SIGTERM)
	select {
	case <-node.Done:
	case <-time.After(5 * time.Second):
		_ = syscall.Kill(-pid, syscall.SIGKILL)
		<-node.Done
	}

	m.mu.Lock()
	m.cleanupTaps(node.Taps)
	node.Taps = nil
	node.State = "stopped"
	m.mu.Unlock()
	m.logger.Info("node stopped", "id", id)

	return nil
}

func (m *Manager) Wipe(id string) error {
	if err := m.Stop(id); err != nil && !strings.Contains(err.Error(), "not found") {
		return err
	}

	m.mu.Lock()
	node, ok := m.nodes[id]
	if !ok {
		m.mu.Unlock()
		return nil
	}
	delete(m.nodes, id)
	m.mu.Unlock()

	if err := os.RemoveAll(node.RunDir); err != nil {
		return fmt.Errorf("remove run directory: %w", err)
	}
	m.logger.Info("node wiped", "id", id)

	return nil
}

func (m *Manager) Status(id string) (NodeInfo, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	node, ok := m.nodes[id]
	if !ok {
		return NodeInfo{}, errors.New("node not found")
	}

	return m.info(node), nil
}

func (m *Manager) List() []NodeInfo {
	m.mu.Lock()
	defer m.mu.Unlock()
	result := make([]NodeInfo, 0, len(m.nodes))
	for _, node := range m.nodes {
		result = append(result, m.info(node))
	}

	return result
}

func (m *Manager) Count() int {
	m.mu.Lock()
	defer m.mu.Unlock()
	count := 0
	for _, node := range m.nodes {
		if node.State == "running" {
			count++
		}
	}

	return count
}

func (m *Manager) PTY(id string) (*os.File, bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	node, ok := m.nodes[id]
	if !ok || node.State != "running" || node.PTY == nil {
		return nil, false
	}

	return node.PTY, true
}

func (m *Manager) Shutdown() {
	for _, info := range m.List() {
		if info.State == "running" {
			_ = m.Stop(info.ID)
		}
	}
}

func (m *Manager) QEMUVersion(ctx context.Context) string {
	command := exec.CommandContext(ctx, m.cfg.QEMUBinary, "--version")
	output, err := command.Output()
	if err != nil {
		return ""
	}
	lines := strings.Split(strings.TrimSpace(string(output)), "\n")
	if len(lines) == 0 {
		return ""
	}

	return lines[0]
}

func (m *Manager) wait(node *Node) {
	_ = node.Cmd.Wait()

	m.mu.Lock()
	if current, ok := m.nodes[node.Spec.ID]; ok && current == node {
		node.State = "stopped"
		if node.PTY != nil {
			_ = node.PTY.Close()
			node.PTY = nil
		}
		m.cleanupTaps(node.Taps)
		node.Taps = nil
		m.removeCgroup(node.CgroupPath)
		node.CgroupPath = ""
		close(node.Done)
	}
	m.mu.Unlock()
}

func (m *Manager) info(node *Node) NodeInfo {
	info := NodeInfo{
		ID:      node.Spec.ID,
		LabID:   node.Spec.LabID,
		Name:    node.Spec.Name,
		State:   node.State,
		RunDir:  node.RunDir,
		Taps:    append([]string(nil), node.Taps...),
		Console: node.Spec.Console,
		Started: node.Started,
		Cgroup:  node.CgroupPath,
	}
	if node.Cmd != nil && node.Cmd.Process != nil {
		info.PID = node.Cmd.Process.Pid
	}

	return info
}

func (m *Manager) runDir(spec Spec) (string, error) {
	base, err := filepath.Abs(m.cfg.RunDir)
	if err != nil {
		return "", err
	}
	parts := []string{base}
	if spec.LabID != "" {
		parts = append(parts, spec.LabID)
	}
	parts = append(parts, spec.ID)
	target := filepath.Join(parts...)
	if target != base && !strings.HasPrefix(target, base+string(os.PathSeparator)) {
		return "", errors.New("run directory escapes the run root")
	}

	return target, nil
}

func (m *Manager) createOverlay(image, overlay string) error {
	command := exec.Command(m.cfg.QEMUImg, "create", "-f", "qcow2", "-F", "qcow2", "-b", image, overlay)
	output, err := command.CombinedOutput()
	if err != nil {
		return fmt.Errorf("create overlay: %s: %w", strings.TrimSpace(string(output)), err)
	}
	m.chown(overlay)

	return nil
}

func (m *Manager) createTap(tap, bridge string) error {
	_ = exec.Command("ip", "link", "delete", tap).Run()
	args := []string{"tuntap", "add", "dev", tap, "mode", "tap"}
	if m.cfg.VMUID > 0 {
		args = append(args, "user", m.cfg.VMUser)
	}
	if output, err := exec.Command("ip", args...).CombinedOutput(); err != nil {
		return fmt.Errorf("create tap %s: %s: %w", tap, strings.TrimSpace(string(output)), err)
	}
	if bridge != "" {
		if output, err := exec.Command("ip", "link", "set", "dev", tap, "master", bridge).CombinedOutput(); err != nil {
			_ = exec.Command("ip", "link", "delete", tap).Run()
			return fmt.Errorf("attach tap %s to %s: %s: %w", tap, bridge, strings.TrimSpace(string(output)), err)
		}
	}
	if output, err := exec.Command("ip", "link", "set", "dev", tap, "up").CombinedOutput(); err != nil {
		_ = exec.Command("ip", "link", "delete", tap).Run()
		return fmt.Errorf("bring tap %s up: %s: %w", tap, strings.TrimSpace(string(output)), err)
	}

	return nil
}

func (m *Manager) cleanupTaps(taps []string) {
	for _, tap := range taps {
		_ = exec.Command("ip", "link", "delete", tap).Run()
	}
}

func (m *Manager) applyCgroup(pid int, spec Spec) (string, error) {
	if m.cfg.DisableCgroups {
		return "", nil
	}
	base := m.cfg.CgroupRoot
	parent := filepath.Dir(base)
	_ = os.WriteFile(filepath.Join(parent, "cgroup.subtree_control"), []byte("+cpu +memory"), 0o644)
	if err := os.MkdirAll(base, 0o755); err != nil {
		return "", fmt.Errorf("create cgroup slice: %w", err)
	}
	_ = os.WriteFile(filepath.Join(base, "cgroup.subtree_control"), []byte("+cpu +memory"), 0o644)

	child := filepath.Join(base, "node-"+spec.ID)
	_ = os.Remove(child)
	if err := os.Mkdir(child, 0o755); err != nil {
		return "", fmt.Errorf("create node cgroup: %w", err)
	}
	memoryLimit := (spec.RAM + 256) * 1024 * 1024
	if err := os.WriteFile(filepath.Join(child, "memory.max"), []byte(strconv.Itoa(memoryLimit)), 0o644); err != nil {
		_ = os.Remove(child)
		return "", fmt.Errorf("set memory limit: %w", err)
	}
	if err := os.WriteFile(filepath.Join(child, "cpu.max"), []byte(strconv.Itoa(spec.CPU*100000)+" 100000"), 0o644); err != nil {
		_ = os.Remove(child)
		return "", fmt.Errorf("set cpu limit: %w", err)
	}
	if err := os.WriteFile(filepath.Join(child, "cgroup.procs"), []byte(strconv.Itoa(pid)), 0o644); err != nil {
		_ = os.Remove(child)
		return "", fmt.Errorf("move qemu into cgroup: %w", err)
	}

	return child, nil
}

func (m *Manager) removeCgroup(path string) {
	if path == "" {
		return
	}
	for attempt := 0; attempt < 3; attempt++ {
		if err := os.Remove(path); err == nil {
			return
		}
		time.Sleep(50 * time.Millisecond)
	}
}

func (m *Manager) chown(path string) {
	if m.cfg.VMUID == 0 {
		return
	}
	_ = os.Chown(path, int(m.cfg.VMUID), int(m.cfg.VMGID))
}
