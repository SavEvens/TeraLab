// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/config"
	"github.com/SavEvens/TeraLab/agent/internal/network"
)

func boolPointer(value bool) *bool {
	return &value
}

// fakeDocker writes a docker shell script that records every argv in the file
// named by FAKE_DOCKER_LOG and then runs body. Tests inject it through
// cfg.DockerBinary so no daemon and no real docker binary are needed.
func fakeDocker(t *testing.T, body string) (string, string) {
	t.Helper()
	dir := t.TempDir()
	logPath := filepath.Join(dir, "argv.log")
	scriptPath := filepath.Join(dir, "docker")
	script := "#!/bin/sh\nprintf '%s\\n' \"$*\" >> \"$FAKE_DOCKER_LOG\"\n" + body + "\n"
	if err := os.WriteFile(scriptPath, []byte(script), 0o755); err != nil {
		t.Fatalf("write fake docker: %v", err)
	}
	t.Setenv("FAKE_DOCKER_LOG", logPath)

	return scriptPath, logPath
}

func dockerCalls(t *testing.T, path string) []string {
	t.Helper()
	data, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if err != nil {
		t.Fatalf("read fake docker log: %v", err)
	}
	lines := strings.Split(strings.TrimSpace(string(data)), "\n")
	if len(lines) == 1 && lines[0] == "" {
		return nil
	}

	return lines
}

func callsContain(calls []string, want string) bool {
	for _, call := range calls {
		if call == want || strings.HasPrefix(call, want+" ") {
			return true
		}
	}

	return false
}

func newDockerTestManager(t *testing.T, binary string) *Manager {
	t.Helper()
	cfg := config.Config{
		RunDir:         t.TempDir(),
		ImagesDir:      t.TempDir(),
		CgroupRoot:     t.TempDir(),
		DisableCgroups: true,
		NetworkBackend: network.BackendLinux,
		VMUser:         "teralab-vm",
		DockerBinary:   binary,
		AllowRootVM:    true,
	}
	manager := NewManager(cfg, slog.New(slog.NewTextHandler(io.Discard, nil)))
	manager.net.SysClassNet = t.TempDir()
	manager.net.Run = func(name string, args ...string) ([]byte, error) { return nil, nil }
	// Close console clients and stop fake containers before TempDir cleanup.
	t.Cleanup(manager.Shutdown)

	return manager
}

func captureNetworkRun(manager *Manager) *[]string {
	calls := []string{}
	manager.net.Run = func(name string, args ...string) ([]byte, error) {
		calls = append(calls, strings.TrimSpace(name+" "+strings.Join(args, " ")))

		return nil, nil
	}

	return &calls
}

func dockerTestSpec(t *testing.T) Spec {
	t.Helper()
	bridge := network.BridgeName("lab-1", "net-1")
	spec := Spec{
		ID:       "node-1",
		LabID:    "lab-1",
		Name:     "R1",
		Engine:   "docker",
		Image:    "frrouting/frr:10.0.1",
		Ethernet: 2,
		Networks: []string{bridge, ""},
		Docker: &DockerOptions{
			ConsoleCommand: "/usr/bin/vtysh",
			CapAdd:         []string{"NET_ADMIN"},
		},
	}
	spec.Normalize()
	if err := spec.Validate(nil); err != nil {
		t.Fatalf("docker spec does not validate: %v", err)
	}

	return spec
}

func TestDockerCreateArgsHardenedDefaults(t *testing.T) {
	spec := dockerTestSpec(t)
	args, err := dockerCreateArgs(spec)
	if err != nil {
		t.Fatalf("create args: %v", err)
	}
	joined := strings.Join(args, " ")
	for _, expected := range []string{
		"create -i -t --name teralab-node-1",
		"--label teralab.node=node-1",
		"--label teralab.lab=lab-1",
		"--network=none",
		"--cap-drop=ALL",
		"--cap-add NET_ADMIN",
		"--security-opt=no-new-privileges",
		"--pids-limit=1024",
		"--memory=512m",
		"--cpus=1",
		"--restart=no",
		spec.Image,
	} {
		if !strings.Contains(joined, expected) {
			t.Fatalf("expected %q in %s", expected, joined)
		}
	}
	for _, forbidden := range []string{
		"--privileged",
		"--mount",
		"--device",
		"--network=bridge",
		"--network=host",
		"--pid=host",
		"--entrypoint",
	} {
		if strings.Contains(joined, forbidden) {
			t.Fatalf("forbidden docker flag %q in %s", forbidden, joined)
		}
	}
	if args[len(args)-1] != spec.Image {
		t.Fatalf("image must be the last argument, got %v", args)
	}
	if strings.Count(joined, "--cap-add") != 1 {
		t.Fatalf("expected one cap-add, got %s", joined)
	}
}

