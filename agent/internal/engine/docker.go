// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/creack/pty"
)

const (
	dockerContainerPrefix = "teralab-"
	// dockerNodeLabel and dockerLabLabel are the only selectors reconcile
	// trusts. A container without the node label is never touched.
	dockerNodeLabel = "teralab.node"
	dockerLabLabel  = "teralab.lab"
	// dockerPSFormat is parsed by reconcileDockerContainers. Labels are
	// restricted to the node/lab id grammar, so "|" cannot appear in a value.
	dockerPSFormat = `{{.ID}}|{{.Names}}|{{.Label "teralab.node"}}|{{.Label "teralab.lab"}}`

	defaultDockerCommand = "/bin/sh"

	// dockerPullTimeout leaves headroom below the API's 60 s agent timeout.
	dockerPullTimeout    = 45 * time.Second
	dockerStartTimeout   = 5 * time.Second
	dockerCommandTimeout = 10 * time.Second
	dockerStopTimeout    = 20 * time.Second
	dockerInspectTimeout = 5 * time.Second
	dockerLogTail        = 20
)

// errDockerNotFound marks an inspect that found no matching object.
var errDockerNotFound = errors.New("docker container not found")

// dockerClient runs the docker CLI. Tests substitute the binary with a fake
// script and can also replace run directly; the agent never links a Docker SDK.
type dockerClient struct {
	binary string
	run    func(ctx context.Context, args ...string) ([]byte, error)
}

func newDockerClient(binary string) *dockerClient {
	return &dockerClient{binary: binary}
}

// execute runs one docker command and returns stdout. Stderr is folded into
// the wrapped error so callers can distinguish "no such object" from a daemon
// failure without parsing stdout.
func (c *dockerClient) execute(ctx context.Context, args ...string) ([]byte, error) {
	if c == nil {
		return nil, errors.New("docker client is not configured")
	}
	if c.run != nil {
		return c.run(ctx, args...)
	}
	command := exec.CommandContext(ctx, c.binary, args...)
	var stderr bytes.Buffer
	command.Stderr = &stderr
	output, err := command.Output()
	if err != nil {
		message := strings.TrimSpace(stderr.String())
		if message == "" {
			message = err.Error()
		}

		return output, fmt.Errorf("docker %s: %s: %w", strings.Join(args, " "), message, err)
	}

	return output, nil
}

func dockerContainerName(nodeID string) string {
	return dockerContainerPrefix + nodeID
}

// dockerBinaryAvailable accepts an absolute path or a bare name resolved on
// PATH, and requires the executable bit.
func dockerBinaryAvailable(binary string) bool {
	if binary == "" {
		return false
	}
	_, err := exec.LookPath(binary)

	return err == nil
}

// dockerBaselineCaps is Docker's default capability set. Containers are
// created with --cap-drop=ALL and this fixed baseline is added back, so images
// behave as they do under a normal container while the effective set stays
// explicit. The baseline is never template-controlled.
var dockerBaselineCaps = []string{
	"CHOWN",
	"DAC_OVERRIDE",
	"FSETID",
	"FOWNER",
	"MKNOD",
	"NET_RAW",
	"SETGID",
	"SETUID",
	"SETFCAP",
	"SETPCAP",
	"NET_BIND_SERVICE",
	"SYS_CHROOT",
	"KILL",
	"AUDIT_WRITE",
}

