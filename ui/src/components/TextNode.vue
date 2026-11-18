<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="text-node">{{ data.name || 'Text' }}</div>
</template>

<script setup>
defineProps({ data: { type: Object, required: true } })
</script>
