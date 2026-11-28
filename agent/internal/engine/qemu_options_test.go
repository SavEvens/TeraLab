// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/SavEvens/TeraLab/agent/internal/network"
)

func mergeRaw(t *testing.T, spec Spec, raw string) (Spec, []string, error) {
	t.Helper()
	tokens, err := SplitQEMUOptions(raw)
	if err != nil {
		return spec, nil, err
	}

	return MergeQEMUOptions(spec, tokens)
}

func TestSplitQEMUOptions(t *testing.T) {
	cases := []struct {
		name    string
		raw     string
		want    []string
		wantErr string
	}{
		{name: "simple", raw: "-no-acpi  -no-hpet", want: []string{"-no-acpi", "-no-hpet"}},
		{
			name: "double quotes keep spaces",
			raw:  `-smbios type=1,manufacturer="VMware Inc.",product="VMware Virtual Platform"`,
			want: []string{"-smbios", "type=1,manufacturer=VMware Inc.,product=VMware Virtual Platform"},
		},
		{name: "single quotes", raw: `-append 'console=ttyS0 quiet'`, want: []string{"-append", "console=ttyS0 quiet"}},
		{name: "escaped space", raw: `-smbios type=1,product=lab\ router`, want: []string{"-smbios", "type=1,product=lab router"}},
		{name: "empty", raw: "", want: nil},
		{name: "newline injection", raw: "-no-acpi\n-drive file=/etc/shadow", wantErr: "control characters"},
		{name: "tab injection", raw: "-no-acpi\t-no-hpet", wantErr: "control characters"},
		{name: "unterminated quote", raw: `-smbios type=1,product="lab`, wantErr: "unterminated quote"},
		{name: "empty argument", raw: `-smbios ""`, wantErr: "empty argument"},
		{name: "trailing backslash", raw: `-no-acpi \`, wantErr: "backslash"},
		{name: "over-long", raw: strings.Repeat("-no-acpi ", 120), wantErr: "at most"},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			tokens, err := SplitQEMUOptions(testCase.raw)
			if testCase.wantErr != "" {
				if err == nil || !strings.Contains(err.Error(), testCase.wantErr) {
					t.Fatalf("expected error containing %q, got %v", testCase.wantErr, err)
				}

				return
			}
			if err != nil {
				t.Fatalf("split: %v", err)
			}
			if !reflect.DeepEqual(tokens, testCase.want) {
				t.Fatalf("tokens: got %q want %q", tokens, testCase.want)
			}
		})
	}
}

func TestMergeQEMUOptionsFillsEmptyFields(t *testing.T) {
	raw := "-machine type=q35,accel=kvm -cpu qemu64,+ssse3 -accel tcg " +
		"-nic model=rtl8139 -drive if=sata"
	merged, extras, err := mergeRaw(t, Spec{}, raw)
	if err != nil {
		t.Fatalf("merge: %v", err)
	}
	if merged.Machine != "q35" {
		t.Fatalf("machine: %q", merged.Machine)
	}
	if merged.Accel != "tcg" {
		t.Fatalf("an explicit -accel flag must win over -machine accel: %q", merged.Accel)
	}
	if merged.CPUModel != "qemu64,+ssse3" {
		t.Fatalf("cpu: %q", merged.CPUModel)
	}
	if merged.NIC != "rtl8139" {
		t.Fatalf("nic: %q", merged.NIC)
	}
	if merged.DiskInterface != "sata" {
		t.Fatalf("disk interface: %q", merged.DiskInterface)
	}
	if len(extras) != 0 {
		t.Fatalf("unexpected extras: %q", extras)
	}
	if merged.ExtraOptions != "" {
		t.Fatal("merged spec must clear the raw options")
	}
}

func TestMergeQEMUOptionsMapsLegacyNICOption(t *testing.T) {
	cases := map[string]string{
		"-net nic,model=vmxnet3":    "vmxnet3",
		"-nic model=virtio-net-pci": "virtio",
		"-nic model=i82559er":       "e1000",
	}
	for raw, canonical := range cases {
		t.Run(raw, func(t *testing.T) {
			merged, _, err := mergeRaw(t, Spec{}, raw)
			if err != nil {
				t.Fatalf("merge: %v", err)
			}
			if merged.NIC != canonical {
				t.Fatalf("nic: got %q want %q", merged.NIC, canonical)
			}
		})
	}
}

func TestMergeQEMUOptionsKeepsResolvedFields(t *testing.T) {
	spec := Spec{
		Machine:       "q35",
		NIC:           "e1000",
		CPUModel:      "max",
		Accel:         "tcg",
		DiskInterface: "virtio",
	}
	raw := "-machine type=pc,accel=kvm -cpu host -accel kvm -nic model=vmxnet3 -drive if=ide"
	merged, _, err := mergeRaw(t, spec, raw)
	if err != nil {
		t.Fatalf("merge: %v", err)
	}
	if merged.Machine != "q35" || merged.NIC != "e1000" || merged.CPUModel != "max" ||
		merged.Accel != "tcg" || merged.DiskInterface != "virtio" {
		t.Fatalf("resolved fields must not be overwritten: %+v", merged)
	}
}

func TestMergeQEMUOptionsDropsEngineOwnedFlags(t *testing.T) {
	spec := Spec{ID: "node-1", Image: "disk.qcow2", Kernel: "", ISO: ""}
	raw := "-smp 8 -m 4096 -name evil -uuid 00000000-0000-4000-8000-000000000000 " +
		"-rtc base=utc -display none -nographic -no-reboot -serial mon:stdio -monitor none " +
		"-kernel /etc/shadow -initrd /etc/passwd -append console=ttyS0 -cdrom /tmp/x.iso " +
		"-netdev tap,id=evil,ifname=evil0 -boot order=dc -vga std -nodefconfig"
	merged, extras, err := mergeRaw(t, spec, raw)
	if err != nil {
		t.Fatalf("merge: %v", err)
	}
	if len(extras) != 0 {
		t.Fatalf("engine-owned flags must not become extras: %q", extras)
	}
	if merged.Machine != "" || merged.CPUModel != "" || merged.Accel != "" || merged.NIC != "" {
		t.Fatalf("engine-owned flags must not fill structured fields: %+v", merged)
	}
}

func TestMergeQEMUOptionsKeepsBootOrderWhenTheEngineDoesNotChoose(t *testing.T) {
	merged, extras, err := mergeRaw(t, Spec{ID: "node-1"}, "-boot order=dc")
	if err != nil {
		t.Fatalf("merge: %v", err)
	}
	if len(extras) != 2 || extras[0] != "-boot" || extras[1] != "order=dc" {
		t.Fatalf("unexpected extras: %q", extras)
	}
	if !reflect.DeepEqual(merged.Extra, extras) {
		t.Fatalf("merged spec must carry the returned extras: %+v", merged.Extra)
	}
}

func TestMergeQEMUOptionsRejectsDangerousArguments(t *testing.T) {
	cases := []struct {
		name    string
		raw     string
		wantErr string
	}{
		{name: "drive file", raw: "-drive file=/etc/shadow", wantErr: "not allowed"},
		{name: "fsdev path", raw: "-fsdev local,path=/", wantErr: "not allowed"},
		{name: "virtfs path", raw: "-virtfs local,path=/,mount_tag=host0", wantErr: "not allowed"},
		{name: "chardev file", raw: "-chardev file,id=x,path=/etc/shadow", wantErr: "not allowed"},
		{name: "device host dev", raw: "-device vfio-pci,host=0000:00:01.0", wantErr: "not allowed"},
		{name: "netdev socket", raw: "-netdev socket,id=x", wantErr: "tap netdev"},
		{name: "sandbox", raw: "-sandbox off", wantErr: "not allowed"},
		{name: "usb host device", raw: "-usbdevice host:1,2", wantErr: "only tablet"},
		{name: "global host", raw: "-global vfio-pci.host=0000:00:01.0", wantErr: "files, hosts or sockets"},
		{name: "smbios path", raw: "-smbios type=1,path=/etc/passwd", wantErr: "files, hosts or sockets"},
		{name: "unknown flag", raw: "-enable-kvm", wantErr: "not allowed"},
		{name: "flag without value", raw: "-no-acpi -drive", wantErr: "requires a value"},
		{name: "machine injection", raw: "-machine type=pc;reboot", wantErr: "invalid machine type"},
		{name: "cpu injection", raw: "-cpu host;reboot", wantErr: "invalid cpu model"},
		{name: "accel injection", raw: "-accel hax", wantErr: "invalid accelerator"},
		{name: "nic injection", raw: "-nic model=host", wantErr: "unsupported nic model"},
		{name: "empty argument", raw: `-no-acpi ""`, wantErr: "empty argument"},
		{
			name:    "too many extras",
			raw:     strings.TrimSpace(strings.Repeat("-no-acpi ", 17)),
			wantErr: "at most",
		},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			spec := Spec{ID: "node-1", Image: "disk.qcow2"}
			_, _, err := mergeRaw(t, spec, testCase.raw)
			if err == nil || !strings.Contains(err.Error(), testCase.wantErr) {
				t.Fatalf("expected error containing %q, got %v", testCase.wantErr, err)
			}
		})
	}
}

func TestMergeQEMUOptionsRejectsBootOverrideWhenTheEngineChooses(t *testing.T) {
	_, _, err := mergeRaw(t, Spec{ID: "node-1", Image: "disk.qcow2"}, "-boot order=dc")
	if err != nil {
		t.Fatalf("engine-owned boot must be dropped, not rejected: %v", err)
	}
	// An explicit Extra list cannot smuggle a boot override past validation.
	spec := Spec{ID: "node-1", Name: "R1", Image: "disk.qcow2", Extra: []string{"-boot", "order=dc"}}
	spec.Normalize()
	if err := spec.Validate(nil); err == nil || !strings.Contains(err.Error(), "boot order") {
		t.Fatalf("expected an override error, got %v", err)
	}
}

// TestMergeQEMUOptionsAcceptsShippedTokenInventory pins every token that the
// built-in templates carry in their legacy qemu_options. A new token must be
// classified here (mapped, dropped or allow-listed) before templates use it.
func TestMergeQEMUOptionsAcceptsShippedTokenInventory(t *testing.T) {
	raw := `-machine type=pc,accel=kvm -machine type=pc-1.0,accel=kvm ` +
		`-machine type=pc,accel=kvm,usb=off -machine type=pc-1.0,accel=tcg ` +
		`-machine pc,accel=kvm -machine type=pc-1.0 ` +
		`-cpu host -cpu Nehalem -cpu qemu64,+ssse3,+sse4.1,+sse4.2,+x2apic,+aes,pclmulqdq ` +
		`-display none -global kvm-pit.lost_tick_policy=discard -icount 1 ` +
		`-no-hpet -no-shutdown -no-user-config -nodefaults -nodefconfig -nographic ` +
		`-realtime mlock=off -rtc base=utc -rtc base=utc,driftfix=slew ` +
		`-serial mon:stdio -serial none -smbios type=1,product=asa5520 ` +
		`-smbios type=1,product=IPS-4240/4255,version=1.0,family=IPS-4240/4255 ` +
		`-smbios type=1,manufacturer="VMware Inc.",product="VMware Virtual Platform" ` +
		`-usbdevice tablet -vga std -boot order=dc -boot order=c -boot order=d`

	spec := Spec{ID: "node-1", Image: "disk.qcow2"}
	merged, extras, err := mergeRaw(t, spec, raw)
	if err != nil {
		t.Fatalf("shipped templates must start: %v", err)
	}
	want := []string{
		"-global", "kvm-pit.lost_tick_policy=discard",
		"-icount", "1",
		"-no-hpet",
		"-no-shutdown",
		"-realtime", "mlock=off",
		"-smbios", "type=1,product=asa5520",
		"-smbios", "type=1,product=IPS-4240/4255,version=1.0,family=IPS-4240/4255",
		"-smbios", "type=1,manufacturer=VMware Inc.,product=VMware Virtual Platform",
		"-usbdevice", "tablet",
	}
	if !reflect.DeepEqual(extras, want) {
		t.Fatalf("extras:\n got: %q\nwant: %q", extras, want)
	}
	if merged.Machine != "pc-1.0" || merged.CPUModel != "qemu64,+ssse3,+sse4.1,+sse4.2,+x2apic,+aes,pclmulqdq" {
		t.Fatalf("structured fields not filled: %+v", merged)
	}
}

// TestShippedTemplateQEMUOptionsAreAccepted runs the real options of every
// built-in template through the parser. It is skipped when the agent is tested
// outside the full repository.
func TestShippedTemplateQEMUOptionsAreAccepted(t *testing.T) {
	files, err := filepath.Glob(filepath.Join("..", "..", "..", "api", "templates", "builtin", "*.yaml"))
	if err != nil {
		t.Fatal(err)
	}
	if len(files) == 0 {
		t.Skip("api/templates/builtin is not present")
	}
	checked := 0
	for _, file := range files {
		raw, ok := templateQEMUOptions(file)
		if !ok {
			continue
		}
		spec := Spec{ID: "node-1", Name: "R1", Image: "disk.qcow2"}
		if _, _, err := mergeRaw(t, spec, raw); err != nil {
			t.Errorf("%s: %v", filepath.Base(file), err)
			continue
		}
		checked++
	}
	if checked == 0 {
		t.Skip("no shipped qemu_options found")
	}
	t.Logf("checked %d built-in templates", checked)
}

// templateQEMUOptions extracts the single-line qemu_options value from a
// built-in template without pulling a YAML parser into the agent tests.
func templateQEMUOptions(path string) (string, bool) {
	data, err := os.ReadFile(path)
	if err != nil {
		return "", false
	}
	for _, line := range strings.Split(string(data), "\n") {
		trimmed := strings.TrimSpace(line)
		if !strings.HasPrefix(trimmed, "qemu_options:") {
			continue
		}
		value := strings.TrimSpace(strings.TrimPrefix(trimmed, "qemu_options:"))
		if len(value) >= 2 && value[0] == '\'' && value[len(value)-1] == '\'' {
			value = value[1 : len(value)-1]
			value = strings.ReplaceAll(value, "''", "'")
		}

		return value, true
	}

	return "", false
}

func TestSpecRejectsInvalidQEMUParameters(t *testing.T) {
	cases := []struct {
		name    string
		mutate  func(*Spec)
		wantErr string
	}{
		{name: "architecture", mutate: func(s *Spec) { s.Arch = "aarch64" }, wantErr: "unsupported architecture"},
		{name: "nic", mutate: func(s *Spec) { s.NIC = "virtio-net-pci; rm" }, wantErr: "invalid nic driver"},
		{name: "unknown nic", mutate: func(s *Spec) { s.NIC = "ne2k_pci" }, wantErr: "invalid nic driver"},
		{name: "machine", mutate: func(s *Spec) { s.Machine = "pc type=evil" }, wantErr: "invalid machine type"},
		{name: "cpu", mutate: func(s *Spec) { s.CPUModel = "host; rm" }, wantErr: "invalid cpu model"},
		{name: "accel", mutate: func(s *Spec) { s.Accel = "hax" }, wantErr: "invalid accelerator"},
		{name: "disk", mutate: func(s *Spec) { s.DiskInterface = "nvme" }, wantErr: "invalid disk interface"},
		{
			name:    "extra options too long",
			mutate:  func(s *Spec) { s.ExtraOptions = strings.Repeat("-no-acpi ", 120) },
			wantErr: "invalid extra qemu options",
		},
		{
			name:    "extra options control chars",
			mutate:  func(s *Spec) { s.ExtraOptions = "-no-acpi\n-no-hpet" },
			wantErr: "invalid extra qemu options",
		},
		{
			name:    "explicit extras are allow-listed",
			mutate:  func(s *Spec) { s.Extra = []string{"-drive", "file=/etc/shadow"} },
			wantErr: "not allowed",
		},
	}
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			spec := Spec{ID: "node-1", Name: "R1"}
			spec.Normalize()
			testCase.mutate(&spec)
			err := spec.Validate(nil)
			if err == nil || !strings.Contains(err.Error(), testCase.wantErr) {
				t.Fatalf("expected error containing %q, got %v", testCase.wantErr, err)
			}
		})
	}
}

func TestSpecAcceptsEmptyQEMUParameters(t *testing.T) {
	spec := Spec{ID: "node-1", Name: "R1"}
	spec.Normalize()
	if spec.Arch != "x86_64" || spec.Machine != "pc" || spec.NIC != "e1000" || spec.DiskInterface != "virtio" {
		t.Fatalf("unexpected fallbacks: %+v", spec)
	}
	if err := spec.Validate(nil); err != nil {
		t.Fatalf("empty structured parameters must be valid: %v", err)
	}
}

func TestNonQEMUEnginesRejectQEMUOptions(t *testing.T) {
	spec := Spec{ID: "pc-1", Name: "PC1", Engine: "vpcs", Ethernet: 1, Networks: []string{network.BridgeName("", "0")}}
	spec.Normalize()
	spec.ExtraOptions = "-no-acpi"
	if err := spec.Validate(nil); err == nil || !strings.Contains(err.Error(), "only supported for qemu") {
		t.Fatalf("expected qemu options to be rejected, got %v", err)
	}
}