// dockerCreateArgs renders the hardened `docker create` argv. There is no
// mount, device, host network, privileged flag, entrypoint override or
// arbitrary extra argument: capabilities are the fixed Docker baseline plus
// the validated template cap_add allowlist, and the image's own default
// command is kept.
func dockerCreateArgs(spec Spec) ([]string, error) {
	if err := validateDockerImage(spec.Image); err != nil {
		return nil, err
	}
	if spec.CPU < 1 || spec.CPU > 64 {
		return nil, errors.New("invalid cpu count for a docker node")
	}
	if spec.RAM < 64 || spec.RAM > 131072 {
		return nil, errors.New("invalid memory size for a docker node")
	}

	// -i -t keeps shell-default images (for example alpine) alive without
	// overriding their entrypoint: the image's shell keeps a stdin and a tty.
	// Daemon-style images such as FRR ignore the extra tty and behave as before.
	args := []string{
		"create",
		"-i", "-t",
		"--name", dockerContainerName(spec.ID),
		"--label", dockerNodeLabel + "=" + spec.ID,
	}
	if spec.LabID != "" {
		args = append(args, "--label", dockerLabLabel+"="+spec.LabID)
	}
	args = append(args, "--network=none", "--cap-drop=ALL")
	// Restore Docker's default set explicitly after the drop. Template caps
	// are additions on top of this baseline.
	for _, capability := range dockerBaselineCaps {
		args = append(args, "--cap-add", capability)
	}
	for _, capability := range dockerCapAdd(spec.Docker) {
		args = append(args, "--cap-add", capability)
	}
	// No seccomp override: the image keeps docker's default profile.
	args = append(
		args,
		"--security-opt=no-new-privileges",
		"--pids-limit=1024",
		"--memory="+strconv.Itoa(spec.RAM)+"m",
		"--cpus="+strconv.Itoa(spec.CPU),
		"--restart=no",
		spec.Image,
	)

	return args, nil
}

// dockerCapAdd returns template-requested capabilities that are not already
// part of the baseline, deduplicated. Validation runs before start; the
// allowlist is repeated here so a future caller cannot bypass it.
func dockerCapAdd(options *DockerOptions) []string {
	if options == nil {
		return nil
	}
	baseline := make(map[string]bool, len(dockerBaselineCaps))
	for _, capability := range dockerBaselineCaps {
		baseline[capability] = true
	}
	result := make([]string, 0, len(options.CapAdd))
	seen := map[string]bool{}
	for _, capability := range options.CapAdd {
		if !dockerCapabilities[capability] || baseline[capability] || seen[capability] {
			continue
		}
		seen[capability] = true
		result = append(result, capability)
	}

	return result
}

// ensureDockerImage inspects the local image store and, when allowed, pulls a
// missing reference with a bounded timeout. Digest references work unchanged.
func (m *Manager) ensureDockerImage(spec Spec) error {
	inspectCtx, cancelInspect := context.WithTimeout(context.Background(), dockerInspectTimeout)
	_, inspectErr := m.docker.execute(inspectCtx, "image", "inspect", spec.Image)
	cancelInspect()
	if inspectErr == nil {
		return nil
	}
	if !spec.Docker.PullEnabled() {
		return fmt.Errorf(
			"docker image %s is not available locally; pull it with docker pull %s",
			spec.Image,
			spec.Image,
		)
	}

	pullCtx, cancelPull := context.WithTimeout(context.Background(), dockerPullTimeout)
	defer cancelPull()
	output, err := m.docker.execute(pullCtx, "pull", spec.Image)
	if err != nil {
		if errors.Is(pullCtx.Err(), context.DeadlineExceeded) {
			return fmt.Errorf(
				"%w: pulling docker image %s timed out after %s",
				ErrEngineUnavailable,
				spec.Image,
				dockerPullTimeout,
			)
		}

		return fmt.Errorf(
			"%w: pull docker image %s: %s",
			ErrEngineUnavailable,
			spec.Image,
			strings.TrimSpace(string(output)),
		)
	}

	return nil
}

type dockerInspectState struct {
	Status   string `json:"Status"`
	Running  bool   `json:"Running"`
	Paused   bool   `json:"Paused"`
	ExitCode int    `json:"ExitCode"`
	Pid      int    `json:"Pid"`
	Error    string `json:"Error"`
}

type dockerInspect struct {
	ID     string `json:"Id"`
	Name   string `json:"Name"`
	Config struct {
		Image     string            `json:"Image"`
		Labels    map[string]string `json:"Labels"`
		Tty       bool              `json:"Tty"`
		OpenStdin bool              `json:"OpenStdin"`
	} `json:"Config"`
	State           dockerInspectState `json:"State"`
	NetworkSettings struct {
		SandboxKey string `json:"SandboxKey"`
	} `json:"NetworkSettings"`
}

func (m *Manager) inspectContainer(ctx context.Context, reference string) (*dockerInspect, error) {
	output, err := m.docker.execute(ctx, "inspect", reference)
	if err != nil {
		return nil, err
	}
	var entries []dockerInspect
	if err := json.Unmarshal(output, &entries); err != nil {
		return nil, fmt.Errorf("decode docker inspect: %w", err)
	}
	if len(entries) == 0 {
		return nil, errDockerNotFound
	}

	return &entries[0], nil
}

