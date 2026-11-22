<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->
<template>
  <section class="appliances">
    <div class="page-head">
      <h1>Appliances</h1>
      <div class="spacer"></div>
    </div>

    <div class="system-tabs" role="tablist" aria-label="Appliance sections">
      <button
        v-for="tab in tabs"
        :key="tab.value"
        role="tab"
        :aria-selected="active === tab.value"
        :class="{ active: active === tab.value }"
        @click="selectTab(tab.value)"
      >{{ tab.label }}</button>
    </div>

    <TemplatesView v-if="active === 'templates'" embedded />
    <ImagesView v-else embedded />
  </section>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import TemplatesView from './TemplatesView.vue'
import ImagesView from './ImagesView.vue'

const tabs = [
  { value: 'templates', label: 'Templates' },
  { value: 'images', label: 'Images' }
]

const route = useRoute()
const router = useRouter()

function matchTab(value) {
  const wanted = typeof value === 'string' ? value.trim().toLowerCase() : ''
  return tabs.some((tab) => tab.value === wanted) ? wanted : 'templates'
}

const active = ref(matchTab(route.query.tab))

function selectTab(tab) {
  active.value = tab
  if (route.query.tab === tab) return
  router.replace({ query: { ...route.query, tab } })
}

watch(() => route.query.tab, (value) => {
  const next = matchTab(value)
  if (next !== active.value) active.value = next
})
</script>
