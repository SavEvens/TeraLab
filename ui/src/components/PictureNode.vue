<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="picture-node">
    <img v-if="data.image" :src="data.image" :alt="data.name || 'Picture'" />
    <span v-else>Picture</span>
  </div>
</template>

<script setup>
defineProps({ data: { type: Object, required: true } })
</script>
