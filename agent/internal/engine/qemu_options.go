// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"errors"
	"fmt"
	"regexp"
	"strings"
)

const (
	// maxExtraOptionsLength bounds the raw legacy qemu_options string.
	maxExtraOptionsLength = 1024
	// maxExtraTokens bounds the allow-listed extras after parsing.
	maxExtraTokens = 16
)

var (
	bootOrderPattern = regexp.MustCompile(`^order=[a-z]{1,4}$`)
	icountPattern    = regexp.MustCompile(`^[0-9]{1,6}$`)
)

// SplitQEMUOptions splits a legacy qemu_options string with shell-like quoting
// (single and double quotes, backslash escapes) but no expansion. Newlines and
// other control characters are rejected before anything is interpreted.
func SplitQEMUOptions(raw string) ([]string, error) {
	if len(raw) > maxExtraOptionsLength {
		return nil, fmt.Errorf("qemu options must be at most %d characters", maxExtraOptionsLength)
	}
	if hasControlChars(raw) {
		return nil, errors.New("qemu options must not contain control characters")
	}
	// Legacy UNetLab templates escaped their quotes for PHP (\"), and YAML
	// single-quoted scalars keep the backslash. Normalise that artefact so the
	// value still parses as one argument; this only removes a backslash.
	raw = strings.ReplaceAll(raw, `\"`, `"`)

	var (
		tokens  []string
		token   strings.Builder
		quote   rune
		started bool
	)
	flush := func() error {
		if !started {
			return nil
		}
		if token.Len() == 0 {
			return errors.New("qemu options contain an empty argument")
		}
		tokens = append(tokens, token.String())
		token.Reset()
		started = false

		return nil
	}

	runes := []rune(raw)
	for index := 0; index < len(runes); index++ {
		char := runes[index]
		switch {
		case quote == '\'':
			if char == '\'' {
				quote = 0
				continue
			}
			token.WriteRune(char)
		case quote == '"':
			switch char {
			case '"':
				quote = 0
			case '\\':
				if index+1 < len(runes) && (runes[index+1] == '"' || runes[index+1] == '\\') {
					index++
					token.WriteRune(runes[index])
					continue
				}
				token.WriteRune(char)
			default:
				token.WriteRune(char)
			}
		case char == '\'' || char == '"':
			quote = char
			started = true
		case char == ' ':
			if err := flush(); err != nil {
				return nil, err
			}
		case char == '\\':
			if index+1 >= len(runes) {
				return nil, errors.New("qemu options end with a backslash")
			}
			index++
			token.WriteRune(runes[index])
			started = true
		default:
			token.WriteRune(char)
			started = true
		}
	}
	if quote != 0 {
		return nil, errors.New("qemu options contain an unterminated quote")
	}
	if err := flush(); err != nil {
		return nil, err
	}

	return tokens, nil
}