func TestDockerCreateArgsWithoutOptions(t *testing.T) {
	spec := dockerTestSpec(t)
	spec.Docker = nil
	args, err := dockerCreateArgs(spec)
	if err != nil {
		t.Fatalf("create args: %v", err)
	}
	joined := strings.Join(args, " ")
	if strings.Contains(joined, "--cap-add") {
		t.Fatalf("no capabilities expected by default: %s", joined)
	}
	if !strings.Contains(joined, "--cap-drop=ALL") {
		t.Fatalf("cap-drop must always be present: %s", joined)
	}
}

func TestDockerCreateArgsDedupesCaps(t *testing.T) {
	spec := dockerTestSpec(t)
	spec.Docker = &DockerOptions{CapAdd: []string{"NET_ADMIN", "NET_ADMIN", "NET_RAW"}}
	spec.Normalize()
	args, err := dockerCreateArgs(spec)
	if err != nil {
		t.Fatalf("create args: %v", err)
	}
	joined := strings.Join(args, " ")
	if strings.Count(joined, "--cap-add NET_ADMIN") != 1 || strings.Count(joined, "--cap-add NET_RAW") != 1 {
		t.Fatalf("capabilities not deduplicated: %s", joined)
	}
}

func TestDockerPullPolicy(t *testing.T) {
	cases := []struct {
		name        string
		inspectExit int
		pullExit    int
		pull        *bool
		wantPull    bool
		wantErr     string
	}{
		{name: "local image needs no pull", inspectExit: 0},
		{name: "missing image is pulled by default", inspectExit: 1, pullExit: 0, wantPull: true},
		{name: "pull disabled reports the manual command", inspectExit: 1, pull: boolPointer(false), wantErr: "pull it with docker pull alpine:3.20"},
		{name: "pull failure is reported", inspectExit: 1, pullExit: 1, wantPull: true, wantErr: "pull docker image"},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			body := fmt.Sprintf(`if [ "$1" = image ] && [ "$2" = inspect ]; then exit %d; fi
if [ "$1" = pull ]; then echo "pull output"; exit %d; fi
exit 0`, testCase.inspectExit, testCase.pullExit)
			binary, logPath := fakeDocker(t, body)
			// Resolve the fake through PATH, as an operator-configured bare
			// TERALAB_DOCKER would be.
			t.Setenv("PATH", filepath.Dir(binary)+string(os.PathListSeparator)+os.Getenv("PATH"))
			manager := newDockerTestManager(t, "docker")
			spec := Spec{ID: "node-pull", Name: "Pull", Engine: "docker", Image: "alpine:3.20"}
			spec.Normalize()
			spec.Docker = &DockerOptions{Pull: testCase.pull}

			err := manager.ensureDockerImage(spec)
			calls := dockerCalls(t, logPath)
			if testCase.wantErr != "" {
				if err == nil || !strings.Contains(err.Error(), testCase.wantErr) {
					t.Fatalf("expected error containing %q, got %v", testCase.wantErr, err)
				}
				if testCase.name == "pull failure is reported" && !errors.Is(err, ErrEngineUnavailable) {
					t.Fatalf("pull failure must be ErrEngineUnavailable: %v", err)
				}
			} else if err != nil {
				t.Fatalf("ensure image: %v", err)
			}
			pulled := callsContain(calls, "pull alpine:3.20")
			if pulled != testCase.wantPull {
				t.Fatalf("pull called = %v, want %v (calls %v)", pulled, testCase.wantPull, calls)
			}
			if !callsContain(calls, "image inspect alpine:3.20") {
				t.Fatalf("image inspect was not called: %v", calls)
			}
		})
	}
}

