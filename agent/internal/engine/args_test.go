// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/SavEvens/TeraLab/agent/internal/config"
)

func goldenSpec(t *testing.T) Spec {
	t.Helper()
	images := t.TempDir()
	disk := filepath.Join(images, "disk.qcow2")
	if err := os.WriteFile(disk, []byte("qcow2"), 0o600); err != nil {
		t.Fatal(err)
	}

	return Spec{
		ID:       "node-1",
		LabID:    "lab-1",
		Name:     "R1",
		Image:    disk,
		Ethernet: 1,
		Networks: []string{"br0"},
	}
}

func buildGoldenArgs(t *testing.T, spec Spec, kvm bool) []string {
	t.Helper()
	args, err := BuildQEMUArgs(spec, []string{spec.TapName(0)}, "/run/overlay.qcow2", nil, nil, kvm)
	if err != nil {
		t.Fatalf("build args: %v", err)
	}

	return args
}

func assertArgv(t *testing.T, got, want []string) {
	t.Helper()
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("unexpected argv:\n got: %q\nwant: %q", got, want)
	}
}

func TestBuildQEMUArgsForC8000VTemplate(t *testing.T) {
	spec := goldenSpec(t)
	spec.NIC = "vmxnet3"
	spec.Machine = "pc"
	spec.CPUModel = "host"
	spec.Accel = "kvm"
	spec.ExtraOptions = "-machine type=pc,accel=kvm -cpu host -serial mon:stdio " +
		"-nographic -no-user-config -nodefaults -rtc base=utc"
	spec.Normalize()

	args := buildGoldenArgs(t, spec, true)
	assertArgv(t, args, []string{
		"-machine", "pc",
		"-smp", "1",
		"-m", "512",
		"-name", "R1",
		"-uuid", spec.UUID,
		"-nodefaults",
		"-no-user-config",
		"-rtc", "base=utc",
		"-display", "none",
		"-no-reboot",
		"-monitor", "none",
		"-serial", "stdio",
		"-accel", "kvm",
		"-cpu", "host",
		"-device", "vmxnet3,netdev=net0,mac=" + spec.MAC(0),
		"-netdev", "tap,id=net0,ifname=" + spec.TapName(0) + ",script=no,downscript=no",
		"-drive", "file=/run/overlay.qcow2,if=virtio,format=qcow2,cache=none",
		"-boot", "order=c",
	})
	for _, duplicate := range []string{"-machine", "-cpu", "-accel", "-serial"} {
		count := 0
		for _, arg := range args {
			if arg == duplicate {
				count++
			}
		}
		if count != 1 {
			t.Fatalf("expected one %s in %q", duplicate, args)
		}
	}
	if strings.Contains(strings.Join(args, " "), "-nographic") {
		t.Fatal("legacy -nographic must be dropped")
	}
}

func TestBuildQEMUArgsForPaloAltoStyleTemplate(t *testing.T) {
	spec := goldenSpec(t)
	// No nic in the template: the engine must keep the e1000 fallback.
	spec.Machine = "pc-1.0"
	spec.Accel = "kvm"
	spec.Normalize()

	args := buildGoldenArgs(t, spec, true)
	joined := strings.Join(args, " ")
	for _, expected := range []string{
		"-machine pc-1.0",
		"-accel kvm",
		"-cpu host",
		"-device e1000,netdev=net0,mac=" + spec.MAC(0),
		"-drive file=/run/overlay.qcow2,if=virtio,format=qcow2,cache=none",
	} {
		if !strings.Contains(joined, expected) {
			t.Fatalf("expected %q in %s", expected, joined)
		}
	}
}

func TestBuildQEMUArgsForBareQEMUTemplate(t *testing.T) {
	spec := goldenSpec(t)
	spec.Normalize()

	args := buildGoldenArgs(t, spec, false)
	assertArgv(t, args, []string{
		"-machine", "pc",
		"-smp", "1",
		"-m", "512",
		"-name", "R1",
		"-uuid", spec.UUID,
		"-nodefaults",
		"-no-user-config",
		"-rtc", "base=utc",
		"-display", "none",
		"-no-reboot",
		"-monitor", "none",
		"-serial", "stdio",
		"-accel", "tcg",
		"-cpu", "max",
		"-device", "e1000,netdev=net0,mac=" + spec.MAC(0),
		"-netdev", "tap,id=net0,ifname=" + spec.TapName(0) + ",script=no,downscript=no",
		"-drive", "file=/run/overlay.qcow2,if=virtio,format=qcow2,cache=none",
		"-boot", "order=c",
	})
}

func TestBuildQEMUArgsRendersNICDeviceModel(t *testing.T) {
	models := map[string]string{
		"virtio":         "virtio-net-pci",
		"vmxnet3":        "vmxnet3",
		"e1000":          "e1000",
		"rtl8139":        "rtl8139",
		"virtio-net-pci": "virtio-net-pci",
		"i82559er":       "e1000",
	}
	for nic, device := range models {
		t.Run(nic, func(t *testing.T) {
			spec := goldenSpec(t)
			spec.NIC = nic
			spec.Normalize()

			args := buildGoldenArgs(t, spec, false)
			joined := strings.Join(args, " ")
			if !strings.Contains(joined, "-device "+device+",netdev=net0,mac=") {
				t.Fatalf("expected device %s in %s", device, joined)
			}
			if device != nic && strings.Contains(joined, "-device "+nic+",") {
				t.Fatalf("short nic name %s must not reach -device: %s", nic, joined)
			}
		})
	}
}