// MergeQEMUOptions maps known legacy flags into structured fields that are
// still empty, drops flags the engine owns, and returns the allow-listed
// extras. Structured values already resolved by the API are never overwritten.
func MergeQEMUOptions(spec Spec, tokens []string) (Spec, []string, error) {
	extras := append([]string(nil), spec.Extra...)
	var (
		machine       string
		machineAccel  string
		cpuModel      string
		nicModel      string
		diskInterface string
		accels        []string
	)

	for index := 0; index < len(tokens); index++ {
		flag := tokens[index]
		if !strings.HasPrefix(flag, "-") {
			return spec, nil, fmt.Errorf("unexpected qemu option %q", flag)
		}
		switch flag {
		case "-nodefaults", "-no-user-config", "-nodefconfig", "-nographic", "-no-reboot":
			// Engine-owned: rendered unconditionally.
		case "-machine":
			value, next, err := qemuOptionValue(tokens, index)
			if err != nil {
				return spec, nil, err
			}
			index = next
			machine, machineAccel, err = parseMachineOption(value)
			if err != nil {
				return spec, nil, err
			}
		case "-cpu":
			value, next, err := qemuOptionValue(tokens, index)
			if err != nil {
				return spec, nil, err
			}
			index = next
			if !cpuModelPattern.MatchString(value) {
				return spec, nil, fmt.Errorf("invalid cpu model %q in qemu options", value)
			}
			cpuModel = value
		case "-accel":
			value, next, err := qemuOptionValue(tokens, index)
			if err != nil {
				return spec, nil, err
			}
			index = next
			if value != "kvm" && value != "tcg" {
				return spec, nil, fmt.Errorf("invalid accelerator %q in qemu options", value)
			}
			accels = append(accels, value)
		case "-nic", "-net":
			value, next, err := qemuOptionValue(tokens, index)
			if err != nil {
				return spec, nil, err
			}
			index = next
			nicModel, err = parseNICOption(value)
			if err != nil {
				return spec, nil, err
			}
		case "-drive":
			value, next, err := qemuOptionValue(tokens, index)
			if err != nil {
				return spec, nil, err
			}
			index = next
			diskInterface, err = parseDriveInterface(value)
			if err != nil {
				return spec, nil, err
			}
		case "-boot":
			value, next, err := qemuOptionValue(tokens, index)
			if err != nil {
				return spec, nil, err
			}
			index = next
			if bootIsEngineOwned(spec) {
				// The engine picks the boot order for disk and ISO nodes.
				continue
			}
			if err := checkExtraValue(spec, flag, value); err != nil {
				return spec, nil, err
			}
			extras = append(extras, flag, value)
		case "-smp", "-m", "-monitor", "-serial", "-display", "-rtc", "-name", "-uuid",
			"-kernel", "-initrd", "-append", "-cdrom", "-vga":
			// Engine-owned flags and values; dropping them keeps one of each
			// in the rendered argv (display is always none, serial follows
			// the console mode, and boot images come from the spec).
			_, next, err := qemuOptionValue(tokens, index)
			if err != nil {
				return spec, nil, err
			}
			index = next
		case "-netdev":
			value, next, err := qemuOptionValue(tokens, index)
			if err != nil {
				return spec, nil, err
			}
			index = next
			if !strings.HasPrefix(value, "tap,") {
				return spec, nil, fmt.Errorf("qemu -netdev %q is not allowed: the engine owns the tap netdev", value)
			}
		case "-no-acpi", "-no-hpet", "-no-shutdown":
			if err := checkExtraValue(spec, flag, ""); err != nil {
				return spec, nil, err
			}
			extras = append(extras, flag)
		case "-smbios", "-global", "-icount", "-realtime", "-usbdevice":
			value, next, err := qemuOptionValue(tokens, index)
			if err != nil {
				return spec, nil, err
			}
			index = next
			if err := checkExtraValue(spec, flag, value); err != nil {
				return spec, nil, err
			}
			extras = append(extras, flag, value)
		case "-device", "-fsdev", "-virtfs", "-chardev", "-vnc", "-pidfile", "-runas",
			"-sandbox", "-daemonize", "-S", "-readconfig", "-writeconfig":
			return spec, nil, fmt.Errorf("qemu option %q is not allowed", flag)
		default:
			return spec, nil, fmt.Errorf("qemu option %q is not allowed", flag)
		}
	}

	if spec.Machine == "" {
		spec.Machine = machine
	}
	if spec.Accel == "" {
		switch {
		case len(accels) > 0:
			joined, err := joinAccels(accels)
			if err != nil {
				return spec, nil, err
			}
			spec.Accel = joined
		case machineAccel != "":
			spec.Accel = machineAccel
		}
	}
	if spec.CPUModel == "" {
		spec.CPUModel = cpuModel
	}
	if spec.NIC == "" {
		spec.NIC = nicModel
	}
	if spec.DiskInterface == "" {
		spec.DiskInterface = diskInterface
	}
	if err := validateExtraTokens(spec, extras); err != nil {
		return spec, nil, err
	}
	spec.Extra = extras
	spec.ExtraOptions = ""

	return spec, extras, nil
}

