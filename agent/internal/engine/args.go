// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"errors"
	"os"
	"strconv"
)

func BuildQEMUArgs(spec Spec, taps []string, overlay string) ([]string, error) {
	if len(taps) != spec.Ethernet {
		return nil, errors.New("tap count does not match ethernet count")
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
		"-monitor", "none",
		"-serial", "stdio",
		"-no-reboot",
	}

	if _, err := os.Stat("/dev/kvm"); err == nil {
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
