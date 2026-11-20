// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

export function nodeFromLab(raw, index) {
  const interfaces = Array.isArray(raw.interfaces) ? raw.interfaces : []
  return {
    id: String(raw.id),
    type: 'lab',
    position: {
      x: raw.left || 80 + (index % 4) * 220,
      y: raw.top || 80 + Math.floor(index / 4) * 140
    },
    data: {
      id: String(raw.id),
      name: raw.name || raw.id,
      kind: raw.type || 'qemu',
      template: raw.template || '',
      icon: raw.icon || '',
      image: raw.image || '',
      iso: raw.iso || '',
      console: raw.console || 'telnet',
      cpu: raw.cpu || 1,
      ram: raw.ram || 512,
      uuid: raw.uuid || '',
      platform: raw.platform || '',
      nic: raw.nic || '',
      serial: raw.serial || 0,
      nvram: Number(raw.nvram || 0),
      delay: Number(raw.delay || 0),
      sat: Number.isInteger(raw.sat) ? raw.sat : -1,
      config: raw.config || '',
      extraXml: Array.isArray(raw.extra_xml) ? raw.extra_xml : [],
      attributes: raw.attributes && typeof raw.attributes === 'object' ? raw.attributes : {},
      interfaces: interfaces.map((iface, port) => ({
        id: Number(iface.id ?? port),
        name: iface.name || defaultPortName(raw.type || 'qemu', port),
        type: iface.type || 'ethernet',
        network_id: String(iface.network_id || ''),
        attributes: iface.attributes && typeof iface.attributes === 'object' ? iface.attributes : {}
      }))
    }
  }
}

// Interface names used by the engine for a freshly created port.
export function defaultPortName(kind, index) {
  if (kind === 'iol' || kind === 'dynamips') {
    return `Ethernet${Math.floor(index / 4)}/${index % 4}`
  }

  return `eth${index}`
}

// Compact interface label shown in dialogs and on link ends (e0/0, e0/1…).
export function portLabel(node, index) {
  const iface = node?.data?.interfaces?.[index]
  const name = String(iface?.name || '')
  const ethernet = /^ethernet(\d+)\/(\d+)$/i.exec(name)
  if (ethernet) return `e${ethernet[1]}/${ethernet[2]}`
  if (/^e\d+\/\d+$/i.test(name)) return name.toLowerCase()
  if (name && !/^eth\d+$/.test(name)) return name
  if (['iol', 'dynamips'].includes(node?.data?.kind)) {
    return `e${Math.floor(index / 4)}/${index % 4}`
  }

  return name || `e${index}`
}

export function buildEdges(nodes) {
  const byNetwork = new Map()
  for (const node of nodes) {
    node.data.interfaces.forEach((iface, index) => {
      if (!iface.network_id) return
      if (!byNetwork.has(iface.network_id)) byNetwork.set(iface.network_id, [])
      byNetwork.get(iface.network_id).push({ node, index })
    })
  }
  const edges = []
  const networks = new Map()
  for (const [networkId, ports] of byNetwork.entries()) {
    if (ports.length < 2) continue
    // Draw shared segments in visual order so the chain follows the canvas
    // instead of crossing over nodes (node ids are unrelated to layout).
    const ordered = [...ports].sort((left, right) => {
      const x = (left.node.position?.x ?? 0) - (right.node.position?.x ?? 0)
      if (x !== 0) return x
      const y = (left.node.position?.y ?? 0) - (right.node.position?.y ?? 0)

      return y !== 0 ? y : left.index - right.index
    })
    for (let index = 0; index < ordered.length - 1; index += 1) {
      const from = ordered[index]
      const to = ordered[index + 1]
      const id = `e-${networkId}-${from.node.id}-${from.index}-${to.node.id}-${to.index}`
      edges.push({
        id,
        source: from.node.id,
        target: to.node.id,
        sourceHandle: 'node-s',
        targetHandle: 'node-t',
        data: {
          networkId,
          sourcePort: portLabel(from.node, from.index),
          targetPort: portLabel(to.node, to.index)
        },
        type: 'link',
        style: { stroke: '#5b8cff', strokeWidth: 2 }
      })
      networks.set(id, networkId)
    }
  }

  return { edges, networks }
}

export function toPayload(name, revision, nodes, networks) {
  return {
    name,
    revision,
    nodes: nodes.map((node) => ({
      id: node.data.id,
      name: node.data.name,
      type: node.data.kind,
      template: node.data.template,
      icon: node.data.icon,
      image: node.data.image,
      iso: node.data.iso,
      console: node.data.console,
      cpu: node.data.cpu,
      ram: node.data.ram,
      uuid: node.data.uuid,
      platform: node.data.platform,
      nic: node.data.nic,
      serial: node.data.serial,
      nvram: node.data.nvram || 0,
      delay: node.data.delay || 0,
      sat: Number.isInteger(node.data.sat) ? node.data.sat : -1,
      config: node.data.config,
      extra_xml: node.data.extraXml || [],
      attributes: node.data.attributes || {},
      left: Math.round(node.position.x),
      top: Math.round(node.position.y),
      interfaces: node.data.interfaces.map((iface) => ({
        id: iface.id,
        name: iface.name,
        type: iface.type,
        network_id: iface.network_id,
        attributes: iface.attributes || {}
      }))
    })),
    networks: networks.map((network) => ({
      id: network.id,
      name: network.name,
      type: network.type || 'bridge',
      left: network.left || 0,
      top: network.top || 0,
      attributes: network.attributes || {}
    }))
  }
}

export function firstFreePort(node, preferredNetwork = '') {
  const interfaces = Array.isArray(node?.data?.interfaces) ? node.data.interfaces : []
  const free = interfaces.find((iface) => !iface.network_id)
  if (free) return free
  if (preferredNetwork !== '') {
    return interfaces.find((iface) => iface.network_id === preferredNetwork) || null
  }

  return null
}

export function nextNetworkId(networks) {
  let max = 0
  for (const network of networks) {
    const numeric = Number(network.id)
    if (Number.isInteger(numeric)) max = Math.max(max, numeric)
  }

  return String(max + 1)
}

export function usedNetworks(nodes) {
  const used = new Set()
  for (const node of nodes) {
    for (const iface of node.data.interfaces) {
      if (iface.network_id) used.add(String(iface.network_id))
    }
  }

  return used
}
