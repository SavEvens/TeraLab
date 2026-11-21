// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/config"
	"github.com/SavEvens/TeraLab/agent/internal/network"
	"github.com/creack/pty"
)

// ErrEngineUnavailable marks a start failure caused by a missing engine binary
// or image rather than a bad request.
var ErrEngineUnavailable = errors.New("engine unavailable")

const stateFileName = "node.json"

type NodeInfo struct {
	ID            string    `json:"id"`
	LabID         string    `json:"lab_id,omitempty"`
	Name          string    `json:"name"`
	Engine        string    `json:"engine"`
	Image         string    `json:"image,omitempty"`
	State         string    `json:"state"`
	PID           int       `json:"pid,omitempty"`
	Started       time.Time `json:"started,omitempty"`
	RunDir        string    `json:"run_dir"`
	Taps          []string  `json:"taps,omitempty"`
	Console       string    `json:"console"`
	ConsolePort   int       `json:"console_port,omitempty"`
	Cgroup        string    `json:"cgroup,omitempty"`
	VNCPort       int       `json:"vnc_port,omitempty"`
	MemoryBytes   int64     `json:"memory_bytes,omitempty"`
	CPUUsageUsec  int64     `json:"cpu_usage_usec,omitempty"`
	UptimeSeconds int64     `json:"uptime_seconds,omitempty"`
	// Ready reports that the engine console can be driven: a prompt was
	// seen for IOL and VPCS, or a QEMU guest produced console output.
	Ready bool `json:"ready"`
}

type Node struct {
	Spec        Spec
	Cmd         *exec.Cmd
	PTY         *os.File
	Relays      []io.Closer
	RunDir      string
	Overlay     string
	Taps        []string
	CgroupPath  string
	VNCPort     int
	VNCPassword string
	MonitorPath string
	ConsolePort int
	StatePath   string
	State       string
	Started     time.Time
	Done        chan struct{}
	Ready       atomic.Bool

	historyMu   sync.Mutex
	history     []byte
	subscribers map[chan []byte]struct{}
	consoleDone chan struct{}

	// writeMu serialises console writes so an agent command replay cannot
	// interleave with a user typing on the same pty.
	writeMu sync.Mutex
}

type Manager struct {
	cfg    config.Config
	net    *network.Manager
	logger *slog.Logger
	mu     sync.Mutex
	nodes  map[string]*Node
}

func NewManager(cfg config.Config, logger *slog.Logger) *Manager {
	links := network.New(cfg.VMUser, cfg.NetworkBackend)
	links.BridgeGroupFwdMask = cfg.BridgeGroupFwdMask
	if logger != nil {
		links.Logf = func(format string, args ...any) {
			logger.Warn(fmt.Sprintf(format, args...))
		}
	}

	return &Manager{
		cfg:    cfg,
		net:    links,
		logger: logger,
		nodes:  map[string]*Node{},
	}
}

// Start validates a node spec and launches the matching engine.
func (m *Manager) Start(spec Spec) (NodeInfo, error) {
	// Parse the raw legacy qemu_options before validation so the allow-list
	// rejects unsafe arguments before any tap or run directory is created.
	if spec.ExtraOptions != "" {
		merged, err := spec.withExtraOptions()
		if err != nil {
			return NodeInfo{}, err
		}
		spec = merged
	}
	spec.Normalize()
	if err := spec.Validate(m.cfg.ImageRoots()); err != nil {
		return NodeInfo{}, err
	}
	if m.cfg.VMUID == 0 && !m.cfg.AllowRootVM {
		return NodeInfo{}, errors.New("teralab-vm user is missing; refusing to run engines as root")
	}

	m.mu.Lock()
	if existing, ok := m.nodes[spec.ID]; ok && existing.State == "running" {
		m.mu.Unlock()
		return NodeInfo{}, errors.New("node is already running")
	}
	m.mu.Unlock()

	runDir, err := m.prepareRunDir(spec)
	if err != nil {
		return NodeInfo{}, err
	}

	var info NodeInfo
	switch spec.Engine {
	case "qemu", "":
		info, err = m.startQEMU(spec, runDir)
	case "vpcs":
		info, err = m.startVPCS(spec, runDir)
	case "dynamips":
		info, err = m.startDynamips(spec, runDir)
	case "iol":
		info, err = m.startIOL(spec, runDir)
	default:
		err = fmt.Errorf("unsupported engine %q", spec.Engine)
	}
	if err != nil {
		_ = os.RemoveAll(runDir)
		return NodeInfo{}, err
	}

	return info, nil
}

