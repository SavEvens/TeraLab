// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/config"
	"github.com/SavEvens/TeraLab/agent/internal/network"
)

// fakeQEMUImgCreate logs every qemu-img argv and creates the target file, so
// tests can assert the exact overlay and generated-disk invocations without a
// real qcow2 stack.
const fakeQEMUImgCreate = `
if [ "$1" = create ]; then
  case " $* " in
    *" -b "*)
      last=""
      for arg in "$@"; do last="$arg"; done
      : > "$last"
      ;;
    *)
      : > "$4"
      ;;
  esac
fi
`

const fakeQEMUImgFailOverlay = `
if [ "$1" = create ]; then
  case " $* " in
    *" -b "*) echo "overlay create failed" >&2; exit 1 ;;
  esac
  : > "$4"
fi
`

func fakeQEMUImg(t *testing.T, body string) (string, string) {
	t.Helper()
	dir := t.TempDir()
	logPath := filepath.Join(dir, "argv.log")
	scriptPath := filepath.Join(dir, "qemu-img")
	script := "#!/bin/sh\nprintf '%s\\n' \"$*\" >> '" + logPath + "'\n" + body + "\n"
	if err := os.WriteFile(scriptPath, []byte(script), 0o755); err != nil {
		t.Fatalf("write fake qemu-img: %v", err)
	}

	return scriptPath, logPath
}

// fakeQEMUBinary records the rendered qemu argv and then stays alive so the
// manager can stop it normally. No KVM and no guest are involved.
func fakeQEMUBinary(t *testing.T) (string, string) {
	t.Helper()
	dir := t.TempDir()
	logPath := filepath.Join(dir, "argv.log")
	scriptPath := filepath.Join(dir, "qemu-system-x86_64")
	script := "#!/bin/sh\nprintf '%s\\n' \"$*\" >> '" + logPath + "'\nexec sleep 30\n"
	if err := os.WriteFile(scriptPath, []byte(script), 0o755); err != nil {
		t.Fatalf("write fake qemu: %v", err)
	}

	return scriptPath, logPath
}

func newDiskTestManager(t *testing.T, qemuImg string) *Manager {
	t.Helper()
	cfg := config.Config{
		RunDir:         t.TempDir(),
		ImagesDir:      t.TempDir(),
		CgroupRoot:     t.TempDir(),
		DisableCgroups: true,
		DisableKVM:     true,
		NetworkBackend: network.BackendLinux,
		QEMUImg:        qemuImg,
		AllowRootVM:    true,
		VMUser:         "teralab-vm",
	}
	manager := NewManager(cfg, slog.New(slog.NewTextHandler(io.Discard, nil)))
	manager.net.SysClassNet = t.TempDir()
	manager.net.Run = func(name string, args ...string) ([]byte, error) { return nil, nil }
	t.Cleanup(manager.Shutdown)

	return manager
}

func readLogLines(t *testing.T, path string) []string {
	t.Helper()
	data, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if err != nil {
		t.Fatalf("read log %s: %v", path, err)
	}
	trimmed := strings.TrimSpace(string(data))
	if trimmed == "" {
		return nil
	}

	return strings.Split(trimmed, "\n")
}

func countLogLines(t *testing.T, path, prefix string) int {
	t.Helper()
	count := 0
	for _, line := range readLogLines(t, path) {
		if strings.HasPrefix(line, prefix) {
			count++
		}
	}

	return count
}

func writeImage(t *testing.T, dir, name string) string {
	t.Helper()
	path := filepath.Join(dir, name)
	if err := os.WriteFile(path, []byte("qcow2"), 0o600); err != nil {
		t.Fatal(err)
	}

	return path
}

func TestSpecJSONDecodesTemplateDisks(t *testing.T) {
	payload := `{"id":"node-1","name":"R1","disks":[` +
		`{"slot":"virtiob","image":"/srv/images/virtiob.qcow2"},` +
		`{"slot":"virtioc","size_gb":40}]}`
	var spec Spec
	if err := json.Unmarshal([]byte(payload), &spec); err != nil {
		t.Fatalf("decode spec: %v", err)
	}
	want := []DiskSpec{
		{Slot: "virtiob", Image: "/srv/images/virtiob.qcow2"},
		{Slot: "virtioc", SizeGB: 40},
	}
	if !reflect.DeepEqual(spec.Disks, want) {
		t.Fatalf("unexpected disks: %+v", spec.Disks)
	}
}

