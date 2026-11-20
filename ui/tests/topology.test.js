// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { describe, expect, it } from 'vitest'
import { buildEdges, defaultPortName, firstFreePort, nextNetworkId, nodeFromLab, portLabel, toPayload, usedNetworks } from '../src/topology'

const labNode = (id, network) => ({
  id: String(id),
  type: 'lab',
  position: { x: 0, y: 0 },
  data: {
    id: String(id),
    name: `N${id}`,
    kind: 'qemu',
    template: 'linux',
    icon: '',
    image: '',
    iso: '',
    console: 'telnet',
    cpu: 1,
    ram: 512,
    uuid: '',
    platform: '',
    nic: '',
    serial: 0,
    config: '',
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
      nic: 'virtio-net-pci',
      serial: 2,
      cpu: 2,
      ram: 1024,
      left: 120,
      top: 80,
      interfaces: [{ id: 0, network_id: '3' }]
    }, 0)
    expect(node.id).toBe('7')
    expect(node.position).toEqual({ x: 120, y: 80 })
    expect(node.data.interfaces[0].network_id).toBe('3')
    expect(node.data.nic).toBe('virtio-net-pci')
    expect(node.data.serial).toBe(2)
  })

  it('builds a chain of edges for a shared network', () => {
    const nodes = [labNode(1, '0'), labNode(2, '0'), labNode(3, '0')]
    const built = buildEdges(nodes)
    expect(built.edges).toHaveLength(2)
    expect(built.edges[0].sourceHandle).toBe('node-s')
    expect(built.networks.get(built.edges[0].id)).toBe('0')
  })

  it('serializes nodes/networks for the API', () => {
    const payload = toPayload('Lab', 4, [labNode(1, '0')], [{ id: '0', name: 'Net-1' }])
    expect(payload.revision).toBe(4)
    expect(payload.nodes[0].left).toBe(0)
    expect(payload.nodes[0].interfaces[0].network_id).toBe('0')
    expect(payload.networks[0].name).toBe('Net-1')
    expect(payload.nodes[0].nic).toBe('')
    expect(payload.nodes[0].serial).toBe(0)
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

  it('finds a free node port for node-level links', () => {
    const node = labNode(1, '0')
    expect(firstFreePort(node)).toBeNull()
    node.data.interfaces.push({ id: 1, name: 'eth1', type: 'ethernet', network_id: '' })
    expect(firstFreePort(node)).toEqual(node.data.interfaces[1])
    expect(firstFreePort(node, '0')).toEqual(node.data.interfaces[1])
  })

  it('allocates the next numeric network and reports used ids', () => {
    expect(nextNetworkId([{ id: '0' }, { id: '4' }])).toBe('5')
    expect([...usedNetworks([labNode(1, '2')])]).toEqual(['2'])
  })

  it('labels engine interfaces compactly', () => {
    const iol = labNode(1, '0')
    iol.data.kind = 'iol'
    iol.data.interfaces = [0, 1, 4].map((index) => ({ id: index, name: `Ethernet${Math.floor(index / 4)}/${index % 4}`, network_id: '' }))
    expect(portLabel(iol, 0)).toBe('e0/0')
    expect(portLabel(iol, 1)).toBe('e0/1')
    expect(portLabel(iol, 2)).toBe('e1/0')

    const imported = labNode(2, '')
    imported.data.interfaces[0].name = 'Ethernet1/2'
    expect(portLabel(imported, 0)).toBe('e1/2')
    expect(portLabel(labNode(3, ''), 0)).toBe('eth0')

    expect(defaultPortName('iol', 5)).toBe('Ethernet1/1')
    expect(defaultPortName('qemu', 5)).toBe('eth5')
  })

  it('carries interface labels into built edges', () => {
    const from = labNode(1, '0')
    from.data.kind = 'iol'
    from.data.interfaces[0].name = 'Ethernet0/2'
    const to = labNode(2, '0')
    to.data.interfaces[0].name = 'eth7'
    const built = buildEdges([from, to])
    expect(built.edges[0].type).toBe('link')
    expect(built.edges[0].data.sourcePort).toBe('e0/2')
    expect(built.edges[0].data.targetPort).toBe('eth7')
  })
})
