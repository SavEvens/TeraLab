// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func testSpec(t *testing.T) (Spec, string) {
	t.Helper()
	images := t.TempDir()
	disk := filepath.Join(images, "disk.qcow2")
	if err := os.WriteFile(disk, []byte("qcow2"), 0o600); err != nil {
		t.Fatal(err)
	}

	spec := Spec{
		ID:       "node-1",
		LabID:    "lab-1",
		Name:     "R1",
		Image:    disk,
		Ethernet: 1,
		Networks: []string{"br0"},
	}
	spec.Normalize()

	return spec, images
}

func TestSpecNormalizeAndValidate(t *testing.T) {
	spec, images := testSpec(t)
	if err := spec.Validate([]string{images}); err != nil {
		t.Fatalf("validation failed: %v", err)
	}
	if spec.Console != "telnet" {
		t.Fatalf("unexpected console: %s", spec.Console)
	}
	if spec.TapName(0) == spec.TapName(1) {
		t.Fatal("tap names must be unique per interface")
	}
	if !strings.HasPrefix(spec.TapName(0), "tl") || len(spec.TapName(0)) > 15 {
		t.Fatalf("invalid tap name: %s", spec.TapName(0))
	}
	if !strings.HasPrefix(spec.MAC(0), "52:54:") {
		t.Fatalf("invalid MAC: %s", spec.MAC(0))
	}
}

func TestSpecRejectsImageOutsideRoot(t *testing.T) {
	_, images := testSpec(t)
	outside := filepath.Join(t.TempDir(), "outside.qcow2")
	if err := os.WriteFile(outside, []byte("qcow2"), 0o600); err != nil {
		t.Fatal(err)
	}

	spec := Spec{
		ID:    "node-1",
		Name:  "R1",
		Image: outside,
	}
	spec.Normalize()

	if err := spec.Validate([]string{images}); err == nil {
		t.Fatal("expected image outside root to be rejected")
	}
}

func TestSpecRejectsInvalidBridgeAndConsole(t *testing.T) {
	spec, images := testSpec(t)
	spec.Networks[0] = "this-bridge-name-is-too-long"
	if err := spec.Validate([]string{images}); err == nil {
		t.Fatal("expected invalid bridge to be rejected")
	}

	spec, images = testSpec(t)
	spec.Console = "spice"
	if err := spec.Validate([]string{images}); err == nil {
		t.Fatal("expected unsupported console to be rejected")
	}

	spec, images = testSpec(t)
	spec.Console = "vnc"
	if err := spec.Validate([]string{images}); err != nil {
		t.Fatalf("expected vnc console to be accepted: %v", err)
	}
}

func TestBuildQEMUArgs(t *testing.T) {
	spec, _ := testSpec(t)
	taps := []string{spec.TapName(0)}

	args, err := BuildQEMUArgs(spec, taps, "/tmp/overlay.qcow2", nil, false)
	if err != nil {
		t.Fatalf("build args failed: %v", err)
	}
	joined := strings.Join(args, " ")
	for _, expected := range []string{
		"-machine pc",
		"-serial stdio",
		"-display none",
		"-monitor none",
		"-netdev tap,id=net0,ifname=" + taps[0],
		"-drive file=/tmp/overlay.qcow2,if=virtio,format=qcow2,cache=none",
		"-accel",
	} {
		if !strings.Contains(joined, expected) {
			t.Fatalf("expected %q in %s", expected, joined)
		}
	}
	if strings.Contains(joined, "-nodefconfig") {
		t.Fatal("removed QEMU option must not be present")
	}
}

func TestBuildQEMUArgsRejectsTapMismatch(t *testing.T) {
	spec, _ := testSpec(t)
	spec.Ethernet = 2
	spec.Networks = []string{"br0", "br1"}

	if _, err := BuildQEMUArgs(spec, []string{"tl1"}, "", nil, false); err == nil {
		t.Fatal("expected tap mismatch to be rejected")
	}
}

