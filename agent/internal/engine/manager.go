// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
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

// qemuSandboxProfile is QEMU's seccomp sandbox. It denies obsolete syscalls,
// privilege elevation, process spawning (the engine uses script=no for taps
// and stdio for the serial console) and resource-control syscalls outside the
// agent's cgroup management.
const qemuSandboxProfile = "on,obsolete=deny,elevateprivileges=deny,spawn=deny,resourcecontrol=deny"

// engineEnv returns the minimal environment an engine process needs. The agent
// environment is not inherited: the agent holds the service secret and may run
// with proxy/credential variables that no guest engine should see.
func engineEnv(home string) []string {
	return []string{
		"PATH=/usr/local/bin:/usr/bin:/bin",
		"HOME=" + home,
		"TERM=vt100",
		"LC_ALL=C",
	}
}

// qemuSandboxArgs renders the sandbox flags. It is a no-op only when the
// operator explicitly disabled the sandbox; the compensating controls are the
// teralab-vm credential, the minimal environment and the engine allowlist.
func qemuSandboxArgs(enabled bool) []string {
	if !enabled {
		return nil
	}

	return []string{"-sandbox", qemuSandboxProfile}
}

type NodeInfo struct {
	ID            string    `json:"id"`
	LabID         string    `json:"lab_id,omitempty"`
	Name          string    `json:"name"`
	Engine        string    `json:"engine"`
	Image         string    `json:"image,omitempty"`
	State         string    `json:"state"`
	PID           int       `json:"pid,omitempty"`
	Started       time.Time `json:"started,omitempty"`
	LastActivity  time.Time `json:"last_activity,omitempty"`
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
	Spec Spec
	Cmd  *exec.Cmd
	// ExecCmd is the optional docker exec console client; it is never the
	// container itself and stopping it must not stop the container.
	ExecCmd      *exec.Cmd
	ContainerID  string
	ContainerPID int
	PTY          *os.File
	Relays       []io.Closer
	RunDir       string
	Overlay      string
	Taps         []string
	CgroupPath   string
	VNCPort      int
	VNCPassword  string
	MonitorPath  string
	ConsolePort  int
	StatePath    string
	State        string
	Started      time.Time
	LastActivity time.Time
	// LabUID/LabGID are the numeric credential recorded in trusted state. They
	// stay zero in rollback mode.
	LabUID uint32
	LabGID uint32
	Done   chan struct{}
	Ready  atomic.Bool

	historyMu   sync.Mutex
	history     []byte
	subscribers map[chan []byte]struct{}
	consoleDone chan struct{}

	// writeMu serialises console writes so an agent command replay cannot
	// interleave with a user typing on the same pty.
	writeMu sync.Mutex

	// consoleStream wraps PTY when the engine console must report activity on
	// I/O (docker exec); nil means PTY is read and written directly.
	consoleStream io.ReadWriter
}

type Manager struct {
	cfg    config.Config
	net    *network.Manager
	docker *dockerClient
	logger *slog.Logger
	mu     sync.Mutex
	nodes  map[string]*Node

	// uidRegistry is nil when per-lab uids are disabled. uidFailure records a
	// startup validation failure so every later start fails closed.
	uidRegistry  *uidAllocator
	uidFailureMu sync.Mutex
	uidFailure   error

	// uidProbe reports whether any live process holds a uid. It is nil in
	// production (the agent scans /proc) and replaceable in tests.
	uidProbe func(uint32) bool
}

func NewManager(cfg config.Config, logger *slog.Logger) *Manager {
	links := network.New(cfg.VMUser, cfg.NetworkBackend)
	links.BridgeGroupFwdMask = cfg.BridgeGroupFwdMask
	if logger != nil {
		links.Logf = func(format string, args ...any) {
			logger.Warn(fmt.Sprintf(format, args...))
		}
	}

	manager := &Manager{
		cfg:    cfg,
		net:    links,
		docker: newDockerClient(cfg.DockerBinary),
		logger: logger,
		nodes:  map[string]*Node{},
	}
	if cfg.PerLabUID {
		manager.uidRegistry = newUIDAllocator(stateRootFor(cfg), cfg.LabUIDFirst, cfg.LabUIDSlots)
	}

	return manager
}