func (m *Manager) prepareRunDir(spec Spec) (string, error) {
	runDir, err := m.runDir(spec)
	if err != nil {
		return "", err
	}
	if err := os.MkdirAll(runDir, 0o750); err != nil {
		return "", fmt.Errorf("create run directory: %w", err)
	}
	if labDir := filepath.Dir(runDir); labDir != m.cfg.RunDir {
		m.chown(labDir)
	}
	m.chown(runDir)

	return runDir, nil
}

func (m *Manager) startQEMU(spec Spec, runDir string) (NodeInfo, error) {
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
		if err := m.net.CreateTap(tap, bridge); err != nil {
			m.cleanupTaps(taps)
			return NodeInfo{}, err
		}
		taps = append(taps, tap)
	}

	var ptmx *os.File
	var tty *os.File
	var vnc *VNCConfig
	vncPort := 0
	vncPassword := ""
	if spec.Console == "vnc" {
		display, err := freeVNCDisplay()
		if err != nil {
			m.cleanupTaps(taps)
			return NodeInfo{}, err
		}
		vncPassword = randomHex(8)
		if vncPassword == "" {
			m.cleanupTaps(taps)
			return NodeInfo{}, errors.New("cannot generate a vnc password")
		}
		vncPort = 5900 + display
		vnc = &VNCConfig{
			Display:     display,
			MonitorPath: filepath.Join(runDir, "monitor.sock"),
		}
	} else {
		var err error
		ptmx, tty, err = pty.Open()
		if err != nil {
			m.cleanupTaps(taps)
			return NodeInfo{}, fmt.Errorf("open pty: %w", err)
		}
	}
	logFile, err := os.OpenFile(filepath.Join(runDir, "qemu.log"), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o640)
	if err != nil {
		_ = ptmx.Close()
		_ = tty.Close()
		m.cleanupTaps(taps)
		return NodeInfo{}, fmt.Errorf("open qemu log: %w", err)
	}

	kvm := m.kvmAvailable()
	if !kvm {
		if spec.Accel == "kvm" || spec.Accel == "kvm:tcg" {
			m.logger.Warn("kvm is unavailable; starting with tcg", "id", spec.ID)
		}
		if spec.CPUModel == "host" {
			m.logger.Warn("kvm is unavailable; using the max cpu model", "id", spec.ID)
		}
	}
	args, err := BuildQEMUArgs(spec, taps, overlay, vnc, kvm)
	if err != nil {
		_ = logFile.Close()
		_ = ptmx.Close()
		_ = tty.Close()
		m.cleanupTaps(taps)
		return NodeInfo{}, err
	}
	// One audit line per start: operators can see every parameter that reached
	// QEMU, including template extras.
	m.logger.Info("qemu command line", "id", spec.ID, "command", commandLine(m.cfg.QEMUBinary, args))

	command := exec.Command(m.cfg.QEMUBinary, args...)
	if tty != nil {
		command.Stdin = tty
		command.Stdout = tty
	} else {
		command.Stdout = logFile
	}
	command.Stderr = logFile
	m.limitProcess(command)
	m.chown(runDir)

	if err := command.Start(); err != nil {
		_ = logFile.Close()
		_ = ptmx.Close()
		_ = tty.Close()
		m.cleanupTaps(taps)
		return NodeInfo{}, fmt.Errorf("start qemu: %w", err)
	}
	_ = tty.Close()
	_ = logFile.Close()

	if vnc != nil {
		if err := m.setVNCPassword(vnc.MonitorPath, vncPassword); err != nil {
			_ = command.Process.Kill()
			_ = command.Wait()
			_ = ptmx.Close()
			m.cleanupTaps(taps)
			return NodeInfo{}, err
		}
	}

	cgroupPath, err := m.applyCgroup(command.Process.Pid, spec)
	if err != nil {
		_ = command.Process.Kill()
		_ = command.Wait()
		_ = ptmx.Close()
		m.cleanupTaps(taps)
		return NodeInfo{}, err
	}

	node := &Node{
		Spec:        spec,
		Cmd:         command,
		PTY:         ptmx,
		RunDir:      runDir,
		Overlay:     overlay,
		Taps:        taps,
		CgroupPath:  cgroupPath,
		VNCPort:     vncPort,
		VNCPassword: vncPassword,
		State:       "running",
		Started:     time.Now(),
		Done:        make(chan struct{}),
		consoleDone: make(chan struct{}),
		subscribers: map[chan []byte]struct{}{},
	}
	if node.PTY != nil {
		go m.pumpPTY(node)
	}

	return m.register(node)
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

	if err := syscall.Kill(-pid, syscall.SIGTERM); err != nil && !errors.Is(err, syscall.ESRCH) {
		return fmt.Errorf("stop node %s: signal: %w", id, err)
	}
	select {
	case <-node.Done:
	case <-time.After(5 * time.Second):
		if err := syscall.Kill(-pid, syscall.SIGKILL); err != nil && !errors.Is(err, syscall.ESRCH) {
			return fmt.Errorf("stop node %s: kill: %w", id, err)
		}
		select {
		case <-node.Done:
		case <-time.After(5 * time.Second):
			return fmt.Errorf("stop node %s: timed out waiting for exit", id)
		}
	}

	m.mu.Lock()
	m.cleanupTaps(node.Taps)
	node.Taps = nil
	node.State = "stopped"
	node.Ready.Store(false)
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

	if node.StatePath != "" {
		_ = os.Remove(node.StatePath)
	}
	if err := os.RemoveAll(node.RunDir); err != nil {
		return fmt.Errorf("remove run directory: %w", err)
	}
	// Remove an empty per-lab directory without touching unrelated nodes.
	_ = os.Remove(filepath.Dir(node.RunDir))
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