func TestSpecValidatesExtraDisks(t *testing.T) {
	spec, images := testSpec(t)
	extraImage := writeImage(t, images, "virtiob.qcow2")
	notQCOW2 := writeImage(t, images, "virtiob.img")
	outside := writeImage(t, t.TempDir(), "outside.qcow2")

	tests := []struct {
		name    string
		disks   []DiskSpec
		wantErr bool
	}{
		{
			name: "no disks",
		},
		{
			name:  "plan shape image then size",
			disks: []DiskSpec{{Slot: "virtiob", Image: extraImage}, {Slot: "virtioc", SizeGB: 40}},
		},
		{
			name:  "plan shape size then image",
			disks: []DiskSpec{{Slot: "virtioc", SizeGB: 40}, {Slot: "virtiob", Image: extraImage}},
		},
		{
			name: "all three slots",
			disks: []DiskSpec{
				{Slot: "virtiod", SizeGB: 1},
				{Slot: "virtiob", Image: extraImage},
				{Slot: "virtioc", SizeGB: 100},
			},
		},
		{
			name:    "unknown slot",
			disks:   []DiskSpec{{Slot: "virtioa", SizeGB: 5}},
			wantErr: true,
		},
		{
			name:    "empty slot",
			disks:   []DiskSpec{{Slot: "", SizeGB: 5}},
			wantErr: true,
		},
		{
			name:    "duplicate slot",
			disks:   []DiskSpec{{Slot: "virtiob", SizeGB: 5}, {Slot: "virtiob", SizeGB: 6}},
			wantErr: true,
		},
		{
			name:    "image and size",
			disks:   []DiskSpec{{Slot: "virtiob", Image: extraImage, SizeGB: 5}},
			wantErr: true,
		},
		{
			name:    "neither image nor size",
			disks:   []DiskSpec{{Slot: "virtiob"}},
			wantErr: true,
		},
		{
			name:    "size zero",
			disks:   []DiskSpec{{Slot: "virtioc", SizeGB: 0}},
			wantErr: true,
		},
		{
			name:    "size above limit",
			disks:   []DiskSpec{{Slot: "virtioc", SizeGB: 101}},
			wantErr: true,
		},
		{
			name:    "negative size",
			disks:   []DiskSpec{{Slot: "virtioc", SizeGB: -1}},
			wantErr: true,
		},
		{
			name:    "image not qcow2",
			disks:   []DiskSpec{{Slot: "virtiob", Image: notQCOW2}},
			wantErr: true,
		},
		{
			name:    "image outside approved roots",
			disks:   []DiskSpec{{Slot: "virtiob", Image: outside}},
			wantErr: true,
		},
		{
			name:    "image missing",
			disks:   []DiskSpec{{Slot: "virtiob", Image: filepath.Join(images, "missing.qcow2")}},
			wantErr: true,
		},
		{
			name: "too many entries",
			disks: []DiskSpec{
				{Slot: "virtiob", SizeGB: 1},
				{Slot: "virtioc", SizeGB: 1},
				{Slot: "virtiod", SizeGB: 1},
				{Slot: "virtiob", SizeGB: 2},
			},
			wantErr: true,
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			candidate := spec
			candidate.Disks = test.disks
			err := candidate.Validate([]string{images})
			if test.wantErr && err == nil {
				t.Fatal("expected validation to reject the disks")
			}
			if !test.wantErr && err != nil {
				t.Fatalf("expected validation to accept the disks: %v", err)
			}
		})
	}
}

func TestSpecRejectsDisksOnNonQEMUEngines(t *testing.T) {
	for _, engine := range []string{"vpcs", "dynamips", "iol", "docker"} {
		t.Run(engine, func(t *testing.T) {
			spec, images := testSpec(t)
			spec.Engine = engine
			spec.Disks = []DiskSpec{{Slot: "virtioc", SizeGB: 5}}

			err := spec.Validate([]string{images})
			if err == nil || !strings.Contains(err.Error(), "only supported for qemu") {
				t.Fatalf("expected disks on %s to be rejected, got %v", engine, err)
			}
		})
	}
}

func driveIndexOf(args []string, drive string) int {
	for index := 0; index+1 < len(args); index++ {
		if args[index] == "-drive" && args[index+1] == drive {
			return index
		}
	}

	return -1
}

