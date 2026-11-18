// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { describe, expect, it } from 'vitest'
import { buildEdges, nextNetworkId, nodeFromLab, toPayload, usedNetworks } from '../src/topology'

const labNode = (id, network) => ({
  id: String(id),
  type: 'lab',
  position: { x: 0, y: 0 },
  data: {
    id: String(id),
    name: `N${id}`,
    kind: 'qemu',
    template: 'linux',
    image: '',
    iso: '',
    console: 'telnet',
    cpu: 1,
    ram: 512,
    uuid: '',
    platform: '',
    interfaces: [{ id: 0, name: 'eth0', type: 'ethernet', network_id: network }]
  }
})

describe('topology helpers', () => {
  it('maps .unl nodes with positions and interfaces', () => {
    const node = nodeFromLab({
      id: '7',
      name: 'R1',
      type: 'qemu',
      image: 'base.qcow2',
      cpu: 2,
      ram: 1024,
      left: 120,
      top: 80,
      interfaces: [{ id: 0, network_id: '3' }]
    }, 0)
    expect(node.id).toBe('7')
    expect(node.position).toEqual({ x: 120, y: 80 })
    expect(node.data.interfaces[0].network_id).toBe('3')
  })

  it('builds a chain of edges for a shared network', () => {
    const nodes = [labNode(1, '0'), labNode(2, '0'), labNode(3, '0')]
    const built = buildEdges(nodes)
    expect(built.edges).toHaveLength(2)
    expect(built.edges[0].sourceHandle).toBe('s-0')
    expect(built.networks.get(built.edges[0].id)).toBe('0')
  })

  it('serializes nodes/networks for the API', () => {
    const payload = toPayload('Lab', 4, [labNode(1, '0')], [{ id: '0', name: 'Net-1' }])
    expect(payload.revision).toBe(4)
    expect(payload.nodes[0].left).toBe(0)
    expect(payload.nodes[0].interfaces[0].network_id).toBe('0')
    expect(payload.networks[0].name).toBe('Net-1')
  })

  it('handles 200 nodes within an interactive budget', () => {
    const nodes = Array.from({ length: 200 }, (_, index) => {
      const node = labNode(index + 1, String(index))
      node.data.kind = 'qemu'
      node.position = { x: (index % 20) * 200, y: Math.floor(index / 20) * 120 }
      return node
    })
    const start = performance.now()
    const payload = toPayload('Large', 1, nodes, nodes.map((node, index) => ({ id: String(index), name: `Net-${index}` })))
    buildEdges(nodes)
    const elapsed = performance.now() - start
    expect(payload.nodes).toHaveLength(200)
    expect(elapsed).toBeLessThan(250)
  })

  it('allocates the next numeric network and reports used ids', () => {
    expect(nextNetworkId([{ id: '0' }, { id: '4' }])).toBe('5')
    expect([...usedNetworks([labNode(1, '2')])]).toEqual(['2'])
  })
})