func TestDockerStartExitedContainerReportsExitAndLogs(t *testing.T) {
	body := `case "$1" in
image) exit 0 ;;
inspect)
  if [ "$2" = "teralab-node-exit" ]; then
    echo "Error: No such object: $2" >&2
    exit 1
  fi
  cat <<'JSON'
[{"Id":"fakeid","Config":{"Image":"alpine:3.20","Labels":{"teralab.node":"node-exit"}},"State":{"Status":"exited","Running":false,"ExitCode":3},"NetworkSettings":{}}]
JSON
  ;;
create) echo fakeid ;;
start) exit 0 ;;
logs) echo "boom: entrypoint failed" ;;
*) exit 0 ;;
esac`
	binary, logPath := fakeDocker(t, body)
	manager := newDockerTestManager(t, binary)
	spec := Spec{ID: "node-exit", Name: "Exit", Engine: "docker", Image: "alpine:3.20"}
	spec.Normalize()
	spec.Docker = &DockerOptions{Pull: boolPointer(false)}

	_, err := manager.startDocker(spec, t.TempDir())
	if err == nil {
		t.Fatal("expected a start failure for an exited container")
	}
	for _, expected := range []string{"exited with code 3", "boom: entrypoint failed"} {
		if !strings.Contains(err.Error(), expected) {
			t.Fatalf("expected %q in error, got %v", expected, err)
		}
	}
	calls := dockerCalls(t, logPath)
	for _, expected := range []string{"create", "start fakeid", "logs --tail 20 fakeid", "rm -f fakeid"} {
		if !callsContain(calls, expected) {
			t.Fatalf("expected docker call %q in %v", expected, calls)
		}
	}
}

func TestDockerStartReusesContainerAndBuildsVeths(t *testing.T) {
	body := `case "$1" in
image) exit 0 ;;
inspect) cat <<'JSON'
[{"Id":"fakeid","Config":{"Image":"frrouting/frr:10.0.1","Labels":{"teralab.node":"node-1","teralab.lab":"lab-1"}},"State":{"Status":"running","Running":true,"Pid":4242},"NetworkSettings":{"SandboxKey":"/var/run/docker/netns/abc"}}]
JSON
  ;;
start) exit 0 ;;
exec) printf '%s\n' "$@" > "$FAKE_DOCKER_EXEC_ARGS"; exit 0 ;;
*) exit 0 ;;
esac`
	binary, logPath := fakeDocker(t, body)
	execArgsPath := filepath.Join(t.TempDir(), "exec.argv")
	t.Setenv("FAKE_DOCKER_EXEC_ARGS", execArgsPath)
	manager := newDockerTestManager(t, binary)
	netCalls := captureNetworkRun(manager)
	spec := dockerTestSpec(t)

	info, err := manager.startDocker(spec, t.TempDir())
	if err != nil {
		t.Fatalf("start docker: %v", err)
	}
	if info.State != "running" || !info.Ready {
		t.Fatalf("unexpected node info: %+v", info)
	}
	calls := dockerCalls(t, logPath)
	if callsContain(calls, "create") {
		t.Fatalf("a labelled container with a matching image must be reused: %v", calls)
	}
	if !callsContain(calls, "start fakeid") {
		t.Fatalf("expected docker start for the reused container: %v", calls)
	}

	// The console must be one docker exec argv with the command as a single
	// token, never a shell command line.
	deadline := time.Now().Add(2 * time.Second)
	var execPayload []byte
	for {
		execPayload, err = os.ReadFile(execArgsPath)
		if err == nil {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("docker exec argv was not recorded: %v", err)
		}
		time.Sleep(10 * time.Millisecond)
	}
	execArgs := strings.Split(strings.TrimSpace(string(execPayload)), "\n")
	wantExec := []string{"exec", "-i", "-t", "fakeid", "/usr/bin/vtysh"}
	if len(execArgs) != len(wantExec) {
		t.Fatalf("docker exec argv = %v, want %v", execArgs, wantExec)
	}
	for index := range wantExec {
		if execArgs[index] != wantExec[index] {
			t.Fatalf("docker exec argv = %v, want %v", execArgs, wantExec)
		}
	}

	host := spec.TapName(0)
	peer := dockerPeerName(spec.LabID, spec.ID, 0)
	bridge := spec.Networks[0]
	expected := []string{
		"ip link add " + host + " type veth peer name " + peer,
		"ip link set dev " + host + " master " + bridge,
		"ip link set dev " + host + " up",
		"ip link set " + peer + " netns 4242",
		"nsenter --net=/var/run/docker/netns/abc ip link set " + peer + " name eth0",
		"nsenter --net=/var/run/docker/netns/abc ip link set dev eth0 address " + spec.MAC(0),
		"nsenter --net=/var/run/docker/netns/abc ip link set dev eth0 up",
	}
	for _, call := range expected {
		if !callsContain(*netCalls, call) {
			t.Fatalf("expected %q in %v", call, *netCalls)
		}
	}
	manager.Shutdown()
}