func TestBuildQEMUArgsRendersExtraDisksAfterPrimary(t *testing.T) {
	spec, _ := testSpec(t)
	spec.DiskInterface = "scsi"
	extra := []string{"/run/disk-virtiob.qcow2", "/run/disk-virtioc.qcow2"}

	args, err := BuildQEMUArgs(spec, []string{spec.TapName(0)}, "/run/overlay.qcow2", extra, nil, false)
	if err != nil {
		t.Fatalf("build args: %v", err)
	}

	primary := "file=/run/overlay.qcow2,if=scsi,format=qcow2,cache=none"
	first := "file=" + extra[0] + ",if=virtio,format=qcow2,cache=none"
	second := "file=" + extra[1] + ",if=virtio,format=qcow2,cache=none"
	primaryIndex := driveIndexOf(args, primary)
	firstIndex := driveIndexOf(args, first)
	secondIndex := driveIndexOf(args, second)
	if primaryIndex < 0 {
		t.Fatalf("primary drive missing from %q", args)
	}
	if firstIndex < 0 || secondIndex < 0 {
		t.Fatalf("extra drives missing from %q", args)
	}
	if !(primaryIndex < firstIndex && firstIndex < secondIndex) {
		t.Fatalf("unexpected drive order: primary=%d virtiob=%d virtioc=%d", primaryIndex, firstIndex, secondIndex)
	}
}

func TestBuildQEMUArgsWithoutExtraDisksKeepsSingleDrive(t *testing.T) {
	spec, _ := testSpec(t)
	base, err := BuildQEMUArgs(spec, []string{spec.TapName(0)}, "/run/overlay.qcow2", nil, nil, false)
	if err != nil {
		t.Fatalf("build args: %v", err)
	}
	empty, err := BuildQEMUArgs(spec, []string{spec.TapName(0)}, "/run/overlay.qcow2", []string{}, nil, false)
	if err != nil {
		t.Fatalf("build args: %v", err)
	}
	if !reflect.DeepEqual(base, empty) {
		t.Fatalf("nil and empty extra disks must render identically:\n%q\n%q", base, empty)
	}
	drives := 0
	for _, arg := range base {
		if arg == "-drive" {
			drives++
		}
	}
	if drives != 1 {
		t.Fatalf("expected only the primary drive, got %d in %q", drives, base)
	}
}

func TestPrepareExtraDisksUsesOverlayAndGeneratedFiles(t *testing.T) {
	qemuImg, imgLog := fakeQEMUImg(t, fakeQEMUImgCreate)
	manager := newDiskTestManager(t, qemuImg)
	base := writeImage(t, manager.cfg.ImagesDir, "base-virtiob.qcow2")
	runDir := filepath.Join(manager.cfg.RunDir, "lab-1", "node-1")
	if err := os.MkdirAll(runDir, 0o750); err != nil {
		t.Fatal(err)
	}
	spec := Spec{
		ID:    "node-1",
		LabID: "lab-1",
		Name:  "R1",
		Disks: []DiskSpec{
			{Slot: "virtioc", SizeGB: 5},
			{Slot: "virtiob", Image: base},
		},
	}

	paths, err := manager.prepareExtraDisks(spec, runDir)
	if err != nil {
		t.Fatalf("prepare extra disks: %v", err)
	}
	want := []string{
		filepath.Join(runDir, "disk-virtiob.qcow2"),
		filepath.Join(runDir, "disk-virtioc.qcow2"),
	}
	if !reflect.DeepEqual(paths, want) {
		t.Fatalf("unexpected disk paths: %q", paths)
	}
	for _, path := range paths {
		if info, err := os.Stat(path); err != nil || !info.Mode().IsRegular() {
			t.Fatalf("disk %s was not created: %v", path, err)
		}
	}
	if calls := countLogLines(t, imgLog, "create -f qcow2 -F qcow2 -b "+base+" "+want[0]); calls != 1 {
		t.Fatalf("expected one overlay for virtiob, got %d in %q", calls, readLogLines(t, imgLog))
	}
	if calls := countLogLines(t, imgLog, "create -f qcow2 "+want[1]+" 5G"); calls != 1 {
		t.Fatalf("expected one generated disk for virtioc, got %d in %q", calls, readLogLines(t, imgLog))
	}

	// A second start re-creates the overlay (as the primary does) but must
	// reuse the generated disk so guest data survives stop/start.
	if _, err := manager.prepareExtraDisks(spec, runDir); err != nil {
		t.Fatalf("prepare extra disks again: %v", err)
	}
	if calls := countLogLines(t, imgLog, "create -f qcow2 -F qcow2 -b "+base+" "+want[0]); calls != 2 {
		t.Fatalf("expected the overlay to be re-created once per start, got %d", calls)
	}
	if calls := countLogLines(t, imgLog, "create -f qcow2 "+want[1]+" 5G"); calls != 1 {
		t.Fatalf("generated disk must be created once, got %d", calls)
	}
}

