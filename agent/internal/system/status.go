// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package system

import (
	"context"
	"io/fs"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"syscall"
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
	Engines     []EngineStatus    `json:"engines"`
	Load1       float64           `json:"load1"`
	Load5       float64           `json:"load5"`
	Load15      float64           `json:"load15"`
	DiskFree    uint64            `json:"disk_free_bytes"`
	DiskTotal   uint64            `json:"disk_total_bytes"`
	Time        time.Time         `json:"time"`
}

func Collect(ctx context.Context, cfg config.Config, manager *engine.Manager) Status {
	hostname, _ := os.Hostname()

	load1, load5, load15 := loadAverage()
	free, total := diskUsage(cfg.ImagesDir)

	return Status{
		Version:     config.Version,
		QEMUVersion: manager.QEMUVersion(ctx),
		Hostname:    hostname,
		OS:          runtime.GOOS,
		Arch:        runtime.GOARCH,
		CPUs:        runtime.NumCPU(),
		Running:     manager.Count(),
		Nodes:       manager.List(),
		Engines:     Engines(ctx, cfg, manager),
		Load1:       load1,
		Load5:       load5,
		Load15:      load15,
		DiskFree:    free,
		DiskTotal:   total,
		Time:        time.Now().UTC(),
	}
}

// EngineStatus reports whether an engine binary or image is available.
type EngineStatus struct {
	Name      string `json:"name"`
	Binary    string `json:"binary,omitempty"`
	Available bool   `json:"available"`
	Version   string `json:"version,omitempty"`
}

func Engines(ctx context.Context, cfg config.Config, manager *engine.Manager) []EngineStatus {
	statuses := []EngineStatus{
		{
			Name:      "qemu",
			Binary:    cfg.QEMUBinary,
			Available: fileExists(cfg.QEMUBinary),
			Version:   manager.QEMUVersion(ctx),
		},
		{
			Name:      "vpcs",
			Binary:    cfg.VPCSBinary,
			Available: fileExists(cfg.VPCSBinary),
			Version:   manager.VPCSVersion(ctx),
		},
		{
			Name:      "dynamips",
			Binary:    cfg.DynamipsBinary,
			Available: fileExists(cfg.DynamipsBinary),
			Version:   manager.DynamipsVersion(ctx),
		},
		{
			Name:      "iol",
			Binary:    "",
			Available: hasExecutableImage(cfg.ImagesDir),
		},
	}

	return statuses
}

func fileExists(path string) bool {
	info, err := os.Stat(path)

	return err == nil && !info.IsDir()
}

func hasExecutableImage(root string) bool {
	found := false
	_ = filepath.WalkDir(root, func(path string, entry fs.DirEntry, err error) error {
		if err != nil || found {
			return nil
		}
		if entry.IsDir() || !strings.HasSuffix(strings.ToLower(path), ".bin") {
			return nil
		}
		info, statErr := entry.Info()
		if statErr == nil && info.Mode()&0o111 != 0 {
			found = true
		}
		return nil
	})

	return found
}

func loadAverage() (float64, float64, float64) {
	data, err := os.ReadFile("/proc/loadavg")
	if err != nil {
		return 0, 0, 0
	}
	fields := strings.Fields(string(data))
	if len(fields) < 3 {
		return 0, 0, 0
	}
	first, _ := strconv.ParseFloat(fields[0], 64)
	second, _ := strconv.ParseFloat(fields[1], 64)
	third, _ := strconv.ParseFloat(fields[2], 64)

	return first, second, third
}

func diskUsage(path string) (uint64, uint64) {
	var stat syscall.Statfs_t
	if err := syscall.Statfs(path, &stat); err != nil {
		return 0, 0
	}
	blockSize := uint64(stat.Bsize)

	return stat.Bavail * blockSize, stat.Blocks * blockSize
}
