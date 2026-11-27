// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { reactive } from 'vue'
import { api } from '../api'

export const consoleState = reactive({
  tabs: [],
  active: null
})

let nextId = 1

export async function openConsole(labId, node, name) {
  const payload = await api.labNodeConsole(labId, node.id)
  const data = payload.data
  const existing = consoleState.tabs.find((tab) => tab.nodeId === node.id && tab.labId === labId)
  if (existing) {
    consoleState.active = existing.id
    return existing
  }
  const tab = {
    id: nextId++,
    labId,
    nodeId: node.id,
    name: name || node.name || node.id,
    kind: data.kind,
    wsPath: data.ws_path,
    password: data.password || ''
  }
  consoleState.tabs.push(tab)
  consoleState.active = tab.id
  return tab
}

export function closeConsole(id) {
  const index = consoleState.tabs.findIndex((tab) => tab.id === id)
  if (index >= 0) consoleState.tabs.splice(index, 1)
  if (consoleState.active === id) {
    consoleState.active = consoleState.tabs[0]?.id ?? null
  }
}

export function activateConsole(id) {
  consoleState.active = id
}