func isNoSuchContainer(err error) bool {
	if err == nil {
		return false
	}
	if errors.Is(err, errDockerNotFound) {
		return true
	}
	message := strings.ToLower(err.Error())

	return strings.Contains(message, "no such object") ||
		strings.Contains(message, "no such container") ||
		strings.Contains(message, "no such id")
}

func firstField(value string) string {
	fields := strings.Fields(value)
	if len(fields) == 0 {
		return ""
	}

	return fields[0]
}

func (m *Manager) waitDockerRunning(containerID string) (*dockerInspect, error) {
	deadline := time.Now().Add(dockerStartTimeout)
	for {
		ctx, cancel := context.WithTimeout(context.Background(), dockerInspectTimeout)
		inspect, err := m.inspectContainer(ctx, containerID)
		cancel()
		if err == nil {
			switch inspect.State.Status {
			case "running":
				return inspect, nil
			case "exited", "dead", "removing":
				return nil, m.dockerExitError(containerID, inspect)
			}
		} else if !isNoSuchContainer(err) {
			return nil, err
		}
		if time.Now().After(deadline) {
			break
		}
		time.Sleep(100 * time.Millisecond)
	}

	return nil, fmt.Errorf(
		"docker container %s did not reach the running state within %s",
		containerID,
		dockerStartTimeout,
	)
}

func (m *Manager) dockerExitError(containerID string, inspect *dockerInspect) error {
	ctx, cancel := context.WithTimeout(context.Background(), dockerInspectTimeout)
	defer cancel()
	logs, _ := m.docker.execute(ctx, "logs", "--tail", strconv.Itoa(dockerLogTail), containerID)

	message := fmt.Sprintf("docker container %s exited with code %d", containerID, inspect.State.ExitCode)
	if inspect.State.Status != "" {
		message += " (" + inspect.State.Status + ")"
	}
	if inspect.State.Error != "" {
		message += ": " + inspect.State.Error
	}
	if text := strings.TrimSpace(string(logs)); text != "" {
		message += "; last log lines:\n" + text
	}

	return errors.New(message)
}

