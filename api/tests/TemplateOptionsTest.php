<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Templates\QemuOptions;
use TeraLab\Api\Templates\TemplateRepository;

final class TemplateOptionsTest extends TestCase
{
    private string $root;
    private string $dataDir;
    private Config $config;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-tplopts-' . bin2hex(random_bytes(6));
        $this->dataDir = $this->root . '/data';
        mkdir($this->dataDir . '/templates', 0770, true);
        $this->config = Config::forTests($this->root, $this->dataDir);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testEveryBuiltinTemplateHasValidQemuOptions(): void
    {
        // The repository validates a template every time it loads it, so a
        // value that is no longer allowed makes the template disappear.
        $repository = new TemplateRepository(Config::forTests(dirname(__DIR__, 2), $this->dataDir));
        $files = glob(dirname(__DIR__, 2) . '/api/templates/builtin/*.yaml') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $id = basename($file, '.yaml');
            self::assertNotSame([], (array) $repository->find($id), $id . ' must load');
        }
    }

    public function testStructuredOptionsAreMappedToTheEngineSpec(): void
    {
        $spec = QemuOptions::spec([
            'qemu_nic' => 'vmxnet3',
            'qemu_machine' => 'pc',
            'qemu_arch' => 'x86_64',
            'qemu_cpu' => 'host',
            'qemu_accel' => 'kvm',
            'qemu_disk_interface' => 'virtio',
            'qemu_options' => '-no-acpi',
            'description' => 'ignored',
        ]);

        self::assertSame([
            'nic' => 'vmxnet3',
            'machine' => 'pc',
            'arch' => 'x86_64',
            'cpu_model' => 'host',
            'accel' => 'kvm',
            'disk_interface' => 'virtio',
            'extra_options' => '-no-acpi',
        ], $spec);
    }

    public function testLegacyValuesAreAcceptedAndNormalised(): void
    {
        $repository = new TemplateRepository($this->config);
        $template = $repository->saveCustom('legacy', $this->yaml('legacy', [
            'qemu_nic' => 'virtio-net-pci',
            'qemu_arch' => 'i386',
        ]));

        self::assertSame('virtio-net-pci', $template['options']['qemu_nic']);
        self::assertSame('i386', $template['options']['qemu_arch']);
        $spec = QemuOptions::spec($template['options']);
        self::assertSame('virtio', $spec['nic']);
        self::assertSame('x86_64', $spec['arch']);
    }

    public function testInvalidStructuredValueIsRejectedOnSave(): void
    {
        $repository = new TemplateRepository($this->config);
        foreach ([
            ['qemu_nic' => 'bogus'],
            ['qemu_nic' => 'e1000; rm -rf /'],
            ['qemu_arch' => 'i686'],
            ['qemu_arch' => 'aarch64'],
            ['qemu_accel' => 'hax'],
            ['qemu_disk_interface' => 'nvme'],
            ['qemu_machine' => 'pc type=malicious'],
            ['qemu_cpu' => str_repeat('a', 65)],
        ] as $options) {
            $id = 'bad-' . substr(hash('sha256', (string) json_encode($options, JSON_THROW_ON_ERROR)), 0, 8);
            try {
                $repository->saveCustom($id, $this->yaml($id, $options));
                self::fail('expected a 422 for ' . (string) json_encode($options, JSON_THROW_ON_ERROR));
            } catch (HttpException $exception) {
                self::assertSame(422, $exception->status, (string) json_encode($options, JSON_THROW_ON_ERROR));
            }
        }
    }

    public function testCpuModelWithFeatureListIsAccepted(): void
    {
        $repository = new TemplateRepository($this->config);
        $template = $repository->saveCustom('features', $this->yaml('features', [
            'qemu_cpu' => 'qemu64,+ssse3,+sse4.1',
        ]));

        self::assertSame('qemu64,+ssse3,+sse4.1', $template['options']['qemu_cpu']);
        self::assertSame('qemu64,+ssse3,+sse4.1', QemuOptions::spec($template['options'])['cpu_model']);
    }

    public function testAarch64IsRejectedWithAClearMessage(): void
    {
        $repository = new TemplateRepository($this->config);
        try {
            $repository->saveCustom('arm', $this->yaml('arm', ['qemu_arch' => 'aarch64']));
            self::fail('aarch64 must be rejected by this x86_64-only build');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertStringContainsString('x86_64 guests only', $exception->getMessage());
        }
    }

    public function testEngineOwnedAndAllowListedRawOptionsAreAccepted(): void
    {
        $raw = '-machine type=pc,accel=kvm -cpu host -nodefaults -no-user-config '
            . '-nodefconfig -nographic -no-reboot -rtc base=utc -display none -vga std '
            . '-serial mon:stdio -boot order=c -kernel /boot/vmlinuz -initrd /boot/initrd '
            . '-append root=/dev/sda -netdev tap,id=n0 -drive if=virtio '
            . '-no-acpi -no-hpet -no-shutdown -smbios type=1,product=test '
            . '-global kvm-pit.lost_tick_policy=discard -icount 1 -realtime mlock=off '
            . '-usbdevice tablet';

        $repository = new TemplateRepository($this->config);
        $template = $repository->saveCustom('legacy-ok', $this->yaml('legacy-ok', ['qemu_options' => $raw]));

        self::assertSame($raw, $template['options']['qemu_options']);
    }

    public function testTooManyRawExtrasAreRejected(): void
    {
        $raw = implode(' ', array_fill(0, 17, '-no-acpi'));

        try {
            (new TemplateRepository($this->config))->saveCustom('too-many', $this->yaml('too-many', ['qemu_options' => $raw]));
            self::fail('more than 16 extras must be rejected');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertStringContainsString('at most 16', $exception->getMessage());
        }
    }

    public function testRejectedRawOptionNamesTheToken(): void
    {
        try {
            (new TemplateRepository($this->config))->saveCustom(
                'named',
                $this->yaml('named', ['qemu_options' => '-device vfio-pci,host=0000:00:01.0']),
            );
            self::fail('a -device token must be rejected');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertStringContainsString('-device', $exception->getMessage());
        }
    }

    public function testMachineValuesUseTheEngineGrammar(): void
    {
        foreach (['pc', 'q35', 'pc-1.0', 'q35-1.0', 'pc-i440fx-1.0', 'pc-i440fx-2.0', 'pc-i440fx-7.2'] as $machine) {
            self::assertNotSame('', QemuOptions::value('qemu_machine', $machine), $machine . ' must be accepted');
        }

        foreach (['bogus', 'pc 1.0', 'microvm'] as $machine) {
            try {
                QemuOptions::value('qemu_machine', $machine);
                self::fail('machine ' . $machine . ' must be rejected');
            } catch (HttpException $exception) {
                self::assertSame(422, $exception->status);
            }
        }
    }

    public function testLegacyMachineAliasesAreCanonicalised(): void
    {
        $canonical = [
            'pc' => 'pc',
            'q35' => 'q35',
            'pc-1.0' => 'pc',
            'q35-1.0' => 'q35',
            'pc-i440fx-1.0' => 'pc',
            'pc-q35-1.0' => 'q35',
            'pc-i440fx-2.0' => 'pc-i440fx-2.0',
            'pc-i440fx-7.2' => 'pc-i440fx-7.2',
            'pc-q35-7.2' => 'pc-q35-7.2',
        ];

        foreach ($canonical as $machine => $expected) {
            self::assertSame($expected, QemuOptions::canonicalMachine($machine), $machine);
            self::assertSame($expected, QemuOptions::value('qemu_machine', $machine), $machine);
        }
    }

    public function testLegacyMachineInTemplateSpecIsCanonicalised(): void
    {
        $repository = new TemplateRepository($this->config);
        $template = $repository->saveCustom('legacy-machine', $this->yaml('legacy-machine', [
            'qemu_machine' => 'pc-1.0',
        ]));

        // The stored template keeps the legacy value; only the engine spec is
        // canonicalised, so the raw YAML round-trips unchanged.
        self::assertSame('pc-1.0', $template['options']['qemu_machine']);
        self::assertSame('pc', QemuOptions::spec($template['options'])['machine']);
    }

    public function testVwaasQemuOptionsHaveNoEscapedQuotes(): void
    {
        $repository = new TemplateRepository(Config::forTests(dirname(__DIR__, 2), $this->dataDir));
        $template = $repository->find('vwaas');
        self::assertNotNull($template);
        $raw = $template['options']['qemu_options'] ?? '';
        self::assertIsString($raw);
        self::assertStringNotContainsString('\\"', $raw);
        self::assertStringContainsString('manufacturer="VMware Inc."', $raw);
        self::assertStringContainsString(
            'serial="VMware-12 00 11 22 33 44 55 66-77 88 99 aa bb cc dd ee"',
            $raw,
        );
    }

    public function testUnsafeRawOptionsAreRejected(): void
    {
        $repository = new TemplateRepository($this->config);
        foreach ([
            "-no-acpi\n-drive file=/etc/shadow",
            str_repeat('-no-acpi ', 100),
            '-drive file=/etc/shadow',
            '-fsdev local,path=/',
            '-device vfio-pci,host=0000:00:01.0',
            '-sandbox off',
            '-chardev file,id=x,path=/etc/shadow',
            '-virtfs local,path=/,mount_tag=host0',
            '-netdev user,id=n0',
            '-writeconfig /tmp/qemu.cfg',
            '-smbios type=1,path=/etc/passwd',
            '-drive if=virtio,file=/etc/shadow',
        ] as $raw) {
            $id = 'raw-' . substr(hash('sha256', $raw), 0, 8);
            try {
                $repository->saveCustom($id, $this->yaml($id, ['qemu_options' => $raw]));
                self::fail('expected a 422 for the raw options');
            } catch (HttpException $exception) {
                self::assertSame(422, $exception->status);
            }
        }
    }

    /**
     * @param array<string, string> $options
     */
    private function yaml(string $id, array $options): string
    {
        $lines = ['schema: 1', 'id: ' . $id, 'name: Placeholder', 'kind: qemu', 'options:'];
        foreach ($options as $key => $value) {
            $lines[] = '  ' . $key . ": '" . str_replace("'", "''", $value) . "'";
        }

        return implode("\n", $lines) . "\n";
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