func TestBuildQEMUArgsUsesISO(t *testing.T) {
	images := t.TempDir()
	iso := filepath.Join(images, "test.iso")
	if err := os.WriteFile(iso, []byte("iso"), 0o600); err != nil {
		t.Fatal(err)
	}
	spec := Spec{ID: "node-iso", Name: "ISO", ISO: iso}
	spec.Normalize()
	if err := spec.Validate([]string{images}); err != nil {
		t.Fatalf("validation failed: %v", err)
	}

	args, err := BuildQEMUArgs(spec, nil, "", nil, false)
	if err != nil {
		t.Fatalf("build args failed: %v", err)
	}
	joined := strings.Join(args, " ")
	if !strings.Contains(joined, "-cdrom "+iso) || !strings.Contains(joined, "-boot order=d") {
		t.Fatalf("expected ISO boot flags in %s", joined)
	}
}

func TestBuildQEMUArgsForVNC(t *testing.T) {
	spec, _ := testSpec(t)
	spec.Console = "vnc"
	vnc := &VNCConfig{Display: 7, MonitorPath: "/tmp/monitor.sock"}

	args, err := BuildQEMUArgs(spec, []string{spec.TapName(0)}, "/tmp/overlay.qcow2", vnc, false)
	if err != nil {
		t.Fatalf("build args failed: %v", err)
	}
	joined := strings.Join(args, " ")
	for _, expected := range []string{
		"-vnc 127.0.0.1:7,password=on",
		"-monitor unix:/tmp/monitor.sock,server=on,wait=off",
		"-serial none",
	} {
		if !strings.Contains(joined, expected) {
			t.Fatalf("expected %q in %s", expected, joined)
		}
	}
	if strings.Contains(joined, "-serial stdio") {
		t.Fatal("vnc nodes must not attach a serial stdio console")
	}
	if strings.Contains(joined, "-monitor none") {
		t.Fatal("vnc nodes need the monitor socket")
	}
}

func TestBuildQEMUArgsRequiresVNCConfigForVNC(t *testing.T) {
	spec, _ := testSpec(t)
	spec.Console = "vnc"

	if _, err := BuildQEMUArgs(spec, []string{spec.TapName(0)}, "", nil, false); err == nil {
		t.Fatal("expected missing vnc configuration to be rejected")
	}
}

func TestBuildQEMUArgsRejectsVNCConfigForSerial(t *testing.T) {
	spec, _ := testSpec(t)

	if _, err := BuildQEMUArgs(spec, []string{spec.TapName(0)}, "", &VNCConfig{Display: 1}, false); err == nil {
		t.Fatal("expected vnc configuration on a serial node to be rejected")
	}
}

func TestFreeVNCDisplayIsUnique(t *testing.T) {
	first, err := freeVNCDisplay()
	if err != nil {
		t.Fatalf("free display failed: %v", err)
	}
	if first < 1 || first > 99 {
		t.Fatalf("unexpected display: %d", first)
	}
	if len(randomHex(8)) != 16 {
		t.Fatal("expected a 16-character vnc password")
	}
}

func TestVPCSValidation(t *testing.T) {
	spec := Spec{ID: "pc-1", Name: "PC1", Engine: "vpcs", Ethernet: 1, Networks: []string{"br0"}}
	spec.Normalize()
	if err := spec.Validate([]string{t.TempDir()}); err != nil {
		t.Fatalf("expected vpcs spec to validate: %v", err)
	}

	spec.Console = "vnc"
	if err := spec.Validate([]string{t.TempDir()}); err == nil {
		t.Fatal("expected vpcs vnc console to be rejected")
	}

	spec.Console = "telnet"
	spec.Ethernet = 2
	spec.Networks = []string{"br0", "br1"}
	if err := spec.Validate([]string{t.TempDir()}); err == nil {
		t.Fatal("expected multi-nic vpcs to be rejected")
	}
}