// VNC returns the loopback VNC port and password for a running node.
func (m *Manager) VNC(id string) (int, string, bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	node, ok := m.nodes[id]
	if !ok || node.State != "running" || node.VNCPort == 0 || node.VNCPassword == "" {
		return 0, "", false
	}

	return node.VNCPort, node.VNCPassword, true
}

// TCPConsole returns the loopback TCP console port for engines that do not
// expose a pty (currently Dynamips).
func (m *Manager) TCPConsole(id string) (int, bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	node, ok := m.nodes[id]
	if !ok || node.State != "running" || node.ConsolePort == 0 {
		return 0, false
	}

	return node.ConsolePort, true
}

// EnsureLab creates the bridges for a lab's networks.
func (m *Manager) EnsureLab(labID string, networkIDs []string) (map[string]string, error) {
	return m.net.EnsureLab(labID, networkIDs)
}

// liveLinks returns every tap and bridge that running nodes currently use,
// restricted to one lab when labID is not empty.
func (m *Manager) liveLinks(labID string) map[string]bool {
	keep := map[string]bool{}
	m.mu.Lock()
	defer m.mu.Unlock()
	for _, node := range m.nodes {
		if node.State != "running" {
			continue
		}
		if labID != "" && node.Spec.LabID != labID {
			continue
		}
		for _, tap := range node.Taps {
			if tap != "" {
				keep[tap] = true
			}
		}
		for _, bridge := range node.Spec.Networks {
			if bridge != "" {
				keep[bridge] = true
			}
		}
	}

	return keep
}

