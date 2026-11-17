// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package system

import (
	"context"
	"os"
	"runtime"
	"time"

	"github.com/SavEvens/TeraLab/agent/internal/config"
	"github.com/SavEvens/TeraLab/agent/internal/engine"
)

type Status struct {
	Version     string            `json:"version"`
	QEMUVersion string            `json:"qemu_version"`
	Hostname    string            `json:"hostname"`
	OS          string            `json:"os"`
	Arch        string            `json:"arch"`
	CPUs        int               `json:"cpus"`
	Running     int               `json:"running"`
	Nodes       []engine.NodeInfo `json:"nodes"`
	Time        time.Time         `json:"time"`
}

func Collect(ctx context.Context, cfg config.Config, manager *engine.Manager) Status {
	hostname, _ := os.Hostname()

	return Status{
		Version:     config.Version,
		QEMUVersion: manager.QEMUVersion(ctx),
		Hostname:    hostname,
		OS:          runtime.GOOS,
		Arch:        runtime.GOARCH,
		CPUs:        runtime.NumCPU(),
		Running:     manager.Count(),
		Nodes:       manager.List(),
		Time:        time.Now().UTC(),
	}
}