func TestPrepareExtraDisksOverlayBacksOntoImage(t *testing.T) {
	qemuImg, err := exec.LookPath("qemu-img")
	if err != nil {
		t.Skip("qemu-img is required for the backing-file check")
	}
	manager := newDiskTestManager(t, qemuImg)
	base := filepath.Join(manager.cfg.ImagesDir, "base-virtiob.qcow2")
	if output, err := exec.Command(qemuImg, "create", "-f", "qcow2", base, "8M").CombinedOutput(); err != nil {
		t.Fatalf("create base image: %s: %v", output, err)
	}
	runDir := filepath.Join(manager.cfg.RunDir, "lab-1", "node-1")
	if err := os.MkdirAll(runDir, 0o750); err != nil {
		t.Fatal(err)
	}
	spec := Spec{
		ID:    "node-1",
		LabID: "lab-1",
		Name:  "R1",
		Disks: []DiskSpec{
			{Slot: "virtiob", Image: base},
			{Slot: "virtioc", SizeGB: 2},
		},
	}

	paths, err := manager.prepareExtraDisks(spec, runDir)
	if err != nil {
		t.Fatalf("prepare extra disks: %v", err)
	}
	if len(paths) != 2 {
		t.Fatalf("unexpected disk paths: %q", paths)
	}

	var overlay struct {
		BackingFilename string `json:"backing-filename"`
	}
	output, err := exec.Command(qemuImg, "info", "--output=json", paths[0]).Output()
	if err != nil {
		t.Fatalf("qemu-img info overlay: %v", err)
	}
	if err := json.Unmarshal(output, &overlay); err != nil {
		t.Fatalf("decode qemu-img info: %v", err)
	}
	if overlay.BackingFilename != base {
		t.Fatalf("overlay backing file = %q, want %q", overlay.BackingFilename, base)
	}

	var generated struct {
		VirtualSize int64 `json:"virtual-size"`
	}
	output, err = exec.Command(qemuImg, "info", "--output=json", paths[1]).Output()
	if err != nil {
		t.Fatalf("qemu-img info generated disk: %v", err)
	}
	if err := json.Unmarshal(output, &generated); err != nil {
		t.Fatalf("decode qemu-img info: %v", err)
	}
	if want := int64(2) << 30; generated.VirtualSize != want {
		t.Fatalf("generated disk size = %d, want %d", generated.VirtualSize, want)
	}

	// A marker survives a second prepare only when the disk is reused rather
	// than re-created.
	marker := []byte("keep me")
	if err := os.WriteFile(paths[1], marker, 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := manager.prepareExtraDisks(spec, runDir); err != nil {
		t.Fatalf("prepare extra disks again: %v", err)
	}
	data, err := os.ReadFile(paths[1])
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(data, marker) {
		t.Fatal("generated disk was re-created on the second start")
	}
}

func TestPrepareExtraDisksRejectsInvalidSlot(t *testing.T) {
	qemuImg, _ := fakeQEMUImg(t, fakeQEMUImgCreate)
	manager := newDiskTestManager(t, qemuImg)
	runDir := t.TempDir()

	_, err := manager.prepareExtraDisks(Spec{
		ID:    "node-1",
		Name:  "R1",
		Disks: []DiskSpec{{Slot: "../escape", SizeGB: 1}},
	}, runDir)
	if err == nil {
		t.Fatal("expected an invalid slot to be rejected")
	}
}

func TestStartQEMUAttachesDisksInSlotOrderAndReusesGeneratedDisk(t *testing.T) {
	qemuImg, imgLog := fakeQEMUImg(t, fakeQEMUImgCreate)
	qemu, qemuLog := fakeQEMUBinary(t)
	manager := newDiskTestManager(t, qemuImg)
	manager.cfg.QEMUBinary = qemu
	base := writeImage(t, manager.cfg.ImagesDir, "base-virtiob.qcow2")

	spec := Spec{
		ID:       "node-1",
		LabID:    "lab-1",
		Name:     "R1",
		Image:    base,
		Ethernet: 1,
		Networks: []string{network.BridgeName("lab-1", "0")},
		Disks: []DiskSpec{
			{Slot: "virtioc", SizeGB: 2},
			{Slot: "virtiob", Image: base},
		},
	}
	spec.Normalize()

	info, err := manager.Start(spec)
	if err != nil {
		t.Fatalf("start qemu: %v", err)
	}
	line := waitForLogLine(t, qemuLog, "disk-virtioc.qcow2")
	primary := strings.Index(line, "overlay.qcow2")
	virtiob := strings.Index(line, filepath.Join(info.RunDir, "disk-virtiob.qcow2"))
	virtioc := strings.Index(line, filepath.Join(info.RunDir, "disk-virtioc.qcow2"))
	if primary < 0 || virtiob < 0 || virtioc < 0 {
		t.Fatalf("expected all drives on the qemu command line: %s", line)
	}
	if !(primary < virtiob && virtiob < virtioc) {
		t.Fatalf("unexpected drive order on the command line: %s", line)
	}
	for _, name := range []string{"disk-virtiob.qcow2", "disk-virtioc.qcow2"} {
		path := filepath.Join(info.RunDir, name)
		if !strings.HasPrefix(path, info.RunDir+string(os.PathSeparator)) {
			t.Fatalf("disk %s does not live under the run dir %s", path, info.RunDir)
		}
		if _, err := os.Stat(path); err != nil {
			t.Fatalf("disk %s missing after start: %v", path, err)
		}
	}
	if err := manager.Stop(spec.ID); err != nil {
		t.Fatalf("stop qemu: %v", err)
	}

	// Stop/start keeps the generated disk and re-creates only the overlays.
	if _, err := manager.Start(spec); err != nil {
		t.Fatalf("restart qemu: %v", err)
	}
	generated := filepath.Join(info.RunDir, "disk-virtioc.qcow2")
	if calls := countLogLines(t, imgLog, "create -f qcow2 "+generated+" 2G"); calls != 1 {
		t.Fatalf("generated disk must be created once, got %d", calls)
	}
	if _, err := os.Stat(generated); err != nil {
		t.Fatalf("generated disk missing after restart: %v", err)
	}
	if err := manager.Stop(spec.ID); err != nil {
		t.Fatalf("stop qemu after restart: %v", err)
	}
}

func waitForLogLine(t *testing.T, path, substr string) string {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for {
		for _, line := range readLogLines(t, path) {
			if strings.Contains(line, substr) {
				return line
			}
		}
		if time.Now().After(deadline) {
			t.Fatalf("timed out waiting for %q in %s", substr, path)
		}
		time.Sleep(10 * time.Millisecond)
	}
}

func TestStartQEMURemovesRunDirWhenExtraDiskFails(t *testing.T) {
	qemuImg, imgLog := fakeQEMUImg(t, fakeQEMUImgFailOverlay)
	manager := newDiskTestManager(t, qemuImg)
	base := writeImage(t, manager.cfg.ImagesDir, "base-virtioc.qcow2")

	// virtiob is generated first, then the virtioc overlay fails: the partial
	// generated disk must disappear with the run directory.
	spec := Spec{
		ID:       "node-1",
		LabID:    "lab-1",
		Name:     "R1",
		Ethernet: 1,
		Networks: []string{network.BridgeName("lab-1", "0")},
		Disks: []DiskSpec{
			{Slot: "virtiob", SizeGB: 1},
			{Slot: "virtioc", Image: base},
		},
	}
	spec.Normalize()

	if _, err := manager.Start(spec); err == nil {
		t.Fatal("expected the start to fail when an overlay cannot be created")
	}
	runDir := filepath.Join(manager.cfg.RunDir, "lab-1", "node-1")
	if _, err := os.Stat(runDir); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("run directory must be removed after a failed start: %v", err)
	}
	if calls := countLogLines(t, imgLog, "create -f qcow2 "+filepath.Join(runDir, "disk-virtiob.qcow2")+" 1G"); calls != 1 {
		t.Fatalf("expected the generated disk to be attempted before the failure, got %d", calls)
	}
}

