// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { reactive } from 'vue'

export const toasts = reactive([])
let nextId = 1

export function notify(kind, message, timeout = 5000) {
  const id = nextId++
  toasts.push({ id, kind, message, progress: null })
  if (timeout > 0) {
    window.setTimeout(() => dismiss(id), timeout)
  }
  return id
}

export function progress(id, percent) {
  const toast = toasts.find((item) => item.id === id)
  if (toast) toast.progress = percent
}

export function dismiss(id) {
  const index = toasts.findIndex((item) => item.id === id)
  if (index >= 0) toasts.splice(index, 1)
}