func TestDockerVethFallsBackToInitPID(t *testing.T) {
	manager := newDockerTestManager(t, "/bin/true")
	netCalls := captureNetworkRun(manager)
	spec := dockerTestSpec(t)
	spec.Ethernet = 1
	spec.Networks = []string{""}
	spec.Normalize()
	inspect := &dockerInspect{ID: "cid"}
	inspect.State.Pid = 99

	if err := manager.attachDockerInterface(spec, 0, spec.TapName(0), inspect); err != nil {
		t.Fatalf("attach interface: %v", err)
	}
	peer := dockerPeerName(spec.LabID, spec.ID, 0)
	for _, expected := range []string{
		"ip link add " + spec.TapName(0),
		"ip link set dev " + spec.TapName(0) + " up",
		"nsenter -t 99 -n ip link set " + peer + " name eth0",
	} {
		if !callsContain(*netCalls, expected) {
			t.Fatalf("expected %q in %v", expected, *netCalls)
		}
	}
	if callsContain(*netCalls, "master") {
		t.Fatalf("an unlinked interface must not be attached to a bridge: %v", *netCalls)
	}
}

func TestDockerStopPreservesContainerAndWipeRemovesIt(t *testing.T) {
	body := `case "$1" in
image) exit 0 ;;
inspect) cat <<'JSON'
[{"Id":"fakeid","Config":{"Image":"frrouting/frr:10.0.1","Labels":{"teralab.node":"node-1","teralab.lab":"lab-1"}},"State":{"Status":"running","Running":true,"Pid":4242},"NetworkSettings":{}}]
JSON
  ;;
create) echo fakeid ;;
start|stop|rm) exit 0 ;;
exec) exit 0 ;;
*) exit 0 ;;
esac`
	binary, logPath := fakeDocker(t, body)
	manager := newDockerTestManager(t, binary)
	spec := dockerTestSpec(t)

	if _, err := manager.startDocker(spec, t.TempDir()); err != nil {
		t.Fatalf("start docker: %v", err)
	}
	if err := manager.Stop(spec.ID); err != nil {
		t.Fatalf("stop docker: %v", err)
	}
	statePath := filepath.Join(manager.nodes[spec.ID].RunDir, stateFileName)
	if _, err := os.Stat(statePath); err != nil {
		t.Fatalf("stop must preserve the state file: %v", err)
	}
	calls := dockerCalls(t, logPath)
	if !callsContain(calls, "stop fakeid") {
		t.Fatalf("docker stop was not called: %v", calls)
	}
	if callsContain(calls, "rm -f fakeid") {
		t.Fatalf("stop must not remove the container: %v", calls)
	}

	runDir := manager.nodes[spec.ID].RunDir
	wipeCallsStart := len(dockerCalls(t, logPath))
	if err := manager.Wipe(spec.ID); err != nil {
		t.Fatalf("wipe docker: %v", err)
	}
	calls = dockerCalls(t, logPath)[wipeCallsStart:]
	if !callsContain(calls, "rm -f fakeid") {
		t.Fatalf("wipe must remove the container: %v", calls)
	}
	if _, err := os.Stat(runDir); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("wipe must remove the run directory: %v", err)
	}
	if _, err := manager.Status(spec.ID); err == nil {
		t.Fatal("wiped node must no longer be registered")
	}
}

