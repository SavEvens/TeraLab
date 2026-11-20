<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <section class="about-view">
    <div class="page-head">
      <span class="crumbs">Workspace /</span>
      <h1>About</h1>
      <span class="badge">v{{ info.version || '—' }}</span>
    </div>
    <div class="about-grid">
      <article class="stat-card">
        <span>Product</span>
        <strong>{{ info.product || 'TeraLab' }}</strong>
        <p class="hint">Network lab orchestration platform.</p>
      </article>
      <article class="stat-card">
        <span>Licence</span>
        <strong>{{ info.license || 'AGPL-3.0-or-later' }}</strong>
        <p class="hint">UNetLab-derived files remain BSD-3-Clause.</p>
      </article>
      <article class="stat-card">
        <span>Source</span>
        <a :href="info.source" target="_blank" rel="noopener">Corresponding source</a>
        <p class="hint">Required by the AGPL for network services.</p>
      </article>
      <article class="stat-card wide">
        <span>Components</span>
        <ul class="engine-list">
          <li v-for="component in info.components || []" :key="component.name">
            <span class="dot" :class="{ ok: !component.separate }"></span>
            <strong>{{ component.name }}</strong>
            <small>{{ component.license }}</small>
          </li>
        </ul>
      </article>
      <article class="stat-card wide">
        <span>No warranty</span>
        <p class="hint">
          This program is distributed in the hope that it will be useful, but
          WITHOUT ANY WARRANTY; without even the implied warranty of
          MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the AGPLv3
          for details. QEMU, Dynamips, IOL/IOUtools, VPCS, Open vSwitch, and the
          kernel are separate programs with their own licences.
        </p>
        <p class="hint">
          See <code>NOTICE</code>, <code>PROVENANCE.md</code>, and
          <code>docs/SBOM.md</code> in the source tree for the full inventory.
        </p>
      </article>
    </div>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api'

const info = ref({})

onMounted(async () => {
  try {
    info.value = await api.version()
  } catch {}
})
</script>
