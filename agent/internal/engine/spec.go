// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

package engine

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/SavEvens/TeraLab/agent/internal/network"
)

var (
	idPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$`)
	// machinePattern accepts the generic aliases, the versioned pc/q35 aliases
	// used by migrated UNetLab templates (pc-1.0) and the canonical versioned
	// names (pc-i440fx-7.2, pc-q35-7.2).
	machinePattern = regexp.MustCompile(`^((pc|q35)(-[0-9]+(\.[0-9]+)*)?|(pc-i440fx|pc-q35)-[0-9]+(\.[0-9]+)*)$`)
	// machineGenericAliasPattern matches the generic versioned aliases
	// (pc-1.0, q35-1.0) that migrated UNetLab templates carry. QEMU 10 no
	// longer ships them, so Normalize maps them onto the generic alias.
	machineGenericAliasPattern = regexp.MustCompile(`^(pc|q35)-[0-9]+(\.[0-9]+)*$`)
	// machineVersionedPattern matches the explicit versioned names
	// (pc-i440fx-1.9, pc-q35-1.9) that QEMU 10 dropped as well.
	machineVersionedPattern = regexp.MustCompile(`^(pc-i440fx|pc-q35)-([0-9]+)(\.[0-9]+)*$`)
	// cpuModelPattern covers "host", "max", named models and feature lists
	// such as "qemu64,+ssse3,+sse4.2" found in the shipped templates.
	cpuModelPattern = regexp.MustCompile(`^[A-Za-z0-9_.+,-]{1,64}$`)
	bridgePattern   = regexp.MustCompile(`^[A-Za-z0-9_.:-]{1,15}$`)
	uuidPattern     = regexp.MustCompile(`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`)
	platformPattern = regexp.MustCompile(`^(c7200|c3600|c2691|c3725|c3745|c1700|c2600)$`)
	slotPattern     = regexp.MustCompile(`^[0-9]{1,2}:[0-9]{1,2}:(tap|linux|unix)$`)
	// dockerImagePattern is a conservative Docker reference grammar: an
	// optional registry host (with an optional port), a lowercase repository
	// path, an optional tag, and an optional sha256 digest. Registry hosts may
	// contain uppercase letters; repository components may not.
	dockerImagePattern = regexp.MustCompile(
		`^([A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:\d{1,5})?/)?` +
			`([a-z0-9]+(([._]|__|-+)[a-z0-9]+)*/)*` +
			`[a-z0-9]+(([._]|__|-+)[a-z0-9]+)*` +
			`(:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?` +
			`(@sha256:[0-9a-f]{64})?$`,
	)
	// dockerConsolePattern accepts an absolute console command with no words
	// or shell metacharacters; the engine runs it directly, never through a
	// shell.
	dockerConsolePattern = regexp.MustCompile(`^/[A-Za-z0-9_./+-]{1,255}$`)
	// dockerConfigPathPattern is the container path a template may copy the
	// node startup configuration to. ".." segments and control characters are
	// rejected separately.
	dockerConfigPathPattern = regexp.MustCompile(`^/[A-Za-z0-9_./-]{1,200}$`)
)

// Docker is a bounded engine. Containers start from Docker's default
// capability baseline; templates may opt into the routing and packet
// capabilities below. SYS_ADMIN is required by FRR zebra and stays opt-in.
const (
	maxDockerCapAdd    = 8
	maxDockerDaemons   = 12
	maxDockerConfigLen = 65536
	dockerDaemonsPath  = "/etc/frr/daemons"
)

var dockerTemplateCapabilities = []string{"NET_ADMIN", "NET_RAW", "SYS_ADMIN"}

var dockerCapabilities = map[string]bool{
	"NET_ADMIN": true,
	"NET_RAW":   true,
	"SYS_ADMIN": true,
}

// dockerDaemonAllowlist is the selectable daemon set shipped in the FRR
// image's /etc/frr/daemons file. The order is the rendering order: it is fixed
// here so the generated file never depends on the template's list order or on
// the image's own defaults. zebra and staticd always run in the image; zebra
// may be listed for readability.
var dockerDaemonAllowlist = []string{
	"zebra", "bgpd", "ospfd", "ospf6d", "ripd", "ripngd", "isisd", "pimd",
	"pim6d", "ldpd", "nhrpd", "eigrpd", "babeld", "sharpd", "pbrd", "bfdd",
	"fabricd", "vrrpd", "pathd",
}

var dockerDaemons = func() map[string]bool {
	allowed := make(map[string]bool, len(dockerDaemonAllowlist))
	for _, daemon := range dockerDaemonAllowlist {
		allowed[daemon] = true
	}

	return allowed
}()

// QEMU fallbacks, applied only when the node spec leaves a field empty. The
// e1000 default is deliberate: most built-in templates declare no NIC, PAN-OS
// is untested on virtio, and templates opt into vmxnet3/virtio explicitly.
const (
	defaultArch          = "x86_64"
	defaultMachine       = "pc"
	defaultNIC           = "e1000"
	defaultDiskInterface = "virtio"
)

// firstShippedMachineMajor is the oldest explicit pc-i440fx/pc-q35 version
// QEMU 10 still provides. Older names cannot start and fall back to the
// generic alias during Normalize.
const firstShippedMachineMajor = 2

// Extra disk bounds. The primary disk keeps Image/virtioa; template options
// may add one disk per remaining virtio slot.
const (
	maxExtraDisks = 3
	minDiskSizeGB = 1
	maxDiskSizeGB = 100
)

// diskSlotOrder is the attachment order for extra disks. It is fixed here so
// neither the template's list order nor map iteration can reorder drives.
var diskSlotOrder = []string{"virtiob", "virtioc", "virtiod"}

// supportedNICs lists the canonical short NIC names accepted from the API.
// nicDevices maps them to the QEMU -device model; "virtio" is a TeraLab
// shorthand and not a QEMU device name. nicAliases keeps the values stored in
// older labs and used by the shipped Inspector working: they normalise to a
// canonical name before validation and rendering.
var (
	supportedNICs = []string{"virtio", "vmxnet3", "e1000", "rtl8139"}
	nicAliases    = map[string]string{
		"virtio-net-pci": "virtio",
		"i82559er":       "e1000",
	}
	nicDevices = map[string]string{
		"virtio":  "virtio-net-pci",
		"vmxnet3": "vmxnet3",
		"e1000":   "e1000",
		"rtl8139": "rtl8139",
	}
	supportedAccels         = []string{"kvm", "tcg", "kvm:tcg"}
	supportedDiskInterfaces = []string{"virtio", "scsi", "ide", "sata"}
)

// Engines lists the supported node engines.
var Engines = []string{"qemu", "vpcs", "dynamips", "iol", "docker"}

// DockerOptions is the bounded, template-declared configuration for a
// container node. Arbitrary extra arguments are deliberately absent.
type DockerOptions struct {
	ConsoleCommand string   `json:"console_command,omitempty"`
	CapAdd         []string `json:"cap_add,omitempty"`
	// Daemons selects FRR daemons from dockerDaemonAllowlist. When it is set,
	// the engine renders a complete /etc/frr/daemons file and copies it into
	// the container before every start. An empty list injects nothing.
	Daemons []string `json:"daemons,omitempty"`
	// ConfigPath is the absolute container path the node startup Config is
	// copied to before every start. An empty value injects no configuration.
	ConfigPath string `json:"config_path,omitempty"`
	// Pull controls whether a missing image may be pulled. A nil value keeps
	// the historical default: pull once, with a bounded timeout.
	Pull *bool `json:"pull,omitempty"`
}

// PullEnabled reports whether the engine may pull a missing image.
func (d *DockerOptions) PullEnabled() bool {
	if d == nil || d.Pull == nil {
		return true
	}

	return *d.Pull
}

// DiskSpec is one template-declared extra disk. Slot selects the virtio
// device (virtiob..virtiod); exactly one of Image (a stored image, resolved to
// an absolute path under the image roots by the API) or SizeGB (a blank disk
// generated per node) is set.
type DiskSpec struct {
	Slot   string `json:"slot"`
	Image  string `json:"image,omitempty"`
	SizeGB int    `json:"size_gb,omitempty"`
}

type Spec struct {
	ID       string   `json:"id"`
	LabID    string   `json:"lab_id"`
	Name     string   `json:"name"`
	Engine   string   `json:"engine,omitempty"`
	Image    string   `json:"image,omitempty"`
	ISO      string   `json:"iso,omitempty"`
	Kernel   string   `json:"kernel,omitempty"`
	Initrd   string   `json:"initrd,omitempty"`
	Append   string   `json:"append,omitempty"`
	CPU      int      `json:"cpu"`
	RAM      int      `json:"ram"`
	Ethernet int      `json:"ethernet"`
	Serial   int      `json:"serial,omitempty"`
	Networks []string `json:"networks,omitempty"`
	NIC      string   `json:"nic,omitempty"`
	Machine  string   `json:"machine,omitempty"`
	Arch     string   `json:"arch,omitempty"`
	CPUModel string   `json:"cpu_model,omitempty"`
	Accel    string   `json:"accel,omitempty"`
	// Disks declares extra QEMU disks beyond the primary Image. The primary
	// disk keeps its Image/virtioa semantics; extras are attached after it.
	Disks []DiskSpec `json:"disks,omitempty"`
	// DiskInterface is the QEMU -drive if= value; ExtraOptions is the raw
	// legacy qemu_options string, parsed before validation; Extra holds the
	// allow-listed extras after parsing.
	DiskInterface string   `json:"disk_interface,omitempty"`
	ExtraOptions  string   `json:"extra_options,omitempty"`
	Extra         []string `json:"extra,omitempty"`
	Console       string   `json:"console,omitempty"`
	UUID          string   `json:"uuid,omitempty"`
	Platform      string   `json:"platform,omitempty"`
	NVRAM         int      `json:"nvram,omitempty"`
	Slots         []string `json:"slots,omitempty"`
	Iourc         string   `json:"iourc,omitempty"`
	Config        string   `json:"config,omitempty"`
	// Docker carries the bounded container options for engine "docker".
	Docker *DockerOptions `json:"docker,omitempty"`
}

func (s *Spec) Normalize() {
	if s.Engine == "" {
		s.Engine = "qemu"
	}
	if s.CPU == 0 {
		s.CPU = 1
	}
	if s.RAM == 0 {
		s.RAM = 512
	}
	if s.Arch == "" {
		s.Arch = defaultArch
	}
	if s.NIC == "" {
		s.NIC = defaultNIC
	} else {
		s.NIC = canonicalNIC(s.NIC)
	}
	if s.Machine == "" {
		s.Machine = defaultMachine
	}
	// Generic and pre-2.0 versioned aliases are no longer shipped by QEMU 10.
	// Canonicalizing here covers templates, node machine overrides and the raw
	// qemu_options that Start parses into Machine before validation.
	s.Machine = canonicalMachine(s.Machine)
	if s.DiskInterface == "" {
		s.DiskInterface = defaultDiskInterface
	}
	if s.Console == "" {
		s.Console = "telnet"
	}
	if s.Platform == "" {
		s.Platform = "c7200"
	}
	if s.NVRAM == 0 {
		s.NVRAM = 512
	}
	if s.UUID == "" {
		s.UUID = randomUUID()
	}
	for len(s.Networks) < s.Ethernet {
		s.Networks = append(s.Networks, "")
	}
	if s.Engine == "dynamips" && len(s.Slots) == 0 {
		for index := 0; index < s.Ethernet; index++ {
			s.Slots = append(s.Slots, "0:"+strconv.Itoa(index)+":tap")
		}
	}
	if s.Engine == "docker" && s.Docker != nil {
		s.Docker.CapAdd = dedupeDockerCaps(s.Docker.CapAdd)
	}
}

func (s Spec) Validate(imageRoots []string) error {
	if !idPattern.MatchString(s.ID) {
		return errors.New("invalid node id")
	}
	if s.LabID != "" && !idPattern.MatchString(s.LabID) {
		return errors.New("invalid lab id")
	}
	if strings.TrimSpace(s.Name) == "" || utf8.RuneCountInString(s.Name) > 128 {
		return errors.New("invalid node name")
	}
	if s.CPU < 1 || s.CPU > 64 {
		return errors.New("cpu must be between 1 and 64")
	}
	if s.RAM < 64 || s.RAM > 131072 {
		return errors.New("ram must be between 64 and 131072 MiB")
	}
	if s.Ethernet < 0 || s.Ethernet > 16 {
		return errors.New("ethernet must be between 0 and 16")
	}
	if len(s.Networks) != s.Ethernet {
		return errors.New("networks length must match ethernet")
	}
	for _, bridge := range s.Networks {
		if bridge != "" && !bridgePattern.MatchString(bridge) {
			return fmt.Errorf("invalid bridge name %q", bridge)
		}
	}
	if s.Console != "telnet" && s.Console != "vnc" {
		return errors.New("console must be either telnet or vnc")
	}
	if s.Serial < 0 || s.Serial > 8 {
		return errors.New("serial must be between 0 and 8")
	}
	if !uuidPattern.MatchString(s.UUID) {
		return errors.New("invalid uuid")
	}
	// Extra disks are a QEMU-only concept; reject them before dispatching so
	// no other engine silently ignores a template disk layout.
	if len(s.Disks) > 0 && s.Engine != "" && s.Engine != "qemu" {
		return errors.New("extra disks are only supported for qemu nodes")
	}

	switch s.Engine {
	case "qemu", "":
		return s.validateQEMU(imageRoots)
	case "vpcs":
		return s.validateVPCS()
	case "dynamips":
		return s.validateDynamips(imageRoots)
	case "iol":
		return s.validateIOL(imageRoots)
	case "docker":
		return s.validateDocker()
	default:
		return fmt.Errorf("unsupported engine %q", s.Engine)
	}
}

func (s Spec) validateQEMU(imageRoots []string) error {
	if s.Arch != "" && s.Arch != defaultArch {
		return fmt.Errorf("unsupported architecture %q: this agent only runs x86_64 guests", s.Arch)
	}
	if s.NIC != "" && nicDevices[canonicalNIC(s.NIC)] == "" {
		return fmt.Errorf("invalid nic driver %q: must be one of %s", s.NIC, strings.Join(supportedNICs, ", "))
	}
	if !machinePattern.MatchString(s.Machine) {
		return errors.New("invalid machine type")
	}
	if s.CPUModel != "" && !cpuModelPattern.MatchString(s.CPUModel) {
		return errors.New("invalid cpu model")
	}
	if !validAccel(s.Accel) {
		return fmt.Errorf("invalid accelerator %q: must be one of %s", s.Accel, strings.Join(supportedAccels, ", "))
	}
	if s.DiskInterface != "" && !validDiskInterface(s.DiskInterface) {
		return fmt.Errorf(
			"invalid disk interface %q: must be one of %s",
			s.DiskInterface,
			strings.Join(supportedDiskInterfaces, ", "),
		)
	}
	if len(s.ExtraOptions) > maxExtraOptionsLength || hasControlChars(s.ExtraOptions) {
		return errors.New("invalid extra qemu options")
	}
	if len(s.Extra) > 0 {
		if err := validateExtraTokens(s, s.Extra); err != nil {
			return err
		}
	}
	if s.Image != "" {
		if err := validateImagePath(s.Image, imageRoots, ".qcow2"); err != nil {
			return err
		}
	}
	if len(s.Disks) > 0 {
		if err := validateExtraDisks(s.Disks, imageRoots); err != nil {
			return err
		}
	}
	if s.ISO != "" {
		if err := validateImagePath(s.ISO, imageRoots, ".iso"); err != nil {
			return err
		}
	}
	if s.Kernel != "" {
		if err := validateFilePath(s.Kernel, imageRoots); err != nil {
			return err
		}
	}
	if s.Initrd != "" {
		if err := validateFilePath(s.Initrd, imageRoots); err != nil {
			return err
		}
	}
	if len(s.Append) > 256 || strings.ContainsAny(s.Append, "\r\n\x00") {
		return errors.New("invalid kernel append string")
	}
	if len(s.Config) > 65536 || strings.ContainsRune(s.Config, 0) {
		return errors.New("invalid startup configuration")
	}

	return nil
}

// validateExtraDisks checks the template-declared extra disks. Input order is
// irrelevant: attachment order is fixed by diskSlotOrder. An image disk goes
// through the same containment and .qcow2 rules as the primary image; a
// generated disk is bounded so a template cannot request a huge sparse file.
func validateExtraDisks(disks []DiskSpec, imageRoots []string) error {
	if len(disks) > maxExtraDisks {
		return fmt.Errorf("at most %d extra disks are allowed", maxExtraDisks)
	}
	seen := make(map[string]bool, len(disks))
	for _, disk := range disks {
		if !validDiskSlot(disk.Slot) {
			return fmt.Errorf(
				"invalid disk slot %q: must be one of %s",
				disk.Slot,
				strings.Join(diskSlotOrder, ", "),
			)
		}
		if seen[disk.Slot] {
			return fmt.Errorf("duplicate disk slot %q", disk.Slot)
		}
		seen[disk.Slot] = true

		hasImage := disk.Image != ""
		hasSize := disk.SizeGB != 0
		if hasImage == hasSize {
			return fmt.Errorf(
				"disk %q must set exactly one of image or size_gb",
				disk.Slot,
			)
		}
		if hasImage {
			if err := validateImagePath(disk.Image, imageRoots, ".qcow2"); err != nil {
				return fmt.Errorf("disk %q: %w", disk.Slot, err)
			}
			continue
		}
		if disk.SizeGB < minDiskSizeGB || disk.SizeGB > maxDiskSizeGB {
			return fmt.Errorf(
				"disk %q size_gb must be between %d and %d",
				disk.Slot,
				minDiskSizeGB,
				maxDiskSizeGB,
			)
		}
	}

	return nil
}

// validDiskSlot reports whether slot is one of the extra virtio disk names.
func validDiskSlot(slot string) bool {
	for _, supported := range diskSlotOrder {
		if slot == supported {
			return true
		}
	}

	return false
}

// orderedDisks returns the disks in attachment order regardless of the order
// the template declared them in. Unknown slots are dropped; validation has
// already rejected them on every path that reaches the engine.
func orderedDisks(disks []DiskSpec) []DiskSpec {
	if len(disks) == 0 {
		return nil
	}
	ordered := make([]DiskSpec, 0, len(disks))
	for _, slot := range diskSlotOrder {
		for _, disk := range disks {
			if disk.Slot == slot {
				ordered = append(ordered, disk)
			}
		}
	}

	return ordered
}

func (s Spec) validateVPCS() error {
	if s.ExtraOptions != "" || len(s.Extra) > 0 {
		return errors.New("qemu options are only supported for qemu nodes")
	}
	if s.Ethernet != 1 {
		return errors.New("vpcs nodes require exactly one ethernet interface")
	}
	if s.Console != "telnet" {
		return errors.New("vpcs supports only a telnet console")
	}
	if s.Image != "" || s.ISO != "" || s.Kernel != "" {
		return errors.New("vpcs does not accept disk or kernel images")
	}

	return nil
}

func (s Spec) validateDynamips(imageRoots []string) error {
	if s.ExtraOptions != "" || len(s.Extra) > 0 {
		return errors.New("qemu options are only supported for qemu nodes")
	}
	if !platformPattern.MatchString(s.Platform) {
		return errors.New("invalid dynamips platform")
	}
	if s.Console != "telnet" {
		return errors.New("dynamips supports only a telnet console")
	}
	if s.Image == "" {
		return errors.New("dynamips requires an IOS image")
	}
	if err := validateImagePath(s.Image, imageRoots, ".bin"); err != nil {
		return err
	}
	if s.RAM < 64 || s.RAM > 8192 {
		return errors.New("dynamips ram must be between 64 and 8192 MiB")
	}
	if s.NVRAM < 64 || s.NVRAM > 4096 {
		return errors.New("dynamips nvram must be between 64 and 4096 KiB")
	}
	for _, slot := range s.Slots {
		if !slotPattern.MatchString(slot) {
			return fmt.Errorf("invalid dynamips slot %q", slot)
		}
	}

	return nil
}

func (s Spec) validateIOL(imageRoots []string) error {
	if s.ExtraOptions != "" || len(s.Extra) > 0 {
		return errors.New("qemu options are only supported for qemu nodes")
	}
	if s.Console != "telnet" {
		return errors.New("iol supports only a telnet console")
	}
	if s.Image == "" {
		return errors.New("iol requires an image")
	}
	if err := validateImagePath(s.Image, imageRoots, ".bin"); err != nil {
		return err
	}
	if s.Iourc != "" {
		if err := validateFilePath(s.Iourc, imageRoots); err != nil {
			return err
		}
	}
	if len(s.Config) > 65536 || strings.ContainsRune(s.Config, 0) {
		return errors.New("invalid startup configuration")
	}

	return nil
}

// validateDocker checks the container-specific fields. Container images are
// registry references, not files under the image store, so they never pass
// through resolveFile.
func (s Spec) validateDocker() error {
	if s.Image == "" {
		return errors.New("docker requires an image reference")
	}
	if err := validateDockerImage(s.Image); err != nil {
		return err
	}
	if s.ISO != "" {
		return errors.New("docker nodes do not accept iso images")
	}
	if s.Kernel != "" {
		return errors.New("docker nodes do not accept a kernel image")
	}
	if s.Initrd != "" {
		return errors.New("docker nodes do not accept an initrd")
	}
	if s.ExtraOptions != "" || len(s.Extra) > 0 {
		return errors.New("qemu options are only supported for qemu nodes")
	}
	if s.Console != "telnet" {
		return errors.New("docker supports only a telnet console")
	}
	if s.Serial != 0 {
		return errors.New("docker nodes do not support serial ports")
	}
	if len(s.Config) > maxDockerConfigLen || strings.ContainsRune(s.Config, 0) {
		return errors.New("invalid startup configuration")
	}
	if s.Docker != nil {
		if err := validateDockerOptions(s.Docker); err != nil {
			return err
		}
	}

	return nil
}

func validateDockerOptions(options *DockerOptions) error {
	if options.ConsoleCommand != "" && !dockerConsolePattern.MatchString(options.ConsoleCommand) {
		return fmt.Errorf(
			"invalid docker console command %q: must be an absolute path without spaces or shell characters",
			options.ConsoleCommand,
		)
	}
	seen := map[string]bool{}
	count := 0
	for _, capability := range options.CapAdd {
		if !dockerCapabilities[capability] {
			return fmt.Errorf(
				"unsupported docker capability %q: must be one of %s",
				capability,
				strings.Join(dockerTemplateCapabilities, ", "),
			)
		}
		if seen[capability] {
			continue
		}
		seen[capability] = true
		count++
	}
	// The limit applies to template-provided capabilities only; the fixed
	// Docker baseline is not counted here.
	if count > maxDockerCapAdd {
		return fmt.Errorf("docker cap_add may not contain more than %d capabilities", maxDockerCapAdd)
	}
	if err := validateDockerDaemons(options.Daemons); err != nil {
		return err
	}
	if err := validateDockerConfigPath(options.ConfigPath); err != nil {
		return err
	}

	return nil
}

// validateDockerDaemons accepts only exact allowlisted names, without
// duplicates, and at most maxDockerDaemons entries. Selection order is free;
// rendering always uses the allowlist order.
func validateDockerDaemons(daemons []string) error {
	if len(daemons) == 0 {
		return nil
	}
	if len(daemons) > maxDockerDaemons {
		return fmt.Errorf("docker daemons may not contain more than %d entries", maxDockerDaemons)
	}
	seen := make(map[string]bool, len(daemons))
	for _, daemon := range daemons {
		if !dockerDaemons[daemon] {
			return fmt.Errorf(
				"unsupported docker daemon %q: must be one of %s",
				daemon,
				strings.Join(dockerDaemonAllowlist, ", "),
			)
		}
		if seen[daemon] {
			return fmt.Errorf("duplicate docker daemon %q", daemon)
		}
		seen[daemon] = true
	}

	return nil
}

// validateDockerConfigPath accepts an absolute container path with a bounded
// alphabet and no parent-directory segment. The value is passed to docker cp
// as one argv element, never through a shell.
func validateDockerConfigPath(path string) error {
	if path == "" {
		return nil
	}
	if hasControlChars(path) || strings.ContainsRune(path, 0) {
		return errors.New("invalid docker config_path: control characters are not allowed")
	}
	if !dockerConfigPathPattern.MatchString(path) {
		return fmt.Errorf(
			"invalid docker config_path %q: must be an absolute path of at most 200 characters without spaces or shell characters",
			path,
		)
	}
	for _, segment := range strings.Split(path, "/") {
		if segment == ".." {
			return fmt.Errorf("invalid docker config_path %q: parent directory segments are not allowed", path)
		}
	}

	return nil
}

// validateDockerImage accepts a conservative registry reference. Tags and
// sha256 digests are both supported; anything that looks like a path escape,
// a flag, or a shell fragment is rejected before it reaches the docker CLI.
func validateDockerImage(reference string) error {
	if reference == "" {
		return errors.New("docker requires an image reference")
	}
	if len(reference) > 255 {
		return errors.New("docker image reference must be at most 255 characters")
	}
	if strings.ContainsAny(reference, " \t\r\n") || hasControlChars(reference) {
		return errors.New("invalid docker image reference")
	}
	if strings.HasPrefix(reference, "/") {
		return errors.New("invalid docker image reference: must not start with a slash")
	}
	if strings.Contains(reference, "..") {
		return errors.New("invalid docker image reference: must not contain ..")
	}
	if !dockerImagePattern.MatchString(reference) {
		return fmt.Errorf("invalid docker image reference %q", reference)
	}

	return nil
}

func dedupeDockerCaps(values []string) []string {
	if len(values) == 0 {
		return nil
	}
	seen := make(map[string]bool, len(values))
	result := make([]string, 0, len(values))
	for _, value := range values {
		if seen[value] {
			continue
		}
		seen[value] = true
		result = append(result, value)
	}

	return result
}

// canonicalMachine maps a legacy machine alias to the generic alias that
// current QEMU ships. Generic aliases (pc-1.0, q35-1.0) and explicit
// pc-i440fx/pc-q35 names older than 2.0 were dropped by QEMU 10; newer
// explicit names and every other value pass through so validation can accept
// or reject them unchanged.
func canonicalMachine(machine string) string {
	if matches := machineGenericAliasPattern.FindStringSubmatch(machine); matches != nil {
		return matches[1]
	}
	matches := machineVersionedPattern.FindStringSubmatch(machine)
	if matches == nil {
		return machine
	}
	major, err := strconv.Atoi(matches[2])
	if err != nil || major >= firstShippedMachineMajor {
		return machine
	}
	if matches[1] == "pc-i440fx" {
		return "pc"
	}

	return "q35"
}

// canonicalNIC maps a legacy NIC value to its canonical short name. Unknown
// values pass through so validation can reject them.
func canonicalNIC(nic string) string {
	if canonical, ok := nicAliases[nic]; ok {
		return canonical
	}

	return nic
}

func validAccel(accel string) bool {
	for _, supported := range supportedAccels {
		if accel == supported {
			return true
		}
	}

	return accel == ""
}

func validDiskInterface(iface string) bool {
	for _, supported := range supportedDiskInterfaces {
		if iface == supported {
			return true
		}
	}

	return false
}

func hasControlChars(value string) bool {
	for _, char := range value {
		if char < 0x20 || char == 0x7f {
			return true
		}
	}

	return false
}

func (s Spec) TapName(index int) string {
	return network.TapName(s.LabID, s.ID, index)
}

func (s Spec) MAC(index int) string {
	sum := sha256.Sum256([]byte(s.ID + ":mac:" + fmt.Sprint(index)))

	return fmt.Sprintf("52:54:%02x:%02x:%02x:%02x", sum[0], sum[1], sum[2], sum[3])
}

func validateImagePath(path string, roots []string, extension string) error {
	resolved, err := resolveFile(path, roots)
	if err != nil {
		return err
	}
	if strings.ToLower(filepath.Ext(resolved)) != extension {
		return fmt.Errorf("image must have %s extension", extension)
	}

	return nil
}

func validateFilePath(path string, roots []string) error {
	_, err := resolveFile(path, roots)

	return err
}

// resolveFile canonicalizes path and accepts it only when its resolved target
// lands under one of the approved roots. Roots that do not exist are inert, as
// long as at least one root resolves; prefix lookalikes never match because the
// comparison is relative to the resolved root.
func resolveFile(path string, roots []string) (string, error) {
	absolute, err := filepath.Abs(path)
	if err != nil {
		return "", errors.New("invalid file path")
	}
	resolved, err := filepath.EvalSymlinks(absolute)
	if err != nil {
		return "", fmt.Errorf("file does not exist: %w", err)
	}
	var rootErr error
	resolvedRoots := 0
	for _, root := range roots {
		if root == "" {
			continue
		}
		resolvedRoot, err := filepath.EvalSymlinks(root)
		if err != nil {
			if rootErr == nil {
				rootErr = fmt.Errorf("image root does not exist: %w", err)
			}
			continue
		}
		resolvedRoots++
		if pathWithinRoot(resolvedRoot, resolved) {
			info, statErr := os.Stat(resolved)
			if statErr != nil || info.IsDir() {
				return "", errors.New("file path is not a regular file")
			}

			return resolved, nil
		}
	}
	if resolvedRoots == 0 && rootErr != nil {
		return "", rootErr
	}

	return "", errors.New("file path is outside the image root")
}

// pathWithinRoot reports whether target is root itself or a descendant of root.
// Both arguments must already be absolute and symlink-resolved.
func pathWithinRoot(root, target string) bool {
	relative, err := filepath.Rel(root, target)
	if err != nil {
		return false
	}

	return relative != ".." && !strings.HasPrefix(relative, ".."+string(os.PathSeparator))
}

func randomUUID() string {
	raw := make([]byte, 16)
	if _, err := rand.Read(raw); err != nil {
		return ""
	}
	raw[6] = (raw[6] & 0x0f) | 0x40
	raw[8] = (raw[8] & 0x3f) | 0x80

	return fmt.Sprintf(
		"%x-%x-%x-%x-%x",
		raw[0:4],
		raw[4:6],
		raw[6:8],
		raw[8:10],
		raw[10:16],
	)
}

// RandomHex returns a hex string of n random bytes.
func RandomHex(n int) string {
	raw := make([]byte, n)
	if _, err := rand.Read(raw); err != nil {
		return ""
	}

	return hex.EncodeToString(raw)
}
