// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

// Lazy loader for the Cloudflare Turnstile browser script. The widget is
// rendered explicitly (render=explicit) so the login view controls exactly
// when it appears. A failed load is kept rejected rather than retried in a
// loop: callers fail closed and ask the visitor to reload.
const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'

let loader = null

export function loadTurnstile() {
  if (typeof window !== 'undefined' && window.turnstile) return Promise.resolve(window.turnstile)
  if (loader) return loader
  loader = new Promise((resolve, reject) => {
    if (typeof document === 'undefined') {
      reject(new Error('Turnstile is unavailable in this environment.'))
      return
    }
    const script = document.createElement('script')
    script.src = SCRIPT_URL
    script.async = true
    script.defer = true
    script.dataset.teralabTurnstile = '1'
    script.onload = () => {
      if (window.turnstile) resolve(window.turnstile)
      else reject(new Error('Turnstile did not initialise.'))
    }
    script.onerror = () => reject(new Error('Turnstile could not be loaded.'))
    document.head.appendChild(script)
  })
  return loader
}
