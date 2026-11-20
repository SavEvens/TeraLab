<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

$version = trim((string) @file_get_contents(dirname(__DIR__) . '/TERALAB_VERSION'));
if ($version === '') {
    $version = '1.5.4';
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>TeraLab <?= htmlspecialchars($version, ENT_QUOTES, 'UTF-8') ?> — About</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>
<h1>TeraLab <?= htmlspecialchars($version, ENT_QUOTES, 'UTF-8') ?></h1>
<p>TeraLab is free software licensed under the GNU Affero General Public License,
version 3 or later (AGPL-3.0-or-later).</p>
<p>Corresponding source code:
<a href="https://github.com/SavEvens/TeraLab">https://github.com/SavEvens/TeraLab</a>.
If you interact with a modified TeraLab service over a network, you are entitled
to the corresponding source of that version.</p>
<p>This program is distributed in the hope that it will be useful, but
WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
FITNESS FOR A PARTICULAR PURPOSE. See the AGPL for details.</p>
<h2>Components</h2>
<ul>
<li>TeraLab: AGPL-3.0-or-later</li>
<li>UNetLab-derived files: BSD-3-Clause (see NOTICE and PROVENANCE.md)</li>
<li>QEMU: GPL-2.0, separate program</li>
<li>Dynamips: GPL-2.0, separate package and program</li>
<li>IOUtools/IOL: separate user-supplied components; IOL images are not
redistributed</li>
<li>VPCS: BSD-3-Clause, separate program</li>
<li>Open vSwitch (optional): Apache-2.0, separate program</li>
<li>PHP dependencies: MIT/BSD (see docs/SBOM.md and composer.lock)</li>
</ul>
<p>See <a href="https://github.com/SavEvens/TeraLab/blob/main/docs/SBOM.md">docs/SBOM.md</a> and the repository
<code>NOTICE</code>, <code>PROVENANCE.md</code>, and <code>LICENSES/</code> for
the full inventory.</p>
</body>
</html>
