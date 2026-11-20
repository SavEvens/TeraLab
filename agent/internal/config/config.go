// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package config

import (
	"errors"
	"fmt"
	"os"
	"os/user"
	"path/filepath"
	"strconv"
	"strings"
)

const Version = "0.1.0"

type Config struct {
	SocketPath      string
	SocketGID       int
	SocketMode      os.FileMode
	ConsoleAddr     string
	Secret          string
	AllowedUIDs     map[uint32]bool
	RunDir          string
	ImagesDir       string
	ImageExtraRoots []string
	CgroupRoot      string
	DisableCgroups  bool
	QEMUBinary      string
	QEMUImg         string
	VPCSBinary      string
	DynamipsBinary  string
	IOLIourc        string
	NetworkBackend  string
	VMUser          string
	VMUID           uint32
	VMGID           uint32
	VMGroups        []uint32
	AllowRootVM     bool
	DisableKVM      bool
}

func Load() (Config, error) {
	cfg := Config{
		SocketPath:     env("TERALAB_AGENT_SOCKET", "/run/teralab/agent.sock"),
		SocketMode:     os.FileMode(0o660),
		ConsoleAddr:    env("TERALAB_AGENT_CONSOLE", "127.0.0.1:8091"),
		RunDir:         env("TERALAB_RUN_DIR", "/var/lib/teralab/run"),
		ImagesDir:      env("TERALAB_IMAGES_DIR", "/var/lib/teralab/images"),
		CgroupRoot:     env("TERALAB_CGROUP_ROOT", "/sys/fs/cgroup/teralab.slice"),
		DisableCgroups: envBool("TERALAB_DISABLE_CGROUPS", false),
		QEMUBinary:     env("TERALAB_QEMU", "/usr/bin/qemu-system-x86_64"),
		QEMUImg:        env("TERALAB_QEMU_IMG", "/usr/bin/qemu-img"),
		VPCSBinary:     env("TERALAB_VPCS", "/usr/bin/vpcs"),
		DynamipsBinary: env("TERALAB_DYNAMIPS", "/usr/bin/dynamips"),
		IOLIourc:       env("TERALAB_IOL_IOURC", "/etc/teralab/iourc"),
		NetworkBackend: env("TERALAB_NETWORK_BACKEND", "auto"),
		VMUser:         env("TERALAB_VM_USER", "teralab-vm"),
		AllowRootVM:    envBool("TERALAB_ALLOW_ROOT_VM", false),
		DisableKVM:     envBool("TERALAB_DISABLE_KVM", false),
	}

	roots, err := imageExtraRoots()
	if err != nil {
		return cfg, err
	}
	cfg.ImageExtraRoots = roots

	secret := os.Getenv("TERALAB_AGENT_SECRET")
	if secret == "" {
		path := env("TERALAB_AGENT_SECRET_FILE", "/etc/teralab/agent.secret")
		data, err := os.ReadFile(path)
		if err != nil {
			return cfg, fmt.Errorf("read agent secret: %w", err)
		}
		secret = strings.TrimSpace(string(data))
	}
	if len(secret) < 32 {
		return cfg, errors.New("agent secret must be at least 32 characters")
	}
	cfg.Secret = secret

	cfg.AllowedUIDs = map[uint32]bool{}
	for _, raw := range strings.Split(env("TERALAB_AGENT_ALLOWED_UIDS", "33"), ",") {
		raw = strings.TrimSpace(raw)
		if raw == "" {
			continue
		}
		uid, err := strconv.ParseUint(raw, 10, 32)
		if err != nil {
			return cfg, fmt.Errorf("invalid allowed UID %q", raw)
		}
		cfg.AllowedUIDs[uint32(uid)] = true
	}
	if len(cfg.AllowedUIDs) == 0 {
		return cfg, errors.New("no allowed agent UIDs configured")
	}

	cfg.SocketGID = lookupGroupGID(env("TERALAB_AGENT_SOCKET_GROUP", "www-data"))
	setVMUser(&cfg)
	return cfg, nil
}

// ImageRoots returns the primary image store followed by every approved extra
// root. Extra roots widen acceptance only; nothing is scanned or enumerated.
func (c Config) ImageRoots() []string {
	roots := make([]string, 0, 1+len(c.ImageExtraRoots))
	roots = append(roots, c.ImagesDir)

	return append(roots, c.ImageExtraRoots...)
}

// imageExtraRoots parses TERALAB_IMAGE_EXTRA_ROOTS (colon-separated, like PATH).
// Every configured root must be absolute; relative entries are rejected rather
// than silently resolved against the process working directory.
func imageExtraRoots() ([]string, error) {
	raw := os.Getenv("TERALAB_IMAGE_EXTRA_ROOTS")
	if raw == "" {
		return nil, nil
	}
	var roots []string
	for _, entry := range strings.Split(raw, ":") {
		entry = strings.TrimSpace(entry)
		if entry == "" {
			continue
		}
		if !filepath.IsAbs(entry) {
			return nil, fmt.Errorf("image extra root %q must be an absolute path", entry)
		}
		roots = append(roots, filepath.Clean(entry))
	}

	return roots, nil
}

func setVMUser(cfg *Config) {
	account, err := user.Lookup(cfg.VMUser)
	if err != nil {
		return
	}
	uid, err := strconv.ParseUint(account.Uid, 10, 32)
	if err != nil {
		return
	}
	gid, err := strconv.ParseUint(account.Gid, 10, 32)
	if err != nil {
		return
	}
	cfg.VMUID = uint32(uid)
	cfg.VMGID = uint32(gid)
	if group, groupErr := user.LookupGroup("kvm"); groupErr == nil {
		if kvmGID, parseErr := strconv.ParseUint(group.Gid, 10, 32); parseErr == nil {
			cfg.VMGroups = append(cfg.VMGroups, uint32(kvmGID))
		}
	}
}

func lookupGroupGID(name string) int {
	group, err := user.LookupGroup(name)
	if err != nil {
		return -1
	}
	gid, err := strconv.Atoi(group.Gid)
	if err != nil {
		return -1
	}
	return gid
}

func env(key, fallback string) string {
	value := os.Getenv(key)
	if value == "" {
		return fallback
	}
	return value
}

func envBool(key string, fallback bool) bool {
	value := os.Getenv(key)
	if value == "" {
		return fallback
	}
	parsed, err := strconv.ParseBool(value)
	if err != nil {
		return fallback
	}
	return parsed
}