// startDocker creates or reuses a labelled container, starts it, moves one
// veth end per interface into its network namespace, and attaches the console
// exec client. The container keeps its filesystem between stop and start.
func (m *Manager) startDocker(spec Spec, runDir string) (NodeInfo, error) {
	if m.docker == nil || m.docker.binary == "" {
		return NodeInfo{}, fmt.Errorf("%w: docker binary is not configured", ErrEngineUnavailable)
	}
	if !dockerBinaryAvailable(m.docker.binary) {
		return NodeInfo{}, fmt.Errorf(
			"%w: docker is not installed; install the docker engine and start its daemon",
			ErrEngineUnavailable,
		)
	}
	if err := validateDockerImage(spec.Image); err != nil {
		return NodeInfo{}, err
	}
	if err := m.ensureDockerImage(spec); err != nil {
		return NodeInfo{}, err
	}

	ctx, cancel := context.WithTimeout(context.Background(), dockerCommandTimeout)
	defer cancel()

	name := dockerContainerName(spec.ID)
	existing, err := m.inspectContainer(ctx, name)
	if err != nil && !isNoSuchContainer(err) {
		return NodeInfo{}, fmt.Errorf("inspect docker container %s: %w", name, err)
	}
	containerID := ""
	createdFresh := false
	if err == nil {
		if existing.Config.Labels[dockerNodeLabel] != spec.ID {
			return NodeInfo{}, fmt.Errorf(
				"docker container %s already exists and is not managed by TeraLab",
				name,
			)
		}
		labelsMatch := existing.Config.Labels[dockerLabLabel] == spec.LabID
		if !labelsMatch || existing.Config.Image != spec.Image {
			// The template image or the owning lab changed. This is our own
			// labelled container, so it is safe to replace; node filesystem
			// state is intentionally lost when the image itself changes.
			if _, removeErr := m.docker.execute(ctx, "rm", "-f", existing.ID); removeErr != nil {
				return NodeInfo{}, fmt.Errorf(
					"replace docker container %s: %s: %w",
					name,
					strings.TrimSpace(existing.ID),
					removeErr,
				)
			}
		} else {
			containerID = existing.ID
		}
	}

	if containerID == "" {
		args, err := dockerCreateArgs(spec)
		if err != nil {
			return NodeInfo{}, err
		}
		m.logger.Info("docker create", "id", spec.ID, "command", commandLine(m.docker.binary, args))
		output, err := m.docker.execute(ctx, args...)
		if err != nil {
			return NodeInfo{}, fmt.Errorf(
				"create docker container: %s: %w",
				strings.TrimSpace(string(output)),
				err,
			)
		}
		containerID = firstField(string(output))
		if containerID == "" {
			return NodeInfo{}, errors.New("docker create returned no container id")
		}
		createdFresh = true
	}

	if output, err := m.docker.execute(ctx, "start", containerID); err != nil {
		if createdFresh {
			_ = m.removeDockerContainer(containerID)
		}

		return NodeInfo{}, fmt.Errorf(
			"start docker container: %s: %w",
			strings.TrimSpace(string(output)),
			err,
		)
	}
	inspect, err := m.waitDockerRunning(containerID)
	if err != nil {
		// A freshly created container that never ran must not linger as an
		// orphan. A reused container keeps its filesystem for the next start.
		if createdFresh {
			_ = m.removeDockerContainer(containerID)
		}

		return NodeInfo{}, err
	}
	if inspect.ID != "" {
		containerID = inspect.ID
	}

	taps := make([]string, 0, spec.Ethernet)
	for index := 0; index < spec.Ethernet; index++ {
		host := spec.TapName(index)
		if err := m.attachDockerInterface(spec, index, host, inspect); err != nil {
			m.cleanupTaps(taps)
			if createdFresh {
				_ = m.removeDockerContainer(containerID)
			} else {
				m.stopDockerContainer(containerID)
			}

			return NodeInfo{}, err
		}
		taps = append(taps, host)
	}

	node := &Node{
		Spec:         spec,
		RunDir:       runDir,
		Taps:         taps,
		ContainerID:  containerID,
		ContainerPID: inspect.State.Pid,
		State:        "running",
		Started:      time.Now(),
		Done:         make(chan struct{}),
		consoleDone:  make(chan struct{}),
		subscribers:  map[chan []byte]struct{}{},
	}
	// Docker has no prompt probe: a running container is ready for the API.
	node.Ready.Store(true)
	if master, command := m.startDockerConsole(spec, containerID, runDir); master != nil {
		node.PTY = master
		node.ExecCmd = command
		go m.pumpPTY(node)
		go m.reapDockerConsole(node, command)
	}

	return m.register(node)
}

