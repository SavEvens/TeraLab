<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div :class="['app-shell', { bare }]">
    <header v-if="!bare" class="topbar">
      <router-link to="/labs" class="brand">TeraLab</router-link>
      <span class="workspace-name">Lab Workspace</span>
      <div class="spacer"></div>
      <span class="badge version">v{{ version }}</span>
      <span v-if="session.user" class="user">{{ session.user.username }} · {{ session.user.role }}</span>
      <button v-if="session.user" class="ghost" @click="passwordOpen = true">Change password</button>
      <button v-if="session.user" class="ghost icon" title="Log out" aria-label="Log out" @click="doLogout">⏻</button>
    </header>

    <div v-if="!bare" class="shell-body">
      <nav class="rail" aria-label="Primary">
        <router-link to="/labs" class="rail-link">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h10M4 17h7"/><circle cx="18" cy="12" r="2"/><circle cx="14" cy="17" r="2"/></svg>
          <span>Labs</span>
        </router-link>
        <router-link to="/templates" class="rail-link">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/></svg>
          <span>Templates</span>
        </router-link>
        <router-link to="/images" class="rail-link">
          <svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/></svg>
          <span>Images</span>
        </router-link>
        <router-link to="/system" class="rail-link">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/></svg>
          <span>System</span>
        </router-link>
        <router-link to="/about" class="rail-link">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 11v5M12 8h.01"/></svg>
          <span>About</span>
        </router-link>
        <div class="rail-footer">
          <span class="dot"></span>System online<br />TeraLab {{ version }}
        </div>
      </nav>
      <main class="app-main">
        <router-view />
      </main>
    </div>

    <main v-else class="app-main is-bare">
      <router-view />
    </main>

    <PasswordChangeDialog v-if="!bare" :open="passwordOpen" @close="passwordOpen = false" />
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
import PasswordChangeDialog from './components/PasswordChangeDialog.vue'
import ToastHost from './components/ToastHost.vue'

const router = useRouter()
const route = useRoute()
const version = ref('1.6-dev')
const passwordOpen = ref(false)
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