// withExtraOptions returns a spec with its raw legacy qemu_options parsed,
// mapped and cleared. MergeQEMUOptions is idempotent, so the manager applies it
// before validation and BuildQEMUArgs can still be used stand-alone.
func (s Spec) withExtraOptions() (Spec, error) {
	tokens, err := SplitQEMUOptions(s.ExtraOptions)
	if err != nil {
		return s, err
	}
	merged, extras, err := MergeQEMUOptions(s, tokens)
	if err != nil {
		return s, err
	}
	merged.Extra = extras

	return merged, nil
}

// bootIsEngineOwned reports whether BuildQEMUArgs chooses the boot order.
func bootIsEngineOwned(spec Spec) bool {
	if spec.Kernel != "" {
		return false
	}

	return spec.Image != "" || spec.ISO != ""
}

// validateExtraTokens checks a flat flag/value token list against the extras
// allow-list. It is used for tokens parsed here and for an explicit Extra list.
func validateExtraTokens(spec Spec, tokens []string) error {
	if len(tokens) > maxExtraTokens {
		return fmt.Errorf("at most %d extra qemu options are allowed", maxExtraTokens)
	}
	for index := 0; index < len(tokens); {
		flag := tokens[index]
		if !strings.HasPrefix(flag, "-") {
			return fmt.Errorf("unexpected extra qemu argument %q", flag)
		}
		if extraOptionTakesValue(flag) {
			if index+1 >= len(tokens) {
				return fmt.Errorf("qemu option %q requires a value", flag)
			}
			if err := checkExtraValue(spec, flag, tokens[index+1]); err != nil {
				return err
			}
			index += 2
			continue
		}
		if err := checkExtraValue(spec, flag, ""); err != nil {
			return err
		}
		index++
	}

	return nil
}

func extraOptionTakesValue(flag string) bool {
	switch flag {
	case "-smbios", "-global", "-boot", "-icount", "-realtime", "-usbdevice":
		return true
	}

	return false
}

// checkExtraValue enforces the allow-list and rejects arguments that could
// reach host files, sockets or devices. The allow-list is deliberately small;
// everything the engine can render itself is not an extra.
func checkExtraValue(spec Spec, flag, value string) error {
	if value != "" {
		if len(value) > 256 {
			return fmt.Errorf("qemu option %q has an over-long value", flag)
		}
		if hasControlChars(value) || strings.HasPrefix(value, "-") {
			return fmt.Errorf("qemu option %q has an invalid value", flag)
		}
		lower := strings.ToLower(value)
		for _, blocked := range []string{"file=", "path=", "host=", "unix:", "socket", "tap,"} {
			if strings.Contains(lower, blocked) {
				return fmt.Errorf("qemu option %q must not reference files, hosts or sockets", flag)
			}
		}
	}

	switch flag {
	case "-no-acpi", "-no-hpet", "-no-shutdown":
		if value != "" {
			return fmt.Errorf("qemu option %q does not take a value", flag)
		}
	case "-smbios":
		if !strings.Contains(value, "type=") {
			return fmt.Errorf("unsupported -smbios value %q", value)
		}
	case "-global":
		if !strings.Contains(value, ".") || !strings.Contains(value, "=") {
			return fmt.Errorf("unsupported -global value %q", value)
		}
	case "-boot":
		if bootIsEngineOwned(spec) {
			return errors.New("qemu options must not override the engine boot order")
		}
		if !bootOrderPattern.MatchString(value) {
			return fmt.Errorf("unsupported -boot value %q", value)
		}
	case "-icount":
		if !icountPattern.MatchString(value) {
			return fmt.Errorf("unsupported -icount value %q", value)
		}
	case "-realtime":
		if value != "mlock=off" {
			return fmt.Errorf("unsupported -realtime value %q", value)
		}
	case "-usbdevice":
		if value != "tablet" {
			return fmt.Errorf("unsupported -usbdevice value %q: only tablet is allowed", value)
		}
	default:
		return fmt.Errorf("qemu option %q is not allowed", flag)
	}

	return nil
}