func TestSpecNormalisesLegacyNICAliases(t *testing.T) {
	cases := map[string]string{
		"virtio-net-pci": "virtio",
		"i82559er":       "e1000",
		"virtio":         "virtio",
		"vmxnet3":        "vmxnet3",
		"e1000":          "e1000",
		"rtl8139":        "rtl8139",
	}
	for nic, canonical := range cases {
		t.Run(nic, func(t *testing.T) {
			spec := Spec{ID: "node-1", Name: "R1", NIC: nic}
			spec.Normalize()
			if spec.NIC != canonical {
				t.Fatalf("normalised nic: got %q want %q", spec.NIC, canonical)
			}
			if err := spec.Validate(nil); err != nil {
				t.Fatalf("legacy nic %q must validate: %v", nic, err)
			}
		})
	}
}

func TestBuildQEMUArgsHonoursDiskInterface(t *testing.T) {
	spec := goldenSpec(t)
	spec.DiskInterface = "ide"
	spec.Normalize()

	joined := strings.Join(buildGoldenArgs(t, spec, false), " ")
	if !strings.Contains(joined, "-drive file=/run/overlay.qcow2,if=ide,format=qcow2,cache=none") {
		t.Fatalf("expected an ide drive in %s", joined)
	}
}

func TestBuildQEMUArgsEmitsRequestedAccelerators(t *testing.T) {
	cases := []struct {
		name      string
		accel     string
		cpu       string
		kvm       bool
		wantAccel string
		wantCPU   string
	}{
		{name: "kvm with kvm", accel: "kvm", kvm: true, wantAccel: "-accel kvm", wantCPU: "host"},
		{name: "kvm demoted without kvm", accel: "kvm", kvm: false, wantAccel: "-accel tcg", wantCPU: "max"},
		{
			name:      "kvm then tcg",
			accel:     "kvm:tcg",
			kvm:       true,
			wantAccel: "-accel kvm -accel tcg",
			wantCPU:   "host",
		},
		{
			name:      "kvm:tcg demoted to tcg",
			accel:     "kvm:tcg",
			cpu:       "host",
			kvm:       false,
			wantAccel: "-accel tcg",
			wantCPU:   "max",
		},
		{name: "explicit tcg", accel: "tcg", cpu: "Nehalem", kvm: true, wantAccel: "-accel tcg", wantCPU: "Nehalem"},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			spec := goldenSpec(t)
			spec.Accel = testCase.accel
			spec.CPUModel = testCase.cpu
			spec.Normalize()

			joined := strings.Join(buildGoldenArgs(t, spec, testCase.kvm), " ")
			if !strings.Contains(joined, testCase.wantAccel+" -cpu "+testCase.wantCPU) {
				t.Fatalf("expected %q with cpu %q in %s", testCase.wantAccel, testCase.wantCPU, joined)
			}
		})
	}
}

func TestBuildQEMUArgsKeepsAllowListedExtras(t *testing.T) {
	spec := goldenSpec(t)
	spec.ExtraOptions = `-no-acpi -no-hpet -smbios type=1,product="lab router"`
	spec.Normalize()

	args := buildGoldenArgs(t, spec, false)
	tail := args[len(args)-4:]
	assertArgv(t, tail, []string{"-no-acpi", "-no-hpet", "-smbios", "type=1,product=lab router"})
}

func TestBuildQEMUArgsRejectsUnsafeExtrasBeforeRendering(t *testing.T) {
	for _, raw := range []string{
		"-drive file=/etc/shadow",
		"-fsdev local,path=/",
		"-device vfio-pci,host=0000:00:01.0",
		"-nodefaults\n-no-acpi",
		"-icount 1; reboot",
		"-usbdevice host:1,2",
	} {
		t.Run(strings.ReplaceAll(raw, " ", "_"), func(t *testing.T) {
			spec := goldenSpec(t)
			spec.ExtraOptions = raw
			spec.Normalize()

			if _, err := BuildQEMUArgs(spec, []string{spec.TapName(0)}, "", nil, nil, false); err == nil {
				t.Fatalf("expected %q to be rejected", raw)
			}
		})
	}
}

func TestCommandLineQuotesValuesWithSpaces(t *testing.T) {
	line := commandLine("/usr/bin/qemu-system-x86_64", []string{"-smbios", "type=1,product=lab router"})
	if line != `/usr/bin/qemu-system-x86_64 -smbios "type=1,product=lab router"` {
		t.Fatalf("unexpected command line: %s", line)
	}
}

func TestManagerStartRejectsUnsafeExtraOptions(t *testing.T) {
	logger := slog.New(slog.NewTextHandler(io.Discard, nil))
	manager := NewManager(config.Config{RunDir: t.TempDir()}, logger)
	spec := Spec{
		ID:           "node-1",
		Name:         "R1",
		ExtraOptions: "-drive file=/etc/shadow",
	}

	_, err := manager.Start(spec)
	if err == nil || !strings.Contains(err.Error(), "not allowed") {
		t.Fatalf("expected the unsafe option to be rejected, got %v", err)
	}
	if info, statErr := os.Stat(filepath.Join(manager.cfg.RunDir, "node-1")); statErr == nil && info.IsDir() {
		t.Fatal("a rejected spec must not create a run directory")
	}
}