func TestDynamipsValidationAndArgs(t *testing.T) {
	images := t.TempDir()
	image := filepath.Join(images, "c7200.bin")
	if err := os.WriteFile(image, []byte("ios"), 0o600); err != nil {
		t.Fatal(err)
	}

	spec := Spec{
		ID:       "r1",
		LabID:    "lab1",
		Name:     "R1",
		Engine:   "dynamips",
		Image:    image,
		Platform: "c7200",
		Ethernet: 2,
		Networks: []string{"br0", "br1"},
	}
	spec.Normalize()
	if err := spec.Validate([]string{images}); err != nil {
		t.Fatalf("expected dynamips spec to validate: %v", err)
	}
	if len(spec.Slots) != 2 {
		t.Fatalf("expected default slots for each interface, got %v", spec.Slots)
	}

	args, err := BuildDynamipsArgs(spec, []string{"tl1", "tl2"}, 20000)
	if err != nil {
		t.Fatalf("build dynamips args: %v", err)
	}
	joined := strings.Join(args, " ")
	for _, expected := range []string{
		"-P c7200",
		"-r 512",
		"-n 512",
		"-p 20000",
		"-s 0:0:tap:tl1",
		"-s 0:1:tap:tl2",
		image,
	} {
		if !strings.Contains(joined, expected) {
			t.Fatalf("expected %q in %s", expected, joined)
		}
	}

	spec.Platform = "not-a-platform"
	if err := spec.Validate([]string{images}); err == nil {
		t.Fatal("expected invalid platform to be rejected")
	}
}

func TestIOLValidationAndArgs(t *testing.T) {
	images := t.TempDir()
	image := filepath.Join(images, "iol.bin")
	if err := os.WriteFile(image, []byte("iol"), 0o700); err != nil {
		t.Fatal(err)
	}

	spec := Spec{ID: "iol1", Name: "IOL1", Engine: "iol", Image: image}
	spec.Normalize()
	if err := spec.Validate([]string{images}); err != nil {
		t.Fatalf("expected iol spec to validate: %v", err)
	}
	args, err := BuildIOLArgs(spec, 7, false)
	if err != nil {
		t.Fatalf("build iol args: %v", err)
	}
	if strings.Join(args, " ") != "-e 0 -s 0 -m 512 -n 512 7" {
		t.Fatalf("unexpected iol args: %v", args)
	}
	args, err = BuildIOLArgs(spec, 7, true)
	if err != nil {
		t.Fatalf("build iol args with l1: %v", err)
	}
	if strings.Join(args, " ") != "-e 0 -s 0 -m 512 -n 512 -l 7" {
		t.Fatalf("unexpected iol l1 args: %v", args)
	}

	spec.Ethernet = 2
	spec.Networks = []string{"br0", "br1"}
	spec.Normalize()
	args, err = BuildIOLArgs(spec, 7, true)
	if err != nil {
		t.Fatalf("build multi-interface iol args: %v", err)
	}
	if args[0] != "-e" || args[1] != "1" {
		t.Fatalf("expected one iol ethernet adapter for two ports: %v", args)
	}
	spec.Ethernet = 5
	spec.Networks = []string{"br0", "br1", "br2", "br3", "br4"}
	spec.Normalize()
	args, err = BuildIOLArgs(spec, 7, true)
	if err != nil {
		t.Fatalf("build five-port iol args: %v", err)
	}
	if args[0] != "-e" || args[1] != "2" {
		t.Fatalf("expected two iol ethernet adapters for five ports: %v", args)
	}

	netmap := buildNETMAP([][2]int{{10001, 20001}, {10002, 20002}})
	if netmap != "10001:127.0.0.1:20001\n10002:127.0.0.1:20002\n" {
		t.Fatalf("unexpected netmap: %q", netmap)
	}
}

