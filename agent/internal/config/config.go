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

	"github.com/SavEvens/TeraLab/agent/internal/network"
	"github.com/SavEvens/TeraLab/agent/internal/origin"
)

const Version = "0.1.0"

// Per-lab uid pool defaults. UIDs at or above 200000 are outside the range
// Debian reserves for real accounts, and the whole pool stays below 2^31 so it
// is a valid Linux uid.
const (
	defaultLabUIDFirst uint32 = 200000
	defaultLabUIDSlots        = 64
	labUIDLimit        uint64 = 1 << 31
)

// validateLabUIDPool rejects a pool that would overlap system accounts or
// overflow the Linux uid range. It runs even when per-lab uids are disabled so
// an operator typo is reported immediately.
func validateLabUIDPool(first uint32, slots int) error {
	if slots < 1 {
		return fmt.Errorf("TERALAB_LAB_UID_SLOTS must be at least 1, got %d", slots)
	}
	if first < defaultLabUIDFirst {
		return fmt.Errorf(
			"TERALAB_LAB_UID_FIRST must be at least %d, got %d",
			defaultLabUIDFirst,
			first,
		)
	}
	last := uint64(first) + uint64(slots) - 1
	if last >= labUIDLimit {
		return fmt.Errorf(
			"per-lab uid pool %d..%d is outside the Linux uid range below %d",
			first,
			last,
			labUIDLimit,
		)
	}

	return nil
}

type Config struct {
	SocketPath  string
	SocketGID   int
	SocketMode  os.FileMode
	ConsoleAddr string
	// ConsoleAllowedOrigins is the explicit browser-origin allowlist for the
	// console WebSocket handshake, in scheme://host[:port] form. When empty
	// the gateway accepts same-origin handshakes only.
	ConsoleAllowedOrigins []string
	Secret                string
	AllowedUIDs           map[uint32]bool
	RunDir                string
	// StateDir holds the root-owned, agent-only node records used by
	// reconciliation. It must never be writable by the engine user.
	StateDir        string
	ImagesDir       string
	ImageExtraRoots []string
	CgroupRoot      string
	DisableCgroups  bool
	QEMUBinary      string
	QEMUImg         string
	VPCSBinary      string
	DynamipsBinary  string
	DockerBinary    string
	IOLIourc        string
	NetworkBackend  string
	// BridgeGroupFwdMask is written to each lab bridge port so STP and LACP
	// frames cross the emulated segment. 0 disables the per-port write.
	BridgeGroupFwdMask uint16
	VMUser             string
	VMUID              uint32
	VMGID              uint32
	VMGroups           []uint32
	// PerLabUID makes every lab run under its own numeric uid/gid taken from
	// the slot pool (TERALAB_LAB_UID_FIRST + slot). The agent works without
	// passwd entries; TERALAB_PER_LAB_UID=0 keeps the shared VMUser path.
	PerLabUID bool
	// LabUIDFirst is the first uid/gid of the per-lab pool.
	LabUIDFirst uint32
	// LabUIDSlots is the number of per-lab slots. Exhaustion fails closed.
	LabUIDSlots int
	// ImageGID is an optional shared image-store group added to every engine
	// as a supplementary gid. 0 adds none.
	ImageGID    uint32
	AllowRootVM bool
	DisableKVM  bool
	// DisableDockerPull blocks docker pull from the agent. The demo profile
	// sets it; images are pre-pulled by the operator.
	DisableDockerPull bool
	// QEMUSandbox adds QEMU's seccomp sandbox to every node. It is on by
	// default and only disabled by an explicit operator decision.
	QEMUSandbox bool
}

