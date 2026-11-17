<?php
# vim: syntax=php tabstop=4 softtabstop=0 noexpandtab laststatus=1 ruler

/**
 * html/templates/bigip.php
 *
 * bigip template for UNetLab.
 *
 * Modified by TeraLab contributors, 2026-11-17.
 * The modifications are licensed under AGPL-3.0-or-later.
 */
$p['qemu_arch'] = 'x86_64';
$p['qemu_options'] = '-machine type=pc,accel=kvm -serial mon:stdio -nographic -no-user-config -nodefaults -display none -vga std -rtc base=utc ';
?>