// attachDockerInterface creates one veth pair, attaches the host end to the
// lab bridge with the same per-port LACP/STP mask as a tap, and moves the peer
// into the container netns as eth<index>.
func (m *Manager) attachDockerInterface(
	spec Spec,
	index int,
	host string,
	inspect *dockerInspect,
) error {
	peer := dockerPeerName(spec.LabID, spec.ID, index)
	pid := inspect.State.Pid
	if pid <= 0 {
		return fmt.Errorf("docker container %s has no init pid", inspect.ID)
	}
	sandbox := dockerSandboxKey(inspect)

	// Moving the link itself needs CAP_NET_ADMIN. Entering the container
	// namespace for nsenter/setns needs CAP_SYS_ADMIN, which the agent unit
	// grants; containers themselves are never privileged.
	//
	// Entering by PID reads /proc/<pid>/ns/net, which hosts with
	// kernel.yama.ptrace_scope=1 may deny to a root process without
	// CAP_SYS_PTRACE. TeraLab does not add CAP_SYS_PTRACE; the docker sandbox
	// key path is preferred because iproute2 can open it directly.
	nsenter := []string{"nsenter"}
	if sandbox != "" {
		nsenter = append(nsenter, "--net="+sandbox)
	} else {
		nsenter = append(nsenter, "-t", strconv.Itoa(pid), "-n")
	}
	target := "eth" + strconv.Itoa(index)
	namespaceArgs := func(args ...string) []string {
		return append(append([]string{}, nsenter...), args...)
	}
	runInNamespace := func(args ...string) error {
		full := namespaceArgs(args...)
		output, err := m.net.Run(full[0], full[1:]...)
		if err != nil {
			return fmt.Errorf(
				"configure %s in container netns: %s: %w",
				target,
				strings.TrimSpace(string(output)),
				err,
			)
		}

		return nil
	}
	// Leftovers from a failed attach may still live in either namespace. Both
	// names are TeraLab-owned; ignore a missing link.
	_ = m.net.DeleteLink(host)
	_ = m.net.DeleteLink(peer)
	for _, stale := range []string{peer, target} {
		full := namespaceArgs("ip", "link", "delete", stale)
		_, _ = m.net.Run(full[0], full[1:]...)
	}

	if output, err := m.net.Run("ip", "link", "add", host, "type", "veth", "peer", "name", peer); err != nil {
		return fmt.Errorf("create veth %s: %s: %w", host, strings.TrimSpace(string(output)), err)
	}
	cleanup := func() {
		_ = m.net.DeleteLink(host)
	}

	if spec.Networks[index] != "" {
		if err := m.net.AttachInterface(host, spec.Networks[index]); err != nil {
			cleanup()

			return err
		}
	} else if output, err := m.net.Run("ip", "link", "set", "dev", host, "up"); err != nil {
		cleanup()

		return fmt.Errorf("bring veth %s up: %s: %w", host, strings.TrimSpace(string(output)), err)
	}

	// Prefer the sandbox path: iproute2 opens an absolute netns path directly,
	// avoiding the /proc/<pid>/ns/net ptrace check that denies the move when
	// kernel.yama.ptrace_scope=1 and the agent lacks CAP_SYS_PTRACE. The PID
	// fallback is used only when Docker exposed no trusted sandbox key.
	if sandbox != "" {
		if output, err := m.net.Run("ip", "link", "set", peer, "netns", sandbox); err != nil {
			cleanup()

			return fmt.Errorf(
				"move veth %s into container netns %s: %s: %w",
				peer,
				sandbox,
				strings.TrimSpace(string(output)),
				err,
			)
		}
	} else if output, err := m.net.Run("ip", "link", "set", peer, "netns", strconv.Itoa(pid)); err != nil {
		cleanup()

		return fmt.Errorf(
			"move veth %s into container netns by pid %d: %s: %w "+
				"(if this is a ptrace denial, adjust kernel.yama.ptrace_scope; the agent does not hold CAP_SYS_PTRACE)",
			peer,
			pid,
			strings.TrimSpace(string(output)),
			err,
		)
	}
	if err := runInNamespace("ip", "link", "set", peer, "name", target); err != nil {
		cleanup()

		return err
	}
	if err := runInNamespace("ip", "link", "set", "dev", target, "address", spec.MAC(index)); err != nil {
		cleanup()

		return err
	}
	if err := runInNamespace("ip", "link", "set", "dev", target, "up"); err != nil {
		cleanup()

		return err
	}

	return nil
}

// dockerSandboxKey accepts only the netns paths docker itself manages. A
// value from container metadata is never passed to nsenter otherwise; callers
// fall back to entering by init PID.
func dockerSandboxKey(inspect *dockerInspect) string {
	key := inspect.NetworkSettings.SandboxKey
	if strings.HasPrefix(key, "/var/run/docker/netns/") ||
		strings.HasPrefix(key, "/run/docker/netns/") {
		return key
	}

	return ""
}

func dockerPeerName(labID, nodeID string, index int) string {
	sum := sha256.Sum256([]byte("veth:" + labID + ":" + nodeID + ":" + strconv.Itoa(index)))

	return "tp" + hex.EncodeToString(sum[:6])
}

// startDockerConsole runs the bounded console command through docker exec. A
// failure is logged and never fails the node; the container keeps running.
func (m *Manager) startDockerConsole(spec Spec, containerID, runDir string) (*os.File, *exec.Cmd) {
	commandPath := defaultDockerCommand
	if spec.Docker != nil && spec.Docker.ConsoleCommand != "" {
		commandPath = spec.Docker.ConsoleCommand
	}
	command := exec.Command(m.docker.binary, "exec", "-i", "-t", containerID, commandPath)
	command.Dir = runDir
	command.Env = append(os.Environ(), "TERM=vt100")
	master, err := pty.Start(command)
	if err != nil {
		m.logger.Warn("docker console unavailable", "id", spec.ID, "error", err)

		return nil, nil
	}

	return master, command
}