func writeDockerStateFile(t *testing.T, runRoot, labID, nodeID, containerID string) string {
	t.Helper()
	spec := Spec{
		ID:       nodeID,
		LabID:    labID,
		Name:     nodeID,
		Engine:   "docker",
		Image:    "alpine:3.20",
		Ethernet: 0,
		Docker:   &DockerOptions{Pull: boolPointer(false)},
	}
	spec.Normalize()
	state := nodeState{
		ID:          nodeID,
		LabID:       labID,
		Engine:      "docker",
		ContainerID: containerID,
		Image:       spec.Image,
		Docker:      spec.Docker,
		Spec:        &spec,
		Started:     time.Now().UTC().Format(time.RFC3339),
	}
	data, err := json.Marshal(state)
	if err != nil {
		t.Fatalf("marshal docker state: %v", err)
	}
	dir := filepath.Join(runRoot, shortPathComponent(labID), shortPathComponent(nodeID))
	if err := os.MkdirAll(dir, 0o750); err != nil {
		t.Fatalf("create docker run dir: %v", err)
	}
	path := filepath.Join(dir, stateFileName)
	if err := os.WriteFile(path, data, 0o640); err != nil {
		t.Fatalf("write docker state: %v", err)
	}

	return path
}

func writeDockerInspect(t *testing.T, dir, containerID, nodeID, labID string, running bool) {
	t.Helper()
	status := "exited"
	if running {
		status = "running"
	}
	entry := fmt.Sprintf(
		`[{"Id":%q,"Config":{"Image":"alpine:3.20","Labels":{"teralab.node":%q,"teralab.lab":%q}},"State":{"Status":%q,"Running":%t,"Pid":111},"NetworkSettings":{"SandboxKey":"/var/run/docker/netns/%s"}}]`,
		containerID, nodeID, labID, status, running, containerID,
	)
	if err := os.WriteFile(filepath.Join(dir, containerID), []byte(entry), 0o640); err != nil {
		t.Fatalf("write inspect fixture: %v", err)
	}
}

func TestDockerReconcileRegistersAndRemovesOrphans(t *testing.T) {
	dataDir := t.TempDir()
	body := `case "$1" in
ps) printf '%s\n' \
  "cidkeep|teralab-keep|keep|lab-keep" \
  "cidgone|teralab-gone|gone|lab-gone" \
  "cidorphan|teralab-orphan|orphan|lab-orphan" \
  "cidunlabelled|teralab-other||lab-other" ;;
inspect)
  if [ -f "$FAKE_DOCKER_DATA/$2" ]; then cat "$FAKE_DOCKER_DATA/$2"; else echo "Error: No such object: $2" >&2; exit 1; fi ;;
exec) exit 0 ;;
*) exit 0 ;;
esac`
	binary, logPath := fakeDocker(t, body)
	t.Setenv("FAKE_DOCKER_DATA", dataDir)
	manager := newDockerTestManager(t, binary)
	manager.net = network.New("", network.BackendLinux)
	manager.net.SysClassNet = t.TempDir()
	manager.net.Run = func(name string, args ...string) ([]byte, error) { return nil, nil }

	runRoot := manager.cfg.RunDir
	keepState := writeDockerStateFile(t, runRoot, "lab-keep", "keep", "cidkeep")
	goneState := writeDockerStateFile(t, runRoot, "lab-gone", "gone", "cidgone")
	_ = goneState
	writeDockerInspect(t, dataDir, "cidkeep", "keep", "lab-keep", true)
	writeDockerInspect(t, dataDir, "cidgone", "gone", "lab-gone", true)

	result, err := manager.Reconcile([]string{"lab-keep"})
	if err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if _, err := os.Stat(keepState); err != nil {
		t.Fatalf("state file of a kept container was removed: %v", err)
	}
	if _, err := os.Stat(filepath.Dir(goneState)); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("removed container's state directory survived: %v", err)
	}
	if _, ok := manager.nodes["keep"]; !ok {
		t.Fatal("kept container was not re-registered")
	}
	if manager.nodes["keep"].State != "running" || !manager.nodes["keep"].Ready.Load() {
		t.Fatalf("re-registered container is not running: %+v", manager.nodes["keep"])
	}
	if _, ok := manager.nodes["gone"]; ok {
		t.Fatal("orphan container must not be re-registered")
	}
	if len(result.RemovedContainers) != 2 {
		t.Fatalf("expected two removed containers, got %v", result.RemovedContainers)
	}
	calls := dockerCalls(t, logPath)
	if !callsContain(calls, "ps -a --filter label=teralab.node") {
		t.Fatalf("reconcile must select labelled containers only: %v", calls)
	}
	for _, expected := range []string{"rm -f cidgone", "rm -f cidorphan"} {
		if !callsContain(calls, expected) {
			t.Fatalf("expected %q in %v", expected, calls)
		}
	}
	if callsContain(calls, "rm -f cidkeep") || callsContain(calls, "rm -f cidunlabelled") {
		t.Fatalf("kept or unlabelled containers were removed: %v", calls)
	}

	info, err := manager.Status("keep")
	if err != nil || info.State != "running" {
		t.Fatalf("status of a re-registered node: %+v, %v", info, err)
	}
	if err := manager.Stop("keep"); err != nil {
		t.Fatalf("stop a re-registered node: %v", err)
	}
	if !callsContain(dockerCalls(t, logPath), "stop cidkeep") {
		t.Fatal("stop did not address the container by id")
	}
	if err := manager.Wipe("keep"); err != nil {
		t.Fatalf("wipe a re-registered node: %v", err)
	}
	if !callsContain(dockerCalls(t, logPath), "rm -f cidkeep") {
		t.Fatal("wipe did not remove the preserved container")
	}
}

