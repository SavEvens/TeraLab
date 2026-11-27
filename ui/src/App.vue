<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div :class="['app-shell', { bare }]">
    <header v-if="!bare" class="topbar">
      <router-link to="/labs" class="brand">TeraLab</router-link>
      <div class="spacer"></div>
      <span v-if="session.user" class="user">{{ session.user.username }} · {{ session.user.role }}</span>
      <button v-if="session.user" class="ghost icon" title="Log out" aria-label="Log out" @click="doLogout">⏻</button>
    </header>

    <div v-if="!bare" class="shell-body">
      <nav class="rail" aria-label="Primary">
        <router-link to="/labs" class="rail-link">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h10M4 17h7"/><circle cx="18" cy="12" r="2"/><circle cx="14" cy="17" r="2"/></svg>
          <span>Labs</span>
        </router-link>
        <router-link to="/appliances" class="rail-link">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/></svg>
          <span>Appliances</span>
        </router-link>
        <router-link to="/system" class="rail-link">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/></svg>
          <span>System</span>
        </router-link>
        <div class="rail-footer">
          <span class="rail-footer-status">
            <span class="dot" aria-hidden="true"></span><span class="rail-footer-label">System online</span>
          </span>
          <span class="rail-footer-version"><span class="rail-footer-product">TeraLab </span>v{{ version }}</span>
        </div>
      </nav>
      <main class="app-main">
        <router-view />
      </main>
    </div>

    <main v-else class="app-main is-bare">
      <router-view />
    </main>

    <ConsoleDock />
    <ToastHost />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { session, logout } from './stores/session'
import { api } from './api'
import ConsoleDock from './components/ConsoleDock.vue'
import ToastHost from './components/ToastHost.vue'

const router = useRouter()
const route = useRoute()
const version = ref('1.6-dev')
const bare = computed(() => ['login', 'setup', 'editor'].includes(String(route.name)))

async function doLogout() {
  await logout()
  router.push({ name: 'login' })
}

onMounted(async () => {
  try {
    version.value = (await api.health()).version || version.value
  } catch {}
})
</script>