func TestSpecImageRoots(t *testing.T) {
	base := t.TempDir()
	store := filepath.Join(base, "images")
	extra := filepath.Join(base, "addons")
	outside := filepath.Join(base, "elsewhere")
	lookalike := store + "-evil"
	missing := filepath.Join(base, "missing-addons")
	for _, dir := range []string{store, extra, outside, lookalike} {
		if err := os.MkdirAll(dir, 0o750); err != nil {
			t.Fatal(err)
		}
	}
	writeImage := func(dir, name string) string {
		t.Helper()
		path := filepath.Join(dir, name)
		if err := os.WriteFile(path, []byte("qcow2"), 0o600); err != nil {
			t.Fatal(err)
		}

		return path
	}
	storeDisk := writeImage(store, "disk.qcow2")
	extraDisk := writeImage(extra, "hda.qcow2")
	outsideDisk := writeImage(outside, "hda.qcow2")
	lookalikeDisk := writeImage(lookalike, "hda.qcow2")
	makeLink := func(target, dir, name string) string {
		t.Helper()
		link := filepath.Join(dir, name)
		if err := os.Symlink(target, link); err != nil {
			t.Fatal(err)
		}

		return link
	}
	storeLinkToExtra := makeLink(extraDisk, store, "adopted.qcow2")
	storeEscape := makeLink(outsideDisk, store, "escape.qcow2")
	extraEscape := makeLink(outsideDisk, extra, "escape.qcow2")

	approved := []string{store, extra}
	cases := []struct {
		name    string
		image   string
		roots   []string
		wantErr string
	}{
		{name: "primary store image", image: storeDisk, roots: approved},
		{name: "extra root image", image: extraDisk, roots: approved},
		{name: "store link to extra root", image: storeLinkToExtra, roots: approved},
		{name: "missing extra root is inert", image: storeDisk, roots: []string{store, missing}},
		{name: "extra root not approved", image: extraDisk, roots: []string{store}, wantErr: "outside the image root"},
		{name: "outside all roots", image: outsideDisk, roots: approved, wantErr: "outside the image root"},
		{name: "prefix lookalike", image: lookalikeDisk, roots: []string{store}, wantErr: "outside the image root"},
		{name: "store link escaping all roots", image: storeEscape, roots: approved, wantErr: "outside the image root"},
		{name: "extra root link escaping all roots", image: extraEscape, roots: approved, wantErr: "outside the image root"},
		{name: "missing image", image: filepath.Join(store, "absent.qcow2"), roots: approved, wantErr: "file does not exist"},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			spec := Spec{ID: "node-1", Name: "R1", Image: testCase.image}
			spec.Normalize()
			err := spec.Validate(testCase.roots)
			if testCase.wantErr == "" {
				if err != nil {
					t.Fatalf("validation failed: %v", err)
				}

				return
			}
			if err == nil {
				t.Fatal("expected validation to fail")
			}
			if !strings.Contains(err.Error(), testCase.wantErr) {
				t.Fatalf("expected error containing %q, got %v", testCase.wantErr, err)
			}
		})
	}
}

func TestSpecImageRootsRequiresPrimaryRoot(t *testing.T) {
	image := filepath.Join(t.TempDir(), "disk.qcow2")
	if err := os.WriteFile(image, []byte("qcow2"), 0o600); err != nil {
		t.Fatal(err)
	}

	spec := Spec{ID: "node-1", Name: "R1", Image: image}
	spec.Normalize()
	if err := spec.Validate(nil); err == nil {
		t.Fatal("expected image to be rejected without an approved root")
	}
	if err := spec.Validate([]string{filepath.Join(t.TempDir(), "absent")}); err == nil {
		t.Fatal("expected missing image root to be reported")
	}
}

func TestEnginesListIncludesDocker(t *testing.T) {
	found := false
	for _, name := range Engines {
		if name == "docker" {
			found = true
		}
	}
	if !found {
		t.Fatalf("docker engine is missing from %v", Engines)
	}
}

