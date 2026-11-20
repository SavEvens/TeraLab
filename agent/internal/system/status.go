// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package system

import (
	"context"
	"io/fs"
	"math"
	"net"
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
	CPUUsage    float64           `json:"cpu_usage_percent"`
	Running     int               `json:"running"`
	Nodes       []engine.NodeInfo `json:"nodes"`
	Engines     []EngineStatus    `json:"engines"`
	Interfaces  []InterfaceInfo   `json:"interfaces"`
	Load1       float64           `json:"load1"`
	Load5       float64           `json:"load5"`
	Load15      float64           `json:"load15"`
	DiskFree    uint64            `json:"disk_free_bytes"`
	DiskTotal   uint64            `json:"disk_total_bytes"`
	Time        time.Time         `json:"time"`
}

func Collect(ctx context.Context, cfg config.Config, manager *engine.Manager) Status {
	hostname, _ := os.Hostname()

	usage := cpuUsage(ctx, procStatPath)
	load1, load5, load15 := loadAverage()
	free, total := diskUsage(cfg.ImagesDir)

	return Status{
		Version:     config.Version,
		QEMUVersion: manager.QEMUVersion(ctx),
		Hostname:    hostname,
		OS:          runtime.GOOS,
		Arch:        runtime.GOARCH,
		CPUs:        runtime.NumCPU(),
		CPUUsage:    usage,
		Running:     manager.Count(),
		Nodes:       manager.List(),
		Engines:     Engines(ctx, cfg, manager),
		Interfaces:  interfaces(),
		Load1:       load1,
		Load5:       load5,
		Load15:      load15,
		DiskFree:    free,
		DiskTotal:   total,
		Time:        time.Now().UTC(),
	}
}

// EngineStatus reports whether an engine binary or image is available.
type InterfaceInfo struct {
	Name      string   `json:"name"`
	MAC       string   `json:"mac,omitempty"`
	MTU       int      `json:"mtu"`
	Up        bool     `json:"up"`
	Addresses []string `json:"addresses,omitempty"`
}

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

const (
	procStatPath      = "/proc/stat"
	cpuSampleInterval = 250 * time.Millisecond
)

// cpuTimes is the aggregate CPU time from /proc/stat, in clock ticks.
type cpuTimes struct {
	idle  uint64
	total uint64
}

// parseProcStat extracts the aggregate "cpu" line from /proc/stat contents.
// The first eight value columns (user through steal) make up the total, while
// idle and iowait make up the idle time; guest and guest_nice are already
// counted in user and nice, so they are ignored.
func parseProcStat(data []byte) (cpuTimes, bool) {
	for _, line := range strings.Split(string(data), "\n") {
		fields := strings.Fields(line)
		if len(fields) == 0 || fields[0] != "cpu" {
			continue
		}
		if len(fields) < 5 {
			return cpuTimes{}, false
		}
		values := fields[1:]
		if len(values) > 8 {
			values = values[:8]
		}
		var times cpuTimes
		for index, value := range values {
			parsed, err := strconv.ParseUint(value, 10, 64)
			if err != nil {
				return cpuTimes{}, false
			}
			times.total += parsed
			if index == 3 || index == 4 { // idle, iowait
				times.idle += parsed
			}
		}
		return times, true
	}

	return cpuTimes{}, false
}

// cpuUsageFromSamples returns the busy share of two /proc/stat samples as a
// percentage rounded to one decimal place. Inconsistent samples yield 0.
func cpuUsageFromSamples(first, second cpuTimes) float64 {
	if second.total <= first.total || second.idle < first.idle {
		return 0
	}
	totalDelta := second.total - first.total
	idleDelta := second.idle - first.idle
	if idleDelta > totalDelta {
		idleDelta = totalDelta
	}
	usage := float64(totalDelta-idleDelta) / float64(totalDelta) * 100
	if usage > 100 {
		usage = 100
	}

	return math.Round(usage*10) / 10
}

func readCPUTimes(path string) (cpuTimes, bool) {
	data, err := os.ReadFile(path)
	if err != nil {
		return cpuTimes{}, false
	}

	return parseProcStat(data)
}

// cpuUsage samples the CPU counters twice, cpuSampleInterval apart, and
// reports the measured busy percentage. The wait is bounded by ctx: when the
// deadline arrives first the measurement is abandoned and 0 is returned, as it
// is when /proc/stat is absent (non-Linux) or unreadable.
func cpuUsage(ctx context.Context, path string) float64 {
	first, ok := readCPUTimes(path)
	if !ok {
		return 0
	}

	timer := time.NewTimer(cpuSampleInterval)
	defer timer.Stop()

	select {
	case <-ctx.Done():
		return 0
	case <-timer.C:
	}

	second, ok := readCPUTimes(path)
	if !ok {
		return 0
	}

	return cpuUsageFromSamples(first, second)
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

func interfaces() []InterfaceInfo {
	links, err := net.Interfaces()
	if err != nil {
		return []InterfaceInfo{}
	}
	result := make([]InterfaceInfo, 0, len(links))
	for _, link := range links {
		info := InterfaceInfo{
			Name: link.Name,
			MAC:  link.HardwareAddr.String(),
			MTU:  link.MTU,
			Up:   link.Flags&net.FlagUp != 0,
		}
		if addrs, err := link.Addrs(); err == nil {
			for _, addr := range addrs {
				info.Addresses = append(info.Addresses, addr.String())
			}
		}
		result = append(result, info)
	}

	return result
}
