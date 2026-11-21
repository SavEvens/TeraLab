// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"errors"
	"fmt"
	"strconv"
	"strings"
)

// VNCConfig describes the loopback VNC endpoint for a node.
type VNCConfig struct {
	Display     int
	MonitorPath string
}

// BuildQEMUArgs renders the QEMU command line. Structured parameters come from
// the spec and fall back only when empty; console, tap, kernel and boot policy
// stay engine-owned. disks holds the prepared extra disk paths, already in slot
// order (virtiob, virtioc, virtiod) by the manager.
func BuildQEMUArgs(spec Spec, taps []string, overlay string, disks []string, vnc *VNCConfig, kvm bool) ([]string, error) {
	if spec.ExtraOptions != "" {
		merged, err := spec.withExtraOptions()
		if err != nil {
			return nil, err
		}
		spec = merged
	}
	if len(taps) != spec.Ethernet {
		return nil, errors.New("tap count does not match ethernet count")
	}
	if spec.Console == "vnc" && vnc == nil {
		return nil, errors.New("vnc console requires a vnc configuration")
	}
	if spec.Console != "vnc" && vnc != nil {
		return nil, errors.New("vnc configuration supplied for a non-vnc console")
	}

	machine := spec.Machine
	if machine == "" {
		machine = defaultMachine
	}
	nic := canonicalNIC(spec.NIC)
	if nic == "" {
		nic = defaultNIC
	}
	nicDevice := nicDevices[nic]
	if nicDevice == "" {
		return nil, fmt.Errorf("unsupported nic driver %q", nic)
	}
	diskInterface := spec.DiskInterface
	if diskInterface == "" {
		diskInterface = defaultDiskInterface
	}

	args := []string{
		"-machine", machine,
		"-smp", strconv.Itoa(spec.CPU),
		"-m", strconv.Itoa(spec.RAM),
		"-name", spec.Name,
		"-uuid", spec.UUID,
		"-nodefaults",
		"-no-user-config",
		"-rtc", "base=utc",
		"-display", "none",
		"-no-reboot",
	}

	if vnc != nil {
		args = append(
			args,
			"-monitor", "unix:"+vnc.MonitorPath+",server=on,wait=off",
			"-serial", "none",
			"-vnc", "127.0.0.1:"+strconv.Itoa(vnc.Display)+",password=on",
		)
	} else {
		args = append(
			args,
			"-monitor", "none",
			"-serial", "stdio",
		)
	}
	for index := 0; index < spec.Serial; index++ {
		args = append(args, "-serial", "pty")
	}

	accelerators := effectiveAccels(spec.Accel, kvm)
	for _, accel := range accelerators {
		args = append(args, "-accel", accel)
	}
	args = append(args, "-cpu", effectiveCPUModel(spec.CPUModel, accelerators))

	for index, tap := range taps {
		netdev := "net" + strconv.Itoa(index)
		args = append(
			args,
			"-device", nicDevice+",netdev="+netdev+",mac="+spec.MAC(index),
			"-netdev", "tap,id="+netdev+",ifname="+tap+",script=no,downscript=no",
		)
	}

	if spec.Image != "" {
		args = append(args, "-drive", "file="+overlay+",if="+diskInterface+",format=qcow2,cache=none")
	}
	// Extra disks are always virtio-blk: their slots name virtio devices even
	// when the primary disk uses another interface. Their order is the slot
	// order supplied by the manager, not the template's list order.
	for _, disk := range disks {
		args = append(args, "-drive", "file="+disk+",if=virtio,format=qcow2,cache=none")
	}
	if spec.Kernel != "" {
		args = append(args, "-kernel", spec.Kernel)
		if spec.Initrd != "" {
			args = append(args, "-initrd", spec.Initrd)
		}
		if spec.Append != "" {
			args = append(args, "-append", spec.Append)
		}
	}
	if spec.ISO != "" {
		args = append(args, "-cdrom", spec.ISO)
		if spec.Kernel == "" {
			args = append(args, "-boot", "order=d")
		}
	} else if spec.Image != "" && spec.Kernel == "" {
		args = append(args, "-boot", "order=c")
	}
	args = append(args, spec.Extra...)

	return args, nil
}

// effectiveAccels resolves the accelerator list. An explicit request is
// honoured, except that KVM is demoted to TCG when /dev/kvm is unavailable:
// templates ship "accel=kvm" as a preference, and the engine has always
// started such nodes with TCG rather than refusing to run them.
func effectiveAccels(accel string, kvm bool) []string {
	switch accel {
	case "kvm":
		if kvm {
			return []string{"kvm"}
		}

		return []string{"tcg"}
	case "kvm:tcg":
		if kvm {
			return []string{"kvm", "tcg"}
		}

		return []string{"tcg"}
	case "tcg":
		return []string{"tcg"}
	}
	if kvm {
		return []string{"kvm"}
	}

	return []string{"tcg"}
}

// effectiveCPUModel resolves the CPU model, keeping it compatible with the
// primary accelerator: "host" only exists under KVM, and TCG has no host CPU.
func effectiveCPUModel(model string, accelerators []string) string {
	if model == "" {
		if accelerators[0] == "kvm" {
			return "host"
		}

		return "max"
	}
	if model == "host" && accelerators[0] != "kvm" {
		return "max"
	}

	return model
}

// commandLine renders an argv for a single audit log line, quoting tokens that
// contain spaces or shell punctuation.
func commandLine(binary string, args []string) string {
	parts := make([]string, 0, len(args)+1)
	parts = append(parts, binary)
	for _, arg := range args {
		if arg == "" || strings.ContainsAny(arg, " \t\n\"'\\") {
			parts = append(parts, strconv.Quote(arg))
			continue
		}
		parts = append(parts, arg)
	}

	return strings.Join(parts, " ")
}
