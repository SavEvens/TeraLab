// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { createRouter, createWebHistory } from 'vue-router'
import { session, refreshSession } from './stores/session'

const routes = [
  { path: '/', redirect: '/labs' },
  { path: '/login', name: 'login', component: () => import('./views/LoginView.vue'), meta: { public: true } },
  { path: '/setup', name: 'setup', component: () => import('./views/SetupView.vue'), meta: { public: true } },
  { path: '/labs', name: 'labs', component: () => import('./views/LabsView.vue') },
  { path: '/labs/:id', name: 'editor', component: () => import('./views/EditorView.vue'), props: true }
]

const router = createRouter({
  history: createWebHistory('/ui/'),
  routes
})

router.beforeEach(async (to) => {
  if (!session.ready) await refreshSession()
  if (to.meta.public) {
    if (session.user && (to.name === 'login' || to.name === 'setup')) return { name: 'labs' }
    return true
  }
  if (!session.user) return { name: 'login', query: { next: to.fullPath } }
  return true
})

export default router