func TestDockerReconcileKeepsStateWithoutKeepSet(t *testing.T) {
	dataDir := t.TempDir()
	body := `case "$1" in
ps) printf '%s\n' "cidkeep|teralab-keep|keep|lab-keep" ;;
inspect) cat "$FAKE_DOCKER_DATA/$2" ;;
exec) exit 0 ;;
*) exit 0 ;;
esac`
	binary, _ := fakeDocker(t, body)
	t.Setenv("FAKE_DOCKER_DATA", dataDir)
	manager := newDockerTestManager(t, binary)
	manager.net.SysClassNet = t.TempDir()
	writeDockerStateFile(t, manager.cfg.RunDir, "lab-keep", "keep", "cidkeep")
	writeDockerInspect(t, dataDir, "cidkeep", "keep", "lab-keep", true)

	if _, err := manager.Reconcile(nil); err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	if _, ok := manager.nodes["keep"]; !ok {
		t.Fatal("startup reconcile must keep a labelled container with a state file")
	}
}

func TestDockerConsoleFailureIsNotFatal(t *testing.T) {
	body := `case "$1" in
image) exit 0 ;;
inspect) cat <<'JSON'
[{"Id":"fakeid","Config":{"Image":"frrouting/frr:10.0.1","Labels":{"teralab.node":"node-1","teralab.lab":"lab-1"}},"State":{"Status":"running","Running":true,"Pid":4242},"NetworkSettings":{}}]
JSON
  ;;
create) echo fakeid ;;
start) exit 0 ;;
exec) echo "no tty" >&2; exit 1 ;;
*) exit 0 ;;
esac`
	binary, _ := fakeDocker(t, body)
	manager := newDockerTestManager(t, binary)
	manager.net.Run = func(name string, args ...string) ([]byte, error) { return nil, nil }
	spec := dockerTestSpec(t)

	info, err := manager.startDocker(spec, t.TempDir())
	if err != nil {
		t.Fatalf("console failure must not fail the node: %v", err)
	}
	if info.State != "running" {
		t.Fatalf("node is not running: %+v", info)
	}
	manager.Shutdown()
}