// PrepareLabUIDs validates the per-lab uid registry at startup. It never stops
// the agent: an invalid registry makes every later lab start fail closed.
func (m *Manager) PrepareLabUIDs() error {
	return m.prepareLabUIDs()
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
	if m.cfg.VMUID == 0 && !m.cfg.AllowRootVM && !m.cfg.PerLabUID {
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
	case "docker":
		info, err = m.startDocker(spec, runDir)
	default:
		err = fmt.Errorf("unsupported engine %q", spec.Engine)
	}
	if err != nil {
		// A docker stop/start cycle reuses the preserved container. Removing
		// its run directory here would also delete the state file that keeps
		// the container associated with the node; leave a pre-existing state
		// file for the next reconcile instead.
		if spec.Engine != "docker" {
			_ = os.RemoveAll(runDir)
			m.removeStatePath(m.statePathFor(spec.ID, spec.LabID))
		} else if _, statErr := os.Stat(m.statePathFor(spec.ID, spec.LabID)); statErr != nil {
			_ = os.RemoveAll(runDir)
		}

		return NodeInfo{}, err
	}

	return info, nil
}

func (m *Manager) prepareRunDir(spec Spec) (string, error) {
	identity, err := m.labIdentityFor(spec)
	if err != nil {
		return "", err
	}
	runDir, err := m.runDir(spec)
	if err != nil {
		return "", err
	}
	runRoot := filepath.Clean(m.runRoot())
	if err := os.MkdirAll(runRoot, 0o750); err != nil {
		return "", fmt.Errorf("create run root: %w", err)
	}
	// The engine user owns (and can write) the lab and node directories, so it
	// could have replaced either with a symlink. Create each level one at a
	// time and refuse to continue when a component is a link or resolves
	// outside the lab run root.
	labRoot := filepath.Clean(m.labRunRoot(spec))
	if err := m.ensureRealRunDir(runRoot, labRoot); err != nil {
		return "", err
	}
	if err := m.ensureRealRunDir(labRoot, runDir); err != nil {
		return "", err
	}
	if tmp := m.privateTmpDir(spec); tmp != "" {
		if err := m.ensureRealRunDir(labRoot, tmp); err != nil {
			return "", err
		}
	}
	if err := m.ownLabRunTree(spec, identity); err != nil {
		return "", err
	}

	return runDir, nil
}

// ensureRealRunDir creates one run-directory level below root when it is
// absent, and otherwise requires it to be a real directory whose resolved path
// stays inside root. It uses Lstat so a symlink planted by an engine user is
// rejected instead of followed, and EvalSymlinks so an intermediate link that
// escapes root is caught as well.
func (m *Manager) ensureRealRunDir(root, path string) error {
	root = filepath.Clean(root)
	path = filepath.Clean(path)
	if !pathWithinRoot(root, path) {
		return fmt.Errorf("run directory %q is outside %q", path, root)
	}

	info, err := os.Lstat(path)
	if errors.Is(err, os.ErrNotExist) {
		if mkdirErr := os.Mkdir(path, 0o750); mkdirErr != nil && !errors.Is(mkdirErr, os.ErrExist) {
			return fmt.Errorf("create run directory %q: %w", path, mkdirErr)
		}
		info, err = os.Lstat(path)
	}
	if err != nil {
		return fmt.Errorf("stat run directory %q: %w", path, err)
	}
	if info.Mode()&os.ModeSymlink != 0 || !info.IsDir() {
		return fmt.Errorf("run directory %q is not a real directory", path)
	}

	resolvedRoot, err := filepath.EvalSymlinks(root)
	if err != nil {
		return fmt.Errorf("resolve run root %q: %w", root, err)
	}
	resolvedPath, err := filepath.EvalSymlinks(path)
	if err != nil {
		return fmt.Errorf("resolve run directory %q: %w", path, err)
	}
	if resolvedPath != resolvedRoot && !pathWithinRoot(resolvedRoot, resolvedPath) {
		return fmt.Errorf("run directory %q resolves outside %q", path, root)
	}

	return nil
}