// reapDockerConsole waits for the console client and closes the pty so the
// pump fan-out ends. It never touches the container.
func (m *Manager) reapDockerConsole(node *Node, command *exec.Cmd) {
	_ = command.Wait()
	if node.PTY != nil {
		_ = node.PTY.Close()
	}
}

func (m *Manager) stopDockerConsole(node *Node) {
	command := node.ExecCmd
	node.ExecCmd = nil
	if command != nil && command.Process != nil {
		pid := command.Process.Pid
		if err := syscall.Kill(-pid, syscall.SIGTERM); err != nil {
			_ = syscall.Kill(pid, syscall.SIGTERM)
		}
		deadline := time.Now().Add(2 * time.Second)
		for time.Now().Before(deadline) {
			if err := syscall.Kill(pid, 0); errors.Is(err, syscall.ESRCH) {
				break
			}
			time.Sleep(20 * time.Millisecond)
		}
		if err := syscall.Kill(pid, 0); err == nil {
			_ = syscall.Kill(-pid, syscall.SIGKILL)
			_ = syscall.Kill(pid, syscall.SIGKILL)
		}
	}
	if node.PTY != nil {
		_ = node.PTY.Close()
	}
}

func (m *Manager) stopDockerContainer(reference string) {
	ctx, cancel := context.WithTimeout(context.Background(), dockerStopTimeout)
	defer cancel()
	_, _ = m.docker.execute(ctx, "stop", reference)
}

// stopDocker stops the container but preserves it so its filesystem survives
// a stop/start cycle. Only the console exec client and veths are removed.
func (m *Manager) stopDocker(id string, node *Node) error {
	m.stopDockerConsole(node)

	m.mu.Lock()
	container := node.ContainerID
	nodeID := node.Spec.ID
	taps := append([]string(nil), node.Taps...)
	m.mu.Unlock()
	if container == "" {
		container = dockerContainerName(nodeID)
	}
	ctx, cancel := context.WithTimeout(context.Background(), dockerStopTimeout)
	defer cancel()
	output, err := m.docker.execute(ctx, "stop", container)
	if err != nil && !isNoSuchContainer(err) {
		return fmt.Errorf(
			"stop docker container %s: %s: %w",
			container,
			strings.TrimSpace(string(output)),
			err,
		)
	}
	m.cleanupTaps(taps)

	m.mu.Lock()
	node.Taps = nil
	node.State = "stopped"
	node.Ready.Store(false)
	node.ContainerPID = 0
	m.mu.Unlock()
	m.saveNodeState(node)
	m.logger.Info("node stopped", "id", id, "engine", "docker", "container", container)

	return nil
}

// wipeDocker removes the preserved container, every veth, the state file and
// the run directory.
func (m *Manager) wipeDocker(id string, node *Node) error {
	_ = m.Stop(id)
	m.stopDockerConsole(node)

	m.mu.Lock()
	container := node.ContainerID
	nodeID := node.Spec.ID
	taps := append([]string(nil), node.Taps...)
	statePath := node.StatePath
	runDir := node.RunDir
	m.mu.Unlock()
	if container == "" {
		container = dockerContainerName(nodeID)
	}
	ctx, cancel := context.WithTimeout(context.Background(), dockerStopTimeout)
	defer cancel()
	output, err := m.docker.execute(ctx, "rm", "-f", container)
	if err != nil && !isNoSuchContainer(err) {
		return fmt.Errorf(
			"remove docker container %s: %s: %w",
			container,
			strings.TrimSpace(string(output)),
			err,
		)
	}
	m.cleanupTaps(taps)

	m.mu.Lock()
	delete(m.nodes, id)
	m.mu.Unlock()

	if statePath != "" {
		_ = os.Remove(statePath)
	}
	if err := os.RemoveAll(runDir); err != nil {
		return fmt.Errorf("remove run directory: %w", err)
	}
	_ = os.Remove(filepath.Dir(runDir))
	m.logger.Info("node wiped", "id", id, "engine", "docker")

	return nil
}

