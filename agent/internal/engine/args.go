// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"errors"
	"strconv"
)

// VNCConfig describes the loopback VNC endpoint for a node.
type VNCConfig struct {
	Display     int
	MonitorPath string
}

func BuildQEMUArgs(spec Spec, taps []string, overlay string, vnc *VNCConfig, kvm bool) ([]string, error) {
	if len(taps) != spec.Ethernet {
		return nil, errors.New("tap count does not match ethernet count")
	}
	if spec.Console == "vnc" && vnc == nil {
		return nil, errors.New("vnc console requires a vnc configuration")
	}
	if spec.Console != "vnc" && vnc != nil {
		return nil, errors.New("vnc configuration supplied for a non-vnc console")
	}

	args := []string{
		"-machine", spec.Machine,
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

	if kvm {
		args = append(args, "-accel", "kvm", "-cpu", "host")
	} else {
		args = append(args, "-accel", "tcg", "-cpu", "max")
	}

	for index, tap := range taps {
		netdev := "net" + strconv.Itoa(index)
		args = append(
			args,
			"-device", spec.NIC+",netdev="+netdev+",mac="+spec.MAC(index),
			"-netdev", "tap,id="+netdev+",ifname="+tap+",script=no,downscript=no",
		)
	}

	if spec.Image != "" {
		args = append(args, "-drive", "file="+overlay+",if=virtio,format=qcow2,cache=none")
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

	return args, nil
}
