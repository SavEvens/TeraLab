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
      config: raw.config || '',
      interfaces: interfaces.map((iface, port) => ({
        id: Number(iface.id ?? port),
        name: iface.name || `eth${port}`,
        type: iface.type || 'ethernet',
        network_id: String(iface.network_id || '')
      }))
    }
  }
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
    for (let index = 0; index < ports.length - 1; index += 1) {
      const from = ports[index]
      const to = ports[index + 1]
      const id = `e-${networkId}-${from.node.id}-${from.index}-${to.node.id}-${to.index}`
      edges.push({
        id,
        source: from.node.id,
        target: to.node.id,
        sourceHandle: `s-${from.index}`,
        targetHandle: `t-${to.index}`,
        data: { networkId },
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
      config: node.data.config,
      left: Math.round(node.position.x),
      top: Math.round(node.position.y),
      interfaces: node.data.interfaces.map((iface) => ({
        id: iface.id,
        name: iface.name,
        type: iface.type,
        network_id: iface.network_id
      }))
    })),
    networks: networks.map((network) => ({
      id: network.id,
      name: network.name,
      type: network.type || 'bridge'
    }))
  }
}

export function nextNetworkId(networks) {
  let max = -1
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