// refreshDockerNode updates a re-registered node from docker so Status does
// not report a container that already exited as running.
func (m *Manager) refreshDockerNode(node *Node) {
	m.mu.Lock()
	container := node.ContainerID
	nodeID := node.Spec.ID
	m.mu.Unlock()
	if container == "" {
		container = dockerContainerName(nodeID)
	}
	ctx, cancel := context.WithTimeout(context.Background(), dockerInspectTimeout)
	defer cancel()
	inspect, err := m.inspectContainer(ctx, container)
	if err != nil {
		return
	}
	m.mu.Lock()
	node.ContainerID = inspect.ID
	node.ContainerPID = inspect.State.Pid
	if inspect.State.Running {
		node.State = "running"
		node.Ready.Store(true)
	} else {
		node.State = "stopped"
		node.Ready.Store(false)
	}
	m.mu.Unlock()
	if !inspect.State.Running {
		m.stopDockerConsole(node)
	}
}

type dockerReconcile struct {
	// handled lists state files whose container was registered or removed; the
	// generic sweep must not touch them again.
	handled      map[string]bool
	registered   map[string]string
	removed      []string
	removedDirs  []string
	removedLinks []string
	// listed is true only when docker ps completed. Without it the reconcile
	// cannot tell a missing container from a stopped daemon, so docker state
	// files must be left alone.
	listed bool
	err    error
}

// reconcileDockerContainers reconciles labelled containers against the node
// state files. Unlabelled containers are invisible to the ps filter and are
// never touched.
func (m *Manager) reconcileDockerContainers(
	keepLabIDs []string,
	records []stateFileRecord,
) dockerReconcile {
	result := dockerReconcile{handled: map[string]bool{}, registered: map[string]string{}}
	if m.docker == nil || m.docker.binary == "" {
		return result
	}
	if !dockerBinaryAvailable(m.docker.binary) {
		return result
	}

	ctx, cancel := context.WithTimeout(context.Background(), dockerStopTimeout)
	defer cancel()
	output, err := m.docker.execute(
		ctx,
		"ps", "-a",
		"--filter", "label="+dockerNodeLabel,
		"--format", dockerPSFormat,
	)
	if err != nil {
		// The daemon may simply be stopped. Keep every state file untouched so
		// a later reconcile can still find the containers.
		m.logger.Warn("docker reconcile skipped: cannot list containers", "error", err)

		return result
	}
	result.listed = true

	states := map[string]stateFileRecord{}
	for _, record := range records {
		if record.state.Engine != "docker" {
			continue
		}
		states[record.state.ID] = record
	}
	keepLabs := map[string]bool{}
	for _, labID := range keepLabIDs {
		if labID != "" {
			keepLabs[labID] = true
		}
	}
	liveLabs := map[string]bool{}
	m.mu.Lock()
	for _, node := range m.nodes {
		if node.State == "running" && node.Spec.LabID != "" {
			liveLabs[node.Spec.LabID] = true
		}
	}
	m.mu.Unlock()

	note := func(err error) {
		if err != nil && result.err == nil {
			result.err = err
		}
	}
	for _, line := range strings.Split(strings.TrimSpace(string(output)), "\n") {
		if strings.TrimSpace(line) == "" {
			continue
		}
		fields := strings.SplitN(line, "|", 4)
		if len(fields) < 4 {
			continue
		}
		containerID := strings.TrimSpace(fields[0])
		nodeID := strings.TrimSpace(fields[2])
		labLabel := strings.TrimSpace(fields[3])
		if nodeID == "" {
			continue
		}
		if m.nodeRunning(nodeID) {
			continue
		}

		record, hasState := states[nodeID]
		if !hasState {
			if err := m.removeDockerContainer(containerID); err != nil {
				note(err)
				continue
			}
			result.removed = append(result.removed, containerID)
			continue
		}

		labID := record.state.LabID
		if labID == "" {
			labID = labLabel
		}
		if keepLabIDs != nil && labID != "" && !keepLabs[labID] && !liveLabs[labID] {
			if err := m.removeDockerContainer(containerID); err != nil {
				note(err)
				continue
			}
			links, dirs, cleanupErr := m.removeDockerNodeState(record)
			note(cleanupErr)
			result.removed = append(result.removed, containerID)
			result.removedLinks = append(result.removedLinks, links...)
			result.removedDirs = append(result.removedDirs, dirs...)
			result.handled[record.path] = true
			continue
		}

		if err := m.registerDockerRecord(record); err != nil {
			note(err)
			if !isNoSuchContainer(err) {
				// A daemon or inspect failure must not make the state file (and
				// therefore the preserved container) look like an orphan.
				result.handled[record.path] = true
			}
			continue
		}
		result.handled[record.path] = true
		result.registered[record.state.ID] = labID
	}

	return result
}