func Load() (Config, error) {
	cfg := Config{
		SocketPath:        env("TERALAB_AGENT_SOCKET", "/run/teralab/agent.sock"),
		SocketMode:        os.FileMode(0o660),
		ConsoleAddr:       env("TERALAB_AGENT_CONSOLE", "127.0.0.1:8091"),
		RunDir:            env("TERALAB_RUN_DIR", "/var/lib/teralab/run"),
		StateDir:          env("TERALAB_STATE_DIR", "/var/lib/teralab/state"),
		ImagesDir:         env("TERALAB_IMAGES_DIR", "/var/lib/teralab/images"),
		CgroupRoot:        env("TERALAB_CGROUP_ROOT", "/sys/fs/cgroup/teralab.slice"),
		DisableCgroups:    envBool("TERALAB_DISABLE_CGROUPS", false),
		QEMUBinary:        env("TERALAB_QEMU", "/usr/bin/qemu-system-x86_64"),
		QEMUImg:           env("TERALAB_QEMU_IMG", "/usr/bin/qemu-img"),
		VPCSBinary:        env("TERALAB_VPCS", "/usr/bin/vpcs"),
		DynamipsBinary:    env("TERALAB_DYNAMIPS", "/usr/bin/dynamips"),
		DockerBinary:      env("TERALAB_DOCKER", "/usr/bin/docker"),
		IOLIourc:          env("TERALAB_IOL_IOURC", "/etc/teralab/iourc"),
		NetworkBackend:    env("TERALAB_NETWORK_BACKEND", "auto"),
		VMUser:            env("TERALAB_VM_USER", "teralab-vm"),
		PerLabUID:         envBool("TERALAB_PER_LAB_UID", true),
		AllowRootVM:       envBool("TERALAB_ALLOW_ROOT_VM", false),
		DisableKVM:        envBool("TERALAB_DISABLE_KVM", false),
		DisableDockerPull: envBool("TERALAB_DISABLE_DOCKER_PULL", false),
		QEMUSandbox:       envBool("TERALAB_QEMU_SANDBOX", true),
	}

	first, err := envUint32("TERALAB_LAB_UID_FIRST", defaultLabUIDFirst)
	if err != nil {
		return cfg, err
	}
	cfg.LabUIDFirst = first
	slots, err := envInt("TERALAB_LAB_UID_SLOTS", defaultLabUIDSlots)
	if err != nil {
		return cfg, err
	}
	cfg.LabUIDSlots = slots
	imageGID, err := envUint32("TERALAB_IMAGE_GID", 0)
	if err != nil {
		return cfg, err
	}
	cfg.ImageGID = imageGID
	if err := validateLabUIDPool(cfg.LabUIDFirst, cfg.LabUIDSlots); err != nil {
		return cfg, err
	}

	roots, err := imageExtraRoots()
	if err != nil {
		return cfg, err
	}
	cfg.ImageExtraRoots = roots

	mask, err := bridgeGroupFwdMask()
	if err != nil {
		return cfg, err
	}
	cfg.BridgeGroupFwdMask = mask

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

	cfg.ConsoleAllowedOrigins, err = consoleAllowedOrigins()
	if err != nil {
		return cfg, err
	}

	cfg.AllowedUIDs, err = allowedAgentUIDs()
	if err != nil {
		return cfg, err
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

// bridgeGroupFwdMask parses TERALAB_BRIDGE_GROUP_FWD_MASK (decimal or 0x hex,
// 0 disables). A malformed value is a configuration error, so an operator typo
// cannot silently disable forwarding for STP and LACP.
func bridgeGroupFwdMask() (uint16, error) {
	raw := strings.TrimSpace(os.Getenv("TERALAB_BRIDGE_GROUP_FWD_MASK"))
	if raw == "" {
		return network.DefaultBridgeGroupFwdMask, nil
	}
	value, err := strconv.ParseUint(raw, 0, 16)
	if err != nil {
		return 0, fmt.Errorf(
			"invalid TERALAB_BRIDGE_GROUP_FWD_MASK %q: want a 16-bit value such as 0x5 or 0",
			raw,
		)
	}

	return uint16(value), nil
}

// defaultAllowedAgentUIDs is the documented fallback for direct invocations
// that do not set TERALAB_AGENT_ALLOWED_UIDS at all. The shipped systemd unit
// always sets the variable (empty until the identity drop-in provides the real
// UID), so a unit without that drop-in fails closed instead of reaching this
// default.
const defaultAllowedAgentUIDs = "33"

// allowedAgentUIDs parses TERALAB_AGENT_ALLOWED_UIDS (comma-separated peer
// UIDs). An absent variable keeps the documented default; a variable that is
// present but empty, whitespace-only, or contains no numeric UID is a
// configuration error. An empty value must never silently fall back to
// www-data (33), because the systemd unit relies on that being fatal.
func allowedAgentUIDs() (map[uint32]bool, error) {
	raw, present := os.LookupEnv("TERALAB_AGENT_ALLOWED_UIDS")
	if !present {
		raw = defaultAllowedAgentUIDs
	}
	uids := map[uint32]bool{}
	for _, entry := range strings.Split(raw, ",") {
		entry = strings.TrimSpace(entry)
		if entry == "" {
			continue
		}
		uid, err := strconv.ParseUint(entry, 10, 32)
		if err != nil {
			return nil, fmt.Errorf("invalid allowed UID %q", entry)
		}
		uids[uint32(uid)] = true
	}
	if len(uids) == 0 {
		return nil, fmt.Errorf(
			"TERALAB_AGENT_ALLOWED_UIDS is set but lists no UIDs (%q); refusing to fall back to the default",
			raw,
		)
	}

	return uids, nil
}

// consoleAllowedOrigins parses TERALAB_CONSOLE_ALLOWED_ORIGINS. Each entry is
// a browser origin (scheme://host[:port]); paths, wildcards, and userinfo are
// rejected so a typo cannot widen the allowlist. An absent or empty variable
// keeps the same-origin default.
func consoleAllowedOrigins() ([]string, error) {
	raw, present := os.LookupEnv("TERALAB_CONSOLE_ALLOWED_ORIGINS")
	if !present || strings.TrimSpace(raw) == "" {
		return nil, nil
	}
	origins := make([]string, 0)
	seen := map[string]bool{}
	for _, entry := range strings.Split(raw, ",") {
		entry = strings.TrimSpace(entry)
		if entry == "" {
			continue
		}
		canonical, err := origin.Parse(entry)
		if err != nil {
			return nil, fmt.Errorf(
				"invalid TERALAB_CONSOLE_ALLOWED_ORIGINS entry %q: %w (want scheme://host[:port])",
				entry,
				err,
			)
		}
		if seen[canonical] {
			continue
		}
		seen[canonical] = true
		origins = append(origins, canonical)
	}
	if len(origins) == 0 {
		return nil, fmt.Errorf(
			"TERALAB_CONSOLE_ALLOWED_ORIGINS is set but lists no origins (%q)",
			raw,
		)
	}

	return origins, nil
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

func envUint32(key string, fallback uint32) (uint32, error) {
	value := os.Getenv(key)
	if value == "" {
		return fallback, nil
	}
	parsed, err := strconv.ParseUint(value, 10, 32)
	if err != nil {
		return 0, fmt.Errorf("invalid %s %q: must be an unsigned 32-bit integer", key, value)
	}

	return uint32(parsed), nil
}

func envInt(key string, fallback int) (int, error) {
	value := os.Getenv(key)
	if value == "" {
		return fallback, nil
	}
	parsed, err := strconv.Atoi(value)
	if err != nil {
		return 0, fmt.Errorf("invalid %s %q: must be an integer", key, value)
	}

	return parsed, nil
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
