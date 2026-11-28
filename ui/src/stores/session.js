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

function applySession(data) {
  session.user = data.user
  session.csrf = data.csrf_token
  setCsrf(data.csrf_token)
  return data
}

export async function login(username, password, turnstileToken = '') {
  const payload = { username, password }
  if (turnstileToken) payload['cf-turnstile-response'] = turnstileToken
  return applySession(await api.login(payload))
}

// Demo entry mints an ephemeral guest identity; the API returns the same
// session shape as a credential login.
export async function guestLogin(turnstileToken = '') {
  const payload = turnstileToken ? { 'cf-turnstile-response': turnstileToken } : {}
  return applySession(await api.guest(payload))
}

export async function setup(username, password, email, setupToken = '') {
  return applySession(await api.setup({ username, password, email }, setupToken))
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