func (m *Manager) nodeRunning(id string) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	node, ok := m.nodes[id]

	return ok && node.State == "running"
}

func (m *Manager) removeDockerContainer(reference string) error {
	ctx, cancel := context.WithTimeout(context.Background(), dockerStopTimeout)
	defer cancel()
	output, err := m.docker.execute(ctx, "rm", "-f", reference)
	if err != nil && !isNoSuchContainer(err) {
		return fmt.Errorf(
			"remove docker container %s: %s: %w",
			reference,
			strings.TrimSpace(string(output)),
			err,
		)
	}

	return nil
}

func (m *Manager) removeDockerNodeState(record stateFileRecord) ([]string, []string, error) {
	var firstErr error
	links := make([]string, 0, len(record.state.Taps))
	for _, tap := range record.state.Taps {
		if err := m.net.DeleteLink(tap); err != nil {
			if firstErr == nil {
				firstErr = err
			}
			continue
		}
		links = append(links, tap)
	}

	dir := filepath.Dir(record.path)
	if err := os.RemoveAll(dir); err != nil {
		if firstErr == nil {
			firstErr = err
		}

		return links, nil, firstErr
	}
	_ = os.Remove(filepath.Dir(dir))

	return links, []string{dir}, firstErr
}

// registerDockerRecord rebuilds a node from its state file and the live
// container. It starts a fresh console exec for a running container because
// the old client died with the previous agent process.
func (m *Manager) registerDockerRecord(record stateFileRecord) error {
	ctx, cancel := context.WithTimeout(context.Background(), dockerInspectTimeout)
	defer cancel()
	reference := record.state.ContainerID
	if reference == "" {
		reference = dockerContainerName(record.state.ID)
	}
	inspect, err := m.inspectContainer(ctx, reference)
	if err != nil {
		return fmt.Errorf("inspect docker container for node %s: %w", record.state.ID, err)
	}

	spec := Spec{}
	if record.state.Spec != nil {
		spec = *record.state.Spec
	}
	if spec.ID == "" {
		spec.ID = record.state.ID
	}
	if spec.LabID == "" {
		spec.LabID = record.state.LabID
	}
	spec.Engine = "docker"
	spec.Console = "telnet"
	if spec.Image == "" {
		spec.Image = record.state.Image
	}
	if spec.Image == "" {
		spec.Image = inspect.Config.Image
	}
	if spec.Docker == nil {
		spec.Docker = record.state.Docker
	}
	spec.Normalize()
	if err := spec.Validate(m.cfg.ImageRoots()); err != nil {
		return fmt.Errorf("re-register docker node %s: %w", record.state.ID, err)
	}

	started := time.Now()
	if record.state.Started != "" {
		if parsed, parseErr := time.Parse(time.RFC3339, record.state.Started); parseErr == nil {
			started = parsed
		}
	}
	node := &Node{
		Spec:         spec,
		RunDir:       filepath.Dir(record.path),
		Taps:         append([]string(nil), record.state.Taps...),
		ContainerID:  inspect.ID,
		ContainerPID: inspect.State.Pid,
		State:        "stopped",
		Started:      started,
		Done:         make(chan struct{}),
		consoleDone:  make(chan struct{}),
		subscribers:  map[chan []byte]struct{}{},
	}
	if node.ContainerID == "" {
		node.ContainerID = record.state.ContainerID
	}
	if node.ContainerID == "" {
		node.ContainerID = dockerContainerName(spec.ID)
	}
	if inspect.State.Running {
		node.State = "running"
		node.Ready.Store(true)
		if master, command := m.startDockerConsole(spec, node.ContainerID, node.RunDir); master != nil {
			node.PTY = master
			node.ExecCmd = command
			go m.pumpPTY(node)
			go m.reapDockerConsole(node, command)
		}
	}
	_, err = m.register(node)

	return err
}