func dockerValidationSpec(image string) Spec {
	spec := Spec{ID: "node-1", LabID: "lab-1", Name: "R1", Engine: "docker", Image: image}
	spec.Normalize()

	return spec
}

func TestDockerSpecAcceptsRegistryReferences(t *testing.T) {
	images := []string{
		"alpine",
		"frrouting/frr:10.0.1",
		"library/debian:bookworm-slim",
		"ghcr.io/org/image:tag",
		"localhost:5000/team/app:v1.2.3",
		"registry.example.com:5000/a/b/c:latest",
		"ghcr.io/org/app@sha256:" + strings.Repeat("a", 64),
		"ghcr.io/org/app:1.0@sha256:" + strings.Repeat("b", 64),
	}
	for _, image := range images {
		t.Run(image, func(t *testing.T) {
			spec := dockerValidationSpec(image)
			if err := spec.Validate(nil); err != nil {
				t.Fatalf("expected %q to validate: %v", image, err)
			}
		})
	}
}

func TestDockerSpecRejectsMalformedReferences(t *testing.T) {
	images := []string{
		"",
		"Alpine",
		"alpine ",
		" alpine",
		"/alpine",
		"../alpine",
		"alpine/",
		"alpine//tag",
		"alpine..bad",
		"alpine:tag:extra",
		"alpine@sha256:1234",
		"alpine@md5:" + strings.Repeat("a", 32),
		strings.Repeat("a", 256),
		"alpine\n",
	}
	for _, image := range images {
		t.Run(image, func(t *testing.T) {
			spec := dockerValidationSpec(image)
			if err := spec.Validate(nil); err == nil {
				t.Fatalf("expected %q to be rejected", image)
			}
		})
	}
}

func TestDockerSpecRejectsNonContainerFields(t *testing.T) {
	cases := []struct {
		name   string
		mutate func(*Spec)
		want   string
	}{
		{name: "iso", mutate: func(spec *Spec) { spec.ISO = "/tmp/disk.iso" }, want: "iso"},
		{name: "kernel", mutate: func(spec *Spec) { spec.Kernel = "/tmp/vmlinuz" }, want: "kernel"},
		{name: "initrd", mutate: func(spec *Spec) { spec.Initrd = "/tmp/initrd" }, want: "initrd"},
		{name: "vnc console", mutate: func(spec *Spec) { spec.Console = "vnc" }, want: "telnet"},
		{name: "serial port", mutate: func(spec *Spec) { spec.Serial = 1 }, want: "serial"},
		{name: "qemu extras", mutate: func(spec *Spec) { spec.Extra = []string{"-smp 4"} }, want: "qemu options"},
		{
			name: "unsupported capability SYS_MODULE",
			mutate: func(spec *Spec) {
				spec.Docker = &DockerOptions{CapAdd: []string{"SYS_MODULE"}}
			},
			want: "unsupported docker capability",
		},
		{
			name: "unsupported capability SYS_PTRACE",
			mutate: func(spec *Spec) {
				spec.Docker = &DockerOptions{CapAdd: []string{"SYS_PTRACE"}}
			},
			want: "unsupported docker capability",
		},
		{
			name: "relative console command",
			mutate: func(spec *Spec) {
				spec.Docker = &DockerOptions{ConsoleCommand: "vtysh"}
			},
			want: "console command",
		},
		{
			name: "console command with arguments",
			mutate: func(spec *Spec) {
				spec.Docker = &DockerOptions{ConsoleCommand: "/usr/bin/vtysh --help"}
			},
			want: "console command",
		},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			spec := dockerValidationSpec("alpine:3.20")
			testCase.mutate(&spec)
			err := spec.Validate(nil)
			if err == nil {
				t.Fatal("expected validation to fail")
			}
			if !strings.Contains(err.Error(), testCase.want) {
				t.Fatalf("expected error containing %q, got %v", testCase.want, err)
			}
		})
	}
}

