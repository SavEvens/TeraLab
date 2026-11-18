// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { reactive } from 'vue'
import { api, setCsrf } from '../api'

export const session = reactive({
  ready: false,
  user: null,
  csrf: ''
})

export async function refreshSession() {
  try {
    const data = await api.me()
    session.user = data.user
    session.csrf = data.csrf_token
    setCsrf(data.csrf_token)
  } catch {
    session.user = null
    session.csrf = ''
    setCsrf('')
  } finally {
    session.ready = true
  }
}

export async function login(username, password) {
  const data = await api.login({ username, password })
  session.user = data.user
  session.csrf = data.csrf_token
  setCsrf(data.csrf_token)
  return data
}

export async function setup(username, password, email) {
  const data = await api.setup({ username, password, email })
  session.user = data.user
  session.csrf = data.csrf_token
  setCsrf(data.csrf_token)
  return data
}

export async function logout() {
  try {
    await api.logout()
  } finally {
    session.user = null
    session.csrf = ''
    setCsrf('')
  }
}