func TestWipeRemovesPreparedDiskFiles(t *testing.T) {
	qemuImg, _ := fakeQEMUImg(t, fakeQEMUImgCreate)
	manager := newDiskTestManager(t, qemuImg)
	runDir := filepath.Join(manager.cfg.RunDir, "lab-1", "node-1")
	if err := os.MkdirAll(runDir, 0o750); err != nil {
		t.Fatal(err)
	}
	files := []string{"overlay.qcow2", "disk-virtiob.qcow2", "disk-virtioc.qcow2"}
	for _, name := range files {
		if err := os.WriteFile(filepath.Join(runDir, name), []byte("qcow2"), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	spec := Spec{ID: "node-1", LabID: "lab-1", Name: "R1"}
	manager.nodes[spec.ID] = &Node{Spec: spec, RunDir: runDir, State: "stopped"}

	if err := manager.Wipe(spec.ID); err != nil {
		t.Fatalf("wipe: %v", err)
	}
	for _, name := range files {
		path := filepath.Join(runDir, name)
		if _, err := os.Stat(path); !errors.Is(err, os.ErrNotExist) {
			t.Fatalf("disk %s survived the wipe: %v", path, err)
		}
	}
	if _, err := os.Stat(runDir); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("run directory survived the wipe: %v", err)
	}
}