// TestDockerIntegrationLifecycle needs a live daemon and a local image whose
// default command stays in the foreground. It never pulls: set
// TERALAB_DOCKER_TEST_IMAGE to such an image and TERALAB_DOCKER_INTEGRATION=1,
// or let the test build a tiny wrapper around a local alpine base.
func TestDockerIntegrationLifecycle(t *testing.T) {
	if os.Getenv("TERALAB_DOCKER_INTEGRATION") != "1" {
		t.Skip("set TERALAB_DOCKER_INTEGRATION=1 to run docker integration tests")
	}
	binary := os.Getenv("TERALAB_DOCKER")
	if binary == "" {
		binary = "/usr/bin/docker"
	}
	if _, err := os.Stat(binary); err != nil {
		t.Skipf("docker binary not found at %s", binary)
	}
	if err := exec.Command(binary, "info").Run(); err != nil {
		t.Skipf("docker daemon is not reachable: %v", err)
	}
	image := integrationDockerImage(t, binary)

	cfg := config.Config{
		RunDir:         t.TempDir(),
		ImagesDir:      t.TempDir(),
		CgroupRoot:     t.TempDir(),
		DisableCgroups: true,
		NetworkBackend: network.BackendLinux,
		VMUser:         "teralab-vm",
		DockerBinary:   binary,
		AllowRootVM:    true,
	}
	manager := NewManager(cfg, slog.New(slog.NewTextHandler(io.Discard, nil)))
	spec := Spec{
		ID:       "dockerint" + strconv.FormatInt(time.Now().UnixNano(), 10),
		Name:     "Docker integration",
		Engine:   "docker",
		Image:    image,
		Ethernet: 0,
		Docker: &DockerOptions{
			ConsoleCommand: "/bin/sh",
			Pull:           boolPointer(false),
		},
	}
	spec.Normalize()
	t.Cleanup(func() { _ = manager.Wipe(spec.ID) })

	info, err := manager.Start(spec)
	if err != nil {
		t.Fatalf("start docker integration node: %v", err)
	}
	if info.State != "running" || manager.nodes[spec.ID].ContainerID == "" {
		t.Fatalf("unexpected start info: %+v", info)
	}
	containerName := dockerContainerName(spec.ID)
	inspect, err := manager.inspectContainer(context.Background(), containerName)
	if err != nil {
		t.Fatalf("inspect the started container: %v", err)
	}
	if !inspect.Config.Tty || !inspect.Config.OpenStdin {
		t.Fatalf("container must be created with -i -t: tty=%v open_stdin=%v",
			inspect.Config.Tty, inspect.Config.OpenStdin)
	}

	if err := manager.Stop(spec.ID); err != nil {
		t.Fatalf("stop: %v", err)
	}
	if err := exec.Command(binary, "inspect", containerName).Run(); err != nil {
		t.Fatalf("stop must preserve the container: %v", err)
	}
	if _, err := manager.Start(spec); err != nil {
		t.Fatalf("restart preserved container: %v", err)
	}
	if err := manager.Wipe(spec.ID); err != nil {
		t.Fatalf("wipe: %v", err)
	}
	if err := exec.Command(binary, "inspect", containerName).Run(); err == nil {
		t.Fatal("wipe must remove the container")
	}
}

func TestDockerSpecJSONShape(t *testing.T) {
	spec := Spec{
		ID:       "node-1",
		LabID:    "lab-1",
		Name:     "R1",
		Engine:   "docker",
		Image:    "frrouting/frr:10.0.1",
		Ethernet: 1,
		Networks: []string{""},
		Docker: &DockerOptions{
			ConsoleCommand: "/usr/bin/vtysh",
			CapAdd:         []string{"NET_ADMIN"},
		},
	}
	spec.Normalize()
	payload, err := json.Marshal(spec)
	if err != nil {
		t.Fatalf("marshal spec: %v", err)
	}
	var decoded map[string]any
	if err := json.Unmarshal(payload, &decoded); err != nil {
		t.Fatalf("decode spec: %v", err)
	}
	docker, ok := decoded["docker"].(map[string]any)
	if !ok {
		t.Fatalf("docker block is missing: %s", payload)
	}
	if docker["console_command"] != "/usr/bin/vtysh" {
		t.Fatalf("unexpected console_command: %v", docker["console_command"])
	}
	capabilities, ok := docker["cap_add"].([]any)
	if !ok || len(capabilities) != 1 || capabilities[0] != "NET_ADMIN" {
		t.Fatalf("unexpected cap_add: %v", docker["cap_add"])
	}
	if _, present := docker["pull"]; present {
		t.Fatalf("a nil pull must be omitted so the default is explicit: %s", payload)
	}

	spec.Docker.Pull = boolPointer(false)
	payload, err = json.Marshal(spec)
	if err != nil {
		t.Fatalf("marshal spec with pull: %v", err)
	}
	if !strings.Contains(string(payload), `"pull":false`) {
		t.Fatalf("pull=false missing from %s", payload)
	}
}