func qemuOptionValue(tokens []string, index int) (string, int, error) {
	if index+1 >= len(tokens) || strings.HasPrefix(tokens[index+1], "-") {
		return "", index, fmt.Errorf("qemu option %q requires a value", tokens[index])
	}

	return tokens[index+1], index + 1, nil
}

// parseMachineOption extracts the machine type and accelerator from
// "-machine type=pc,accel=kvm". Other machine properties (usb=off) are device
// tuning the engine does not model and are dropped.
func parseMachineOption(value string) (string, string, error) {
	var machine, accel string
	for _, part := range strings.Split(value, ",") {
		part = strings.TrimSpace(part)
		if part == "" {
			continue
		}
		key, val, found := strings.Cut(part, "=")
		if !found {
			if machine != "" {
				return "", "", fmt.Errorf("invalid machine option %q", value)
			}
			machine = part
			continue
		}
		switch key {
		case "type":
			machine = val
		case "accel":
			accel = val
		}
	}
	if machine == "" {
		return "", "", fmt.Errorf("qemu -machine %q has no type", value)
	}
	if !machinePattern.MatchString(machine) {
		return "", "", fmt.Errorf("invalid machine type %q in qemu options", machine)
	}
	if accel != "" && accel != "kvm" && accel != "tcg" {
		return "", "", fmt.Errorf("invalid machine accelerator %q in qemu options", accel)
	}

	return machine, accel, nil
}

// parseNICOption accepts "-nic model=vmxnet3" and "-net nic,model=vmxnet3".
// MAC and netdev ids are engine-owned and ignored.
func parseNICOption(value string) (string, error) {
	parts := strings.Split(value, ",")
	model := ""
	for _, part := range parts {
		switch {
		case part == "nic":
		case strings.HasPrefix(part, "model="):
			model = strings.TrimPrefix(part, "model=")
		case strings.HasPrefix(part, "mac="), strings.HasPrefix(part, "id="):
		case len(parts) == 1 && nicDevices[canonicalNIC(part)] != "":
			model = canonicalNIC(part)
		default:
			return "", fmt.Errorf("unsupported nic option %q", value)
		}
	}
	model = canonicalNIC(model)
	if nicDevices[model] == "" {
		return "", fmt.Errorf("unsupported nic model in %q", value)
	}

	return model, nil
}

// parseDriveInterface reads the if= hint from a -drive option. Drives with a
// file or media reference are rejected: the overlay path is engine-owned.
func parseDriveInterface(value string) (string, error) {
	if strings.Contains(value, "file=") || strings.Contains(value, "media=") {
		return "", errors.New("qemu -drive options with a file or media are not allowed")
	}
	iface := ""
	for _, part := range strings.Split(value, ",") {
		if strings.HasPrefix(part, "if=") {
			iface = strings.TrimPrefix(part, "if=")
		}
	}
	if iface == "" {
		return "", nil
	}
	if !validDiskInterface(iface) {
		return "", fmt.Errorf("unsupported disk interface %q", iface)
	}

	return iface, nil
}

func joinAccels(values []string) (string, error) {
	seen := map[string]bool{}
	ordered := make([]string, 0, len(values))
	for _, value := range values {
		if seen[value] {
			continue
		}
		seen[value] = true
		ordered = append(ordered, value)
	}
	joined := strings.Join(ordered, ":")
	if !validAccel(joined) {
		return "", fmt.Errorf("conflicting qemu accelerators %q", joined)
	}

	return joined, nil
}