func TestDockerSpecAcceptsTemplateCapabilities(t *testing.T) {
	for _, capability := range []string{"NET_ADMIN", "NET_RAW", "SYS_ADMIN"} {
		t.Run(capability, func(t *testing.T) {
			spec := dockerValidationSpec("alpine:3.20")
			spec.Docker = &DockerOptions{CapAdd: []string{capability}}
			spec.Normalize()
			if err := spec.Validate(nil); err != nil {
				t.Fatalf("expected %s to validate: %v", capability, err)
			}
		})
	}
}

func TestDockerSpecDedupesCapabilities(t *testing.T) {
	spec := dockerValidationSpec("alpine:3.20")
	spec.Docker = &DockerOptions{CapAdd: []string{"NET_RAW", "NET_ADMIN", "SYS_ADMIN", "NET_RAW"}}
	spec.Normalize()
	if err := spec.Validate(nil); err != nil {
		t.Fatalf("validation failed: %v", err)
	}
	if len(spec.Docker.CapAdd) != 3 {
		t.Fatalf("capabilities were not deduplicated: %v", spec.Docker.CapAdd)
	}
}

func TestDockerPullDefaultsToEnabled(t *testing.T) {
	var missing *DockerOptions
	if !missing.PullEnabled() {
		t.Fatal("a nil docker block must pull by default")
	}
	if !(&DockerOptions{}).PullEnabled() {
		t.Fatal("a nil pull value must pull by default")
	}
	if (&DockerOptions{Pull: boolPointer(false)}).PullEnabled() {
		t.Fatal("pull=false must disable pulling")
	}
}

func TestDockerSpecAcceptsFRRInjectionOptions(t *testing.T) {
	cases := []struct {
		name    string
		daemons []string
		path    string
	}{
		{
			name:    "FRR template shape",
			daemons: []string{"zebra", "ospfd", "bgpd"},
			path:    "/etc/frr/frr.conf",
		},
		{
			name:    "selection order is irrelevant",
			daemons: []string{"pathd", "zebra", "bgpd"},
			path:    "/etc/frr/frr.conf",
		},
		{
			name:    "twelve daemons",
			daemons: dockerDaemonAllowlist[:maxDockerDaemons],
			path:    "/etc/frr/frr.conf",
		},
		{
			name:    "maximum length path",
			daemons: []string{"zebra"},
			path:    "/" + strings.Repeat("a", 200),
		},
		{
			name:    "dots inside a segment are allowed",
			daemons: []string{"zebra"},
			path:    "/etc/frr..d/frr.conf",
		},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			spec := dockerValidationSpec("quay.io/frrouting/frr:10.7.1")
			spec.Docker = &DockerOptions{
				ConsoleCommand: "/usr/bin/vtysh",
				CapAdd:         []string{"NET_ADMIN"},
				Daemons:        testCase.daemons,
				ConfigPath:     testCase.path,
			}
			spec.Normalize()
			if err := spec.Validate(nil); err != nil {
				t.Fatalf("expected the options to validate: %v", err)
			}
		})
	}
}