func TestDockerReconcileLeavesStateWhenDaemonFails(t *testing.T) {
	body := `case "$1" in
ps) echo "Cannot connect to the Docker daemon" >&2; exit 1 ;;
*) exit 1 ;;
esac`
	binary, _ := fakeDocker(t, body)
	manager := newDockerTestManager(t, binary)
	statePath := writeDockerStateFile(t, manager.cfg.RunDir, "lab-keep", "keep", "cidkeep")

	result, err := manager.Reconcile(nil)
	if err != nil {
		t.Fatalf("reconcile must not fail when docker is unavailable: %v", err)
	}
	if _, err := os.Stat(statePath); err != nil {
		t.Fatalf("state file must survive an unavailable daemon: %v", err)
	}
	if len(result.KilledProcesses) != 0 || len(result.RemovedDirs) != 0 {
		t.Fatalf("unavailable daemon must not reclaim docker resources: %+v", result)
	}
	if _, ok := manager.nodes["keep"]; ok {
		t.Fatal("a container that could not be inspected must not be registered")
	}
}

// integrationDockerImage prefers an operator-supplied local image. When none
// is configured it builds a tiny wrapper around a present alpine base so the
// default command keeps the container alive; neither path pulls from a
// registry.
func integrationDockerImage(t *testing.T, binary string) string {
	t.Helper()
	if image := os.Getenv("TERALAB_DOCKER_TEST_IMAGE"); image != "" {
		if err := exec.Command(binary, "image", "inspect", image).Run(); err != nil {
			t.Skipf("local test image %s is not present; pull it first", image)
		}

		return image
	}

	base := ""
	for _, candidate := range []string{"alpine:3.20", "alpine:latest"} {
		if exec.Command(binary, "image", "inspect", candidate).Run() == nil {
			base = candidate
			break
		}
	}
	if base == "" {
		t.Skip("no local alpine image and no TERALAB_DOCKER_TEST_IMAGE supplied")
	}

	tag := "teralab-docker-int:" + strconv.FormatInt(time.Now().UnixNano(), 10)
	dir := t.TempDir()
	dockerfile := "FROM " + base + "\nCMD [\"sleep\",\"300\"]\n"
	if err := os.WriteFile(filepath.Join(dir, "Dockerfile"), []byte(dockerfile), 0o600); err != nil {
		t.Fatalf("write integration Dockerfile: %v", err)
	}
	output, err := exec.Command(binary, "build", "-t", tag, dir).CombinedOutput()
	if err != nil {
		t.Skipf("cannot build the local integration image: %s", strings.TrimSpace(string(output)))
	}
	t.Cleanup(func() {
		_ = exec.Command(binary, "rmi", "-f", tag).Run()
	})

	return tag
}

func TestDockerBinaryAvailableHonoursPATH(t *testing.T) {
	binary, _ := fakeDocker(t, "exit 0")
	if !dockerBinaryAvailable(binary) {
		t.Fatalf("absolute fake binary should be available: %s", binary)
	}
	t.Setenv("PATH", filepath.Dir(binary))
	if !dockerBinaryAvailable("docker") {
		t.Fatal("bare docker name should resolve on PATH")
	}
	if dockerBinaryAvailable(filepath.Join(t.TempDir(), "missing-docker")) {
		t.Fatal("a missing binary must not be reported available")
	}
}

func TestDockerVethRejectsUntrustedSandboxKey(t *testing.T) {
	manager := newDockerTestManager(t, "/bin/true")
	netCalls := captureNetworkRun(manager)
	spec := dockerTestSpec(t)
	spec.Ethernet = 1
	spec.Networks = []string{""}
	spec.Normalize()
	inspect := &dockerInspect{ID: "cid"}
	inspect.State.Pid = 77
	inspect.NetworkSettings.SandboxKey = "/tmp/not-docker/netns/cid"

	if err := manager.attachDockerInterface(spec, 0, spec.TapName(0), inspect); err != nil {
		t.Fatalf("attach interface: %v", err)
	}
	peer := dockerPeerName(spec.LabID, spec.ID, 0)
	if !callsContain(*netCalls, "nsenter -t 77 -n ip link set "+peer+" name eth0") {
		t.Fatalf("expected the init-pid fallback in %v", *netCalls)
	}
	for _, call := range *netCalls {
		if strings.Contains(call, "--net="+inspect.NetworkSettings.SandboxKey) {
			t.Fatalf("untrusted sandbox key reached nsenter: %v", *netCalls)
		}
	}
}