func (m *Manager) startQEMU(spec Spec, runDir string) (NodeInfo, error) {
	identity, err := m.labIdentityFor(spec)
	if err != nil {
		return NodeInfo{}, err
	}
	overlay := ""
	if spec.Image != "" {
		overlay = filepath.Join(runDir, "overlay.qcow2")
		if err := m.createOverlay(identity, spec.Image, overlay); err != nil {
			return NodeInfo{}, err
		}
	}
	// Prepare every extra disk before touching the network so a failed overlay
	// or create aborts the start and the caller's run-dir cleanup removes the
	// partial files.
	disks, err := m.prepareExtraDisks(spec, runDir)
	if err != nil {
		return NodeInfo{}, err
	}

	taps := make([]string, 0, spec.Ethernet)
	for index := 0; index < spec.Ethernet; index++ {
		tap := spec.TapName(index)
		bridge := spec.Networks[index]
		if err := m.createLabTap(spec, identity, tap, bridge); err != nil {
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
	args, err := BuildQEMUArgs(spec, taps, overlay, disks, vnc, kvm)
	if err != nil {
		_ = logFile.Close()
		_ = ptmx.Close()
		_ = tty.Close()
		m.cleanupTaps(taps)
		return NodeInfo{}, err
	}
	if !m.cfg.QEMUSandbox {
		m.logger.Warn(
			"qemu seccomp sandbox disabled by configuration; the node runs as the VM user with a minimal environment",
			"id", spec.ID,
		)
	}
	args = append(args, qemuSandboxArgs(m.cfg.QEMUSandbox)...)
	// One audit line per start: operators can see every parameter that reached
	// QEMU, including template extras.
	m.logger.Info("qemu command line", "id", spec.ID, "command", commandLine(m.cfg.QEMUBinary, args))

	command := exec.Command(m.cfg.QEMUBinary, args...)
	command.Env = engineEnvironment(engineEnv(m.engineHome(spec, runDir)), m.privateTmpDir(spec))
	if tty != nil {
		command.Stdin = tty
		command.Stdout = tty
	} else {
		command.Stdout = logFile
	}
	command.Stderr = logFile
	identity.apply(command)
	if err := m.ownLabRunTree(spec, identity); err != nil {
		_ = logFile.Close()
		_ = ptmx.Close()
		_ = tty.Close()
		m.cleanupTaps(taps)
		return NodeInfo{}, err
	}

	if err := engineSpawn(command); err != nil {
		_ = logFile.Close()
		_ = ptmx.Close()
		_ = tty.Close()
		m.cleanupTaps(taps)
		return NodeInfo{}, fmt.Errorf("start qemu: %w", err)
	}
	_ = tty.Close()
	_ = logFile.Close()

	if vnc != nil {
		m.ownLabSocket(identity, vnc.MonitorPath)
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
		LabUID:      identity.UID,
		LabGID:      identity.GID,
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
	if node.Spec.Engine == "docker" {
		m.mu.Unlock()
		return m.stopDocker(id, node)
	}
	if node.Cmd == nil || node.Cmd.Process == nil {
		m.mu.Unlock()
		return fmt.Errorf("stop node %s: engine process is missing", id)
	}
	pid := node.Cmd.Process.Pid
	m.mu.Unlock()

	if err := signalEngine(pid, syscall.SIGTERM); err != nil && !errors.Is(err, syscall.ESRCH) {
		return fmt.Errorf("stop node %s: signal: %w", id, err)
	}
	select {
	case <-node.Done:
	case <-time.After(5 * time.Second):
		if err := signalEngine(pid, syscall.SIGKILL); err != nil && !errors.Is(err, syscall.ESRCH) {
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
	m.mu.Lock()
	node, tracked := m.nodes[id]
	m.mu.Unlock()
	if tracked && node.Spec.Engine == "docker" {
		return m.wipeDocker(id, node)
	}

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

	m.removeStatePath(node.StatePath)
	if !pathWithinRoot(m.runRoot(), filepath.Clean(node.RunDir)) {
		return fmt.Errorf("refusing to remove run directory outside %s", m.runRoot())
	}
	if err := os.RemoveAll(node.RunDir); err != nil {
		return fmt.Errorf("remove run directory: %w", err)
	}
	// Release the lab uid only once its subtree holds no node any more.
	m.releaseNodeRunRoot(node.Spec)
	m.logger.Info("node wiped", "id", id)

	return nil
}

func (m *Manager) Status(id string) (NodeInfo, error) {
	m.mu.Lock()
	node, ok := m.nodes[id]
	m.mu.Unlock()
	if !ok {
		return NodeInfo{}, errors.New("node not found")
	}
	// A re-registered container has no in-memory process to reflect; refresh
	// its state from docker before reporting it.
	if node.Spec.Engine == "docker" {
		m.refreshDockerNode(node)
	}

	m.mu.Lock()
	defer m.mu.Unlock()

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

// touchActivity records console activity for one node. The timestamp is
// in-memory only: a running node restored after an agent restart is treated as
// active again at re-registration, which errs on the safe side because it
// never causes a wrongful idle wipe. Unknown node ids are ignored.
func (m *Manager) touchActivity(id string) {
	if id == "" {
		return
	}
	m.mu.Lock()
	if node, ok := m.nodes[id]; ok {
		node.LastActivity = time.Now()
	}
	m.mu.Unlock()
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
		m.removeStatePath(node.StatePath)
		node.StatePath = ""
		close(node.Done)
	}
	m.mu.Unlock()
}

func (m *Manager) info(node *Node) NodeInfo {
	info := NodeInfo{
		ID:           node.Spec.ID,
		LabID:        node.Spec.LabID,
		Name:         node.Spec.Name,
		Engine:       node.Spec.Engine,
		Image:        node.Spec.Image,
		State:        node.State,
		RunDir:       node.RunDir,
		Taps:         append([]string(nil), node.Taps...),
		Console:      node.Spec.Console,
		ConsolePort:  node.ConsolePort,
		Started:      node.Started,
		LastActivity: node.LastActivity,
		Cgroup:       node.CgroupPath,
		VNCPort:      node.VNCPort,
		Ready:        node.State == "running" && node.Ready.Load(),
	}
	if node.Cmd != nil && node.Cmd.Process != nil {
		info.PID = node.Cmd.Process.Pid
	} else if node.ContainerPID > 0 {
		info.PID = node.ContainerPID
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
	// A running node with no recorded activity is either a fresh launch or a
	// node restored after an agent restart; both count as active now, so a
	// quiet guest is never idle-wiped on stale evidence. A stopped restore has
	// no console to be active on and keeps its start time.
	if node.LastActivity.IsZero() {
		if node.State == "running" {
			node.LastActivity = time.Now()
		} else {
			node.LastActivity = node.Started
		}
	}
	m.saveNodeState(node)

	// Publish the node and snapshot its info in one critical section: the
	// docker pump goroutine may already be touching activity, and State can
	// change as the engine exits.
	m.mu.Lock()
	m.nodes[node.Spec.ID] = node
	info := m.info(node)
	m.mu.Unlock()

	if node.Spec.Engine == "docker" {
		m.logger.Info("docker node registered",
			"id", info.ID,
			"container", node.ContainerID,
			"state", info.State,
			"run_dir", info.RunDir,
		)
		return info, nil
	}

	go m.wait(node)
	m.logger.Info("node started",
		"id", info.ID,
		"engine", info.Engine,
		"pid", info.PID,
		"run_dir", info.RunDir,
	)

	return info, nil
}

// saveNodeState persists what a later agent process needs to re-register a
// node. Container nodes additionally store the image, container id and bounded
// docker options; the full spec keeps stop, status and wipe working after an
// agent restart without a live exec.Cmd.
func (m *Manager) saveNodeState(node *Node) {
	state := nodeState{
		ID:      node.Spec.ID,
		LabID:   node.Spec.LabID,
		Engine:  node.Spec.Engine,
		Taps:    append([]string(nil), node.Taps...),
		Cgroup:  node.CgroupPath,
		RunDir:  node.RunDir,
		Started: node.Started.UTC().Format(time.RFC3339),
		UID:     node.LabUID,
		GID:     node.LabGID,
	}
	if node.Spec.Engine == "docker" {
		spec := node.Spec
		state.Spec = &spec
		state.Image = node.Spec.Image
		state.ContainerID = node.ContainerID
		state.Docker = node.Spec.Docker
		state.PID = node.ContainerPID
	} else if node.Cmd != nil {
		state.Exe = node.Cmd.Path
		if node.Cmd.Process != nil {
			state.PID = node.Cmd.Process.Pid
			state.StartTicks = processStartTicks(state.PID)
		}
	}
	statePath := m.statePathFor(node.Spec.ID, node.Spec.LabID)
	if err := m.writeStateFile(statePath, state); err != nil {
		if m.logger != nil {
			m.logger.Error("cannot persist trusted node state", "id", node.Spec.ID, "path", statePath, "error", err)
		}

		return
	}
	node.StatePath = statePath
}

func (m *Manager) runDir(spec Spec) (string, error) {
	base := m.runRoot()
	target := m.runDirFor(spec.ID, spec.LabID)
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

// qemuImgTimeout bounds every qemu-img invocation. It is generous enough for
// a large sparse overlay but never leaves an untrusted image parser running.
const qemuImgTimeout = 2 * time.Minute

// maxQEMUImgOutput caps what an error message can carry back to the caller.
const maxQEMUImgOutput = 64 << 10

// ownLabSocket applies the socket mode/ownership for a socket the engine
// creates during startup. A missing path is not an error.
func (m *Manager) ownLabSocket(identity labIdentity, path string) {
	info, err := os.Lstat(path)
	if err != nil || info.Mode()&os.ModeSocket == 0 {
		return
	}
	_ = chmodFile(path, 0o660)
	m.chownAs(identity, path)
}

// qemu-img runners and engine launchers are replaceable in tests. qemuImgRunner
// is declared next to the command builder.
var qemuImgRunner = func(command *exec.Cmd) ([]byte, error) {
	output := &cappedBuffer{limit: maxQEMUImgOutput}
	command.Stdout = output
	command.Stderr = output
	err := command.Run()

	return output.Bytes(), err
}

// cappedBuffer keeps the first limit bytes and silently discards the rest, so
// a hostile or verbose child cannot exhaust agent memory.
type cappedBuffer struct {
	buffer bytes.Buffer
	limit  int
}

func (b *cappedBuffer) Write(data []byte) (int, error) {
	remaining := b.limit - b.buffer.Len()
	if remaining > 0 {
		if len(data) > remaining {
			_, _ = b.buffer.Write(data[:remaining])
		} else {
			_, _ = b.buffer.Write(data)
		}
	}

	return len(data), nil
}

func (b *cappedBuffer) Bytes() []byte {
	return b.buffer.Bytes()
}

// engineSpawn launches a prepared engine command. Tests replace it to inspect
// the credential and argv without requiring root (a credential for an arbitrary
// uid cannot be set by an unprivileged test process).
var engineSpawn = func(command *exec.Cmd) error {
	return command.Start()
}

// qemuImg runs qemu-img with the lab credential and a minimal environment.
// The image store must be readable by that identity; a temporary upload is
// staged by images.InspectAs before inspection.
func (m *Manager) qemuImg(identity labIdentity, home string, args ...string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(context.Background(), qemuImgTimeout)
	defer cancel()
	command := exec.CommandContext(ctx, m.cfg.QEMUImg, args...)
	command.Env = engineEnv(home)
	identity.apply(command)
	output, err := qemuImgRunner(command)
	if errors.Is(ctx.Err(), context.DeadlineExceeded) {
		return output, fmt.Errorf("qemu-img timed out after %s", qemuImgTimeout)
	}

	return output, err
}

func (m *Manager) createOverlay(identity labIdentity, image, overlay string) error {
	output, err := m.qemuImg(identity, filepath.Dir(overlay), "create", "-f", "qcow2", "-F", "qcow2", "-b", image, overlay)
	if err != nil {
		return fmt.Errorf("create overlay: %s: %w", strings.TrimSpace(string(output)), err)
	}
	m.chownAs(identity, overlay)

	return nil
}

// prepareExtraDisks materialises one file per template-declared extra disk and
// returns the paths in slot order. Attached images get a fresh per-node
// overlay, exactly like the primary image; generated disks are created once
// and reused across stop and start. Both live in the run dir, so Wipe removes
// them with the rest of the node state.
func (m *Manager) prepareExtraDisks(spec Spec, runDir string) ([]string, error) {
	if len(spec.Disks) == 0 {
		return nil, nil
	}
	identity, err := m.labIdentityFor(spec)
	if err != nil {
		return nil, err
	}
	for _, disk := range spec.Disks {
		if !validDiskSlot(disk.Slot) {
			return nil, fmt.Errorf("invalid disk slot %q", disk.Slot)
		}
	}
	disks := orderedDisks(spec.Disks)
	paths := make([]string, 0, len(disks))
	for _, disk := range disks {
		path := filepath.Join(runDir, "disk-"+disk.Slot+".qcow2")
		if disk.Image != "" {
			if err := m.createOverlay(identity, disk.Image, path); err != nil {
				return nil, err
			}
		} else if err := m.createGeneratedDisk(identity, path, disk.SizeGB); err != nil {
			return nil, err
		}
		paths = append(paths, path)
	}

	return paths, nil
}

// createGeneratedDisk creates a blank qcow2 file only when it is absent, so a
// stop/start cycle keeps the guest's data. Validation bounds the size before
// this runs; qemu-img still owns the actual sparse allocation.
func (m *Manager) createGeneratedDisk(identity labIdentity, path string, sizeGB int) error {
	if _, err := os.Stat(path); err == nil {
		return nil
	} else if !errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("stat generated disk: %w", err)
	}
	output, err := m.qemuImg(identity, filepath.Dir(path), "create", "-f", "qcow2", path, strconv.Itoa(sizeGB)+"G")
	if err != nil {
		return fmt.Errorf("create disk %s: %s: %w", filepath.Base(path), strings.TrimSpace(string(output)), err)
	}
	m.chownAs(identity, path)

	return nil
}

func (m *Manager) cleanupTaps(taps []string) {
	for _, tap := range taps {
		_ = m.net.DeleteLink(tap)
	}
}

// signalEngine signals the process group only when pid is its group leader.
// The agent always starts engines with Setpgid (or a fresh session through a
// pty), so the group is TeraLab-owned; a process that is not a leader is
// signalled individually instead of risking an unrelated group.
func signalEngine(pid int, signal syscall.Signal) error {
	if pid <= 0 {
		return syscall.EINVAL
	}
	if group, err := syscall.Getpgid(pid); err == nil && group == pid {
		return syscall.Kill(-pid, signal)
	}

	return syscall.Kill(pid, signal)
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

	labDir := m.cgroupLabDir(spec.LabID)
	if labDir != "" && filepath.Clean(labDir) != filepath.Clean(base) {
		if err := os.MkdirAll(labDir, 0o755); err != nil {
			return "", fmt.Errorf("create lab cgroup: %w", err)
		}
		// Controllers must be enabled in the lab parent for the node's
		// memory.max/cpu.max files to exist.
		_ = os.WriteFile(filepath.Join(labDir, "cgroup.subtree_control"), []byte("+cpu +memory"), 0o644)
	}

	child := m.cgroupNodePath(spec)
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
	root := m.cgroupRoot()
	target, err := filepath.Abs(path)
	if err != nil || root == "" || !pathWithinRoot(root, target) {
		return
	}
	if !strings.HasPrefix(filepath.Base(target), "node-") {
		return
	}
	// The node cgroup is either a direct child of the root (lab-less legacy) or
	// a child of one lab cgroup. Anything deeper is not ours.
	parent := filepath.Dir(target)
	if parent != root && !(strings.HasPrefix(filepath.Base(parent), "lab-") && filepath.Dir(parent) == root) {
		return
	}
	path = target
	removed := false
	for attempt := 0; attempt < 3; attempt++ {
		if err := os.Remove(path); err == nil {
			removed = true
			break
		}
		time.Sleep(50 * time.Millisecond)
	}
	// Drop the now-empty lab cgroup so an idle lab leaves no kernel object. A
	// failure means another node in the same lab still lives there.
	if removed && parent != root {
		_ = os.Remove(parent)
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
