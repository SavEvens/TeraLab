<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <header class="topbar">
    <router-link to="/labs" class="brand">TeraLab</router-link>
    <nav>
      <router-link to="/labs">Labs</router-link>
      <a href="/about.php" target="_blank" rel="noopener">About</a>
    </nav>
    <div class="spacer"></div>
    <span v-if="session.user" class="user">{{ session.user.username }} ({{ session.user.role }})</span>
    <button v-if="session.user" class="ghost" @click="doLogout">Log out</button>
  </header>
  <main class="app-main">
    <router-view />
  </main>
  <ConsoleDock />
  <ToastHost />
</template>

<script setup>
import { useRouter } from 'vue-router'
import { session, logout } from './stores/session'
import ConsoleDock from './components/ConsoleDock.vue'
import ToastHost from './components/ToastHost.vue'

const router = useRouter()
async function doLogout() {
  await logout()
  router.push({ name: 'login' })
}
</script>