// CleanupLab removes the bridges and taps owned by one lab, sparing links
// that running nodes still use, and reports what was removed.
func (m *Manager) CleanupLab(labID string) ([]string, error) {
	removed, err := m.net.CleanupLab(labID, m.liveLinks(labID))
	if len(removed) > 0 {
		m.logger.Warn("removed lab links", "lab", labID, "links", removed)
	}

	return removed, err
}

func (m *Manager) Shutdown() {
	for _, info := range m.List() {
		if info.State == "running" {
			_ = m.Stop(info.ID)
		}
	}
}

func (m *Manager) QEMUVersion(ctx context.Context) string {
	return binaryVersion(ctx, m.cfg.QEMUBinary)
}

func (m *Manager) VPCSVersion(ctx context.Context) string {
	return binaryVersion(ctx, m.cfg.VPCSBinary)
}

func (m *Manager) DynamipsVersion(ctx context.Context) string {
	return binaryVersion(ctx, m.cfg.DynamipsBinary)
}

func binaryVersion(ctx context.Context, binary string) string {
	command := exec.CommandContext(ctx, binary, "--version")
	output, err := command.Output()
	if err != nil {
		command = exec.CommandContext(ctx, binary, "-v")
		output, err = command.CombinedOutput()
		if err != nil {
			return ""
		}
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
		node.Ready.Store(false)
		if node.PTY != nil {
			_ = node.PTY.Close()
			node.PTY = nil
		}
		for _, relay := range node.Relays {
			_ = relay.Close()
		}
		node.Relays = nil
		m.cleanupTaps(node.Taps)
		node.Taps = nil
		m.removeCgroup(node.CgroupPath)
		node.CgroupPath = ""
		if node.StatePath != "" {
			_ = os.Remove(node.StatePath)
		}
		close(node.Done)
	}
	m.mu.Unlock()
}

func (m *Manager) info(node *Node) NodeInfo {
	info := NodeInfo{
		ID:          node.Spec.ID,
		LabID:       node.Spec.LabID,
		Name:        node.Spec.Name,
		Engine:      node.Spec.Engine,
		Image:       node.Spec.Image,
		State:       node.State,
		RunDir:      node.RunDir,
		Taps:        append([]string(nil), node.Taps...),
		Console:     node.Spec.Console,
		ConsolePort: node.ConsolePort,
		Started:     node.Started,
		Cgroup:      node.CgroupPath,
		VNCPort:     node.VNCPort,
		Ready:       node.State == "running" && node.Ready.Load(),
	}
	if node.Cmd != nil && node.Cmd.Process != nil {
		info.PID = node.Cmd.Process.Pid
	}
	if node.State == "running" {
		info.UptimeSeconds = int64(time.Since(node.Started).Seconds())
		memory, cpu := readCgroupUsage(node.CgroupPath)
		info.MemoryBytes = memory
		info.CPUUsageUsec = cpu
	}

	return info
}

// readCgroupUsage reads best-effort memory and CPU counters for a node.
func readCgroupUsage(path string) (int64, int64) {
	if path == "" {
		return 0, 0
	}
	var memory int64
	if data, err := os.ReadFile(filepath.Join(path, "memory.current")); err == nil {
		memory, _ = strconv.ParseInt(strings.TrimSpace(string(data)), 10, 64)
	}
	var cpu int64
	if data, err := os.ReadFile(filepath.Join(path, "cpu.stat")); err == nil {
		for _, line := range strings.Split(string(data), "\n") {
			fields := strings.Fields(line)
			if len(fields) == 2 && fields[0] == "usage_usec" {
				cpu, _ = strconv.ParseInt(fields[1], 10, 64)
				break
			}
		}
	}

	return memory, cpu
}