func TestDockerSpecRejectsInvalidInjectionOptions(t *testing.T) {
	cases := []struct {
		name   string
		mutate func(*Spec)
		want   string
	}{
		{
			name: "unknown daemon",
			mutate: func(spec *Spec) {
				spec.Docker = &DockerOptions{Daemons: []string{"zebra", "zebrad"}}
			},
			want: "unsupported docker daemon",
		},
		{
			name: "uppercase daemon",
			mutate: func(spec *Spec) {
				spec.Docker = &DockerOptions{Daemons: []string{"Zebra"}}
			},
			want: "unsupported docker daemon",
		},
		{
			name: "duplicate daemon",
			mutate: func(spec *Spec) {
				spec.Docker = &DockerOptions{Daemons: []string{"zebra", "ospfd", "zebra"}}
			},
			want: "duplicate docker daemon",
		},
		{
			name: "too many daemons",
			mutate: func(spec *Spec) {
				spec.Docker = &DockerOptions{
					Daemons: dockerDaemonAllowlist[:maxDockerDaemons+1],
				}
			},
			want: "more than 12",
		},
		{
			name: "relative config path",
			mutate: func(spec *Spec) {
				spec.Docker = &DockerOptions{ConfigPath: "etc/frr/frr.conf"}
			},
			want: "config_path",
		},
		{
			name: "parent segment",
			mutate: func(spec *Spec) {
				spec.Docker = &DockerOptions{ConfigPath: "/etc/frr/../frr.conf"}
			},
			want: "parent directory",
		},
		{
			name: "leading parent segment",
			mutate: func(spec *Spec) {
				spec.Docker = &DockerOptions{ConfigPath: "/../etc/frr.conf"}
			},
			want: "parent directory",
		},
		{
			name: "newline in config path",
			mutate: func(spec *Spec) {
				spec.Docker = &DockerOptions{ConfigPath: "/etc/frr/frr.conf\n"}
			},
			want: "control characters",
		},
		{
			name: "NUL in config path",
			mutate: func(spec *Spec) {
				spec.Docker = &DockerOptions{ConfigPath: "/etc/frr/frr\x00.conf"}
			},
			want: "control characters",
		},
		{
			name: "space in config path",
			mutate: func(spec *Spec) {
				spec.Docker = &DockerOptions{ConfigPath: "/etc/frr/frr conf"}
			},
			want: "config_path",
		},
		{
			name: "oversized config path",
			mutate: func(spec *Spec) {
				spec.Docker = &DockerOptions{ConfigPath: "/" + strings.Repeat("a", 201)}
			},
			want: "config_path",
		},
		{
			name: "startup config too large",
			mutate: func(spec *Spec) {
				spec.Config = strings.Repeat("a", maxDockerConfigLen+1)
			},
			want: "invalid startup configuration",
		},
		{
			name: "NUL in startup config",
			mutate: func(spec *Spec) {
				spec.Config = "hostname teralab\x00"
			},
			want: "invalid startup configuration",
		},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			spec := dockerValidationSpec("quay.io/frrouting/frr:10.7.1")
			testCase.mutate(&spec)
			err := spec.Validate(nil)
			if err == nil {
				t.Fatal("expected validation to fail")
			}
			if !strings.Contains(err.Error(), testCase.want) {
				t.Fatalf("expected error containing %q, got %v", testCase.want, err)
			}
		})
	}
}

func TestDockerSpecAcceptsMaximumStartupConfig(t *testing.T) {
	spec := dockerValidationSpec("quay.io/frrouting/frr:10.7.1")
	spec.Docker = &DockerOptions{ConfigPath: "/etc/frr/frr.conf"}
	spec.Config = strings.Repeat("a", maxDockerConfigLen)
	if err := spec.Validate(nil); err != nil {
		t.Fatalf("a 64 KiB startup config must validate: %v", err)
	}
}

func TestDockerDaemonAllowlistIsFrozen(t *testing.T) {
	want := []string{
		"zebra", "bgpd", "ospfd", "ospf6d", "ripd", "ripngd", "isisd", "pimd",
		"pim6d", "ldpd", "nhrpd", "eigrpd", "babeld", "sharpd", "pbrd", "bfdd",
		"fabricd", "vrrpd", "pathd",
	}
	if len(dockerDaemonAllowlist) != len(want) {
		t.Fatalf("allowlist = %v, want %v", dockerDaemonAllowlist, want)
	}
	for index := range want {
		if dockerDaemonAllowlist[index] != want[index] {
			t.Fatalf("allowlist order changed at %d: got %q, want %q", index, dockerDaemonAllowlist[index], want[index])
		}
	}
	if maxDockerDaemons != 12 {
		t.Fatalf("maxDockerDaemons = %d, want 12", maxDockerDaemons)
	}
}
