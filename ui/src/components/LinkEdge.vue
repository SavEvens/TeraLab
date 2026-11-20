<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <BaseEdge :id="id" :path="path" :style="style" :marker-end="markerEnd" :interaction-width="18" />
  <EdgeLabelRenderer>
    <div
      v-if="data?.sourcePort"
      class="link-port-tag"
      :style="tagStyle(sourcePoint, targetPoint)"
      :data-source-port="data.sourcePort"
    >{{ data.sourcePort }}</div>
    <div
      v-if="data?.targetPort"
      class="link-port-tag"
      :style="tagStyle(targetPoint, sourcePoint)"
      :data-target-port="data.targetPort"
    >{{ data.targetPort }}</div>
  </EdgeLabelRenderer>
</template>

<script setup>
import { computed } from 'vue'
import { BaseEdge, EdgeLabelRenderer, getStraightPath, useVueFlow } from '@vue-flow/core'

const props = defineProps({
  id: { type: String, default: '' },
  sourceX: { type: Number, default: 0 },
  sourceY: { type: Number, default: 0 },
  targetX: { type: Number, default: 0 },
  targetY: { type: Number, default: 0 },
  sourceNode: { type: Object, default: null },
  targetNode: { type: Object, default: null },
  data: { type: Object, default: () => ({}) },
  style: { type: [Object, String], default: undefined },
  markerEnd: { type: String, default: undefined }
})

const { viewport } = useVueFlow()

// Pull the endpoints onto the node body so links do not stop at the node box
// edge (the rendered icon is narrower than the measured node).
const INSET_X = 22
const INSET_Y = 10

function box(node) {
  const width = node?.dimensions?.width || 96
  const height = node?.dimensions?.height || 86
  const x = node?.computedPosition?.x ?? 0
  const y = node?.computedPosition?.y ?? 0

  return { cx: x + width / 2, cy: y + height / 2, halfW: Math.max(1, width / 2 - INSET_X), halfH: Math.max(1, height / 2 - INSET_Y) }
}

function borderPoint(node, towardX, towardY) {
  if (!node?.dimensions?.width) return null
  const { cx, cy, halfW, halfH } = box(node)
  const dx = towardX - cx
  const dy = towardY - cy
  if (dx === 0 && dy === 0) return { x: cx, y: cy }
  const scaleX = dx === 0 ? Number.POSITIVE_INFINITY : halfW / Math.abs(dx)
  const scaleY = dy === 0 ? Number.POSITIVE_INFINITY : halfH / Math.abs(dy)
  const scale = Math.min(scaleX, scaleY)

  return { x: cx + dx * scale, y: cy + dy * scale }
}

const sourcePoint = computed(() => {
  const target = props.targetNode?.dimensions?.width ? box(props.targetNode) : null
  const point = target ? borderPoint(props.sourceNode, target.cx, target.cy) : null

  return point || { x: props.sourceX, y: props.sourceY }
})

const targetPoint = computed(() => {
  const source = props.sourceNode?.dimensions?.width ? box(props.sourceNode) : null
  const point = source ? borderPoint(props.targetNode, source.cx, source.cy) : null

  return point || { x: props.targetX, y: props.targetY }
})

const path = computed(() => getStraightPath({
  sourceX: sourcePoint.value.x,
  sourceY: sourcePoint.value.y,
  targetX: targetPoint.value.x,
  targetY: targetPoint.value.y
})[0])

function tagStyle(from, toward) {
  const dx = toward.x - from.x
  const dy = toward.y - from.y
  const length = Math.hypot(dx, dy) || 1
  const ux = dx / length
  const uy = dy / length
  // Labels keep a constant screen size, so their offset is scaled by zoom too.
  const zoom = viewport.value?.zoom || 1
  const along = 22 / zoom
  const perpendicular = 10 / zoom
  const x = from.x + ux * along - uy * perpendicular
  const y = from.y + uy * along + ux * perpendicular

  return {
    position: 'absolute',
    transform: `translate(-50%, -50%) translate(${x}px, ${y}px) scale(${1 / zoom})`
  }
}
</script>