func (m *Manager) register(node *Node) (NodeInfo, error) {
	state := nodeState{
		ID:      node.Spec.ID,
		LabID:   node.Spec.LabID,
		Engine:  node.Spec.Engine,
		Exe:     node.Cmd.Path,
		PID:     node.Cmd.Process.Pid,
		Taps:    append([]string(nil), node.Taps...),
		Cgroup:  node.CgroupPath,
		Started: node.Started.UTC().Format(time.RFC3339),
	}
	statePath := filepath.Join(node.RunDir, stateFileName)
	if data, err := json.MarshalIndent(state, "", "  "); err == nil {
		if err := os.WriteFile(statePath, data, 0o640); err == nil {
			node.StatePath = statePath
		}
	}

	m.mu.Lock()
	m.nodes[node.Spec.ID] = node
	m.mu.Unlock()

	go m.wait(node)
	m.logger.Info("node started",
		"id", node.Spec.ID,
		"engine", node.Spec.Engine,
		"pid", node.Cmd.Process.Pid,
		"run_dir", node.RunDir,
	)

	return m.info(node), nil
}

func (m *Manager) runDir(spec Spec) (string, error) {
	base, err := filepath.Abs(m.cfg.RunDir)
	if err != nil {
		return "", err
	}
	parts := []string{base}
	if spec.LabID != "" {
		parts = append(parts, shortPathComponent(spec.LabID))
	}
	parts = append(parts, shortPathComponent(spec.ID))
	target := filepath.Join(parts...)
	if target != base && !strings.HasPrefix(target, base+string(os.PathSeparator)) {
		return "", errors.New("run directory escapes the run root")
	}

	return target, nil
}

// shortPathComponent keeps nested runtime paths below Unix socket limits.
func shortPathComponent(value string) string {
	if len(value) <= 32 {
		return value
	}
	sum := sha256.Sum256([]byte(value))

	return hex.EncodeToString(sum[:8])
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

func (m *Manager) cleanupTaps(taps []string) {
	for _, tap := range taps {
		_ = m.net.DeleteLink(tap)
	}
}

func (m *Manager) limitProcess(command *exec.Cmd) {
	command.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	if m.cfg.VMUID > 0 {
		command.SysProcAttr.Credential = &syscall.Credential{
			Uid:    m.cfg.VMUID,
			Gid:    m.cfg.VMGID,
			Groups: m.cfg.VMGroups,
		}
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
		return "", fmt.Errorf("move pid into cgroup: %w", err)
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

// freeVNCDisplay reserves a loopback VNC display in the range QEMU supports.
func freeVNCDisplay() (int, error) {
	for display := 1; display <= 99; display++ {
		listener, err := net.Listen("tcp", "127.0.0.1:"+strconv.Itoa(5900+display))
		if err != nil {
			continue
		}
		_ = listener.Close()
		return display, nil
	}

	return 0, errors.New("no free VNC display is available")
}

func randomHex(bytes int) string {
	raw := make([]byte, bytes)
	if _, err := rand.Read(raw); err != nil {
		return ""
	}

	return hex.EncodeToString(raw)
}

func (m *Manager) setVNCPassword(monitorPath, password string) error {
	deadline := time.Now().Add(15 * time.Second)
	var connection net.Conn
	for {
		var err error
		connection, err = net.DialTimeout("unix", monitorPath, 200*time.Millisecond)
		if err == nil {
			break
		}
		if time.Now().After(deadline) {
			return fmt.Errorf("connect to qemu monitor: %w", err)
		}
		time.Sleep(50 * time.Millisecond)
	}
	defer connection.Close()
	_ = connection.SetDeadline(time.Now().Add(2 * time.Second))

	banner := make([]byte, 1024)
	_, _ = connection.Read(banner)
	if _, err := fmt.Fprintf(connection, "set_password vnc %s\n", password); err != nil {
		return fmt.Errorf("set vnc password: %w", err)
	}
	response := make([]byte, 4096)
	_, _ = connection.Read(response)

	return nil
}

func (m *Manager) kvmAvailable() bool {
	if m.cfg.DisableKVM {
		return false
	}
	_, err := os.Stat("/dev/kvm")

	return err == nil
}

func (m *Manager) chown(path string) {
	if m.cfg.VMUID == 0 {
		return
	}
	_ = os.Chown(path, int(m.cfg.VMUID), int(m.cfg.VMGID))
}
