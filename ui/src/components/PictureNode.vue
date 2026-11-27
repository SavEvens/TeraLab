<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="picture-node">
    <img v-if="pictureSrc" :src="pictureSrc" :alt="data.name || 'Picture'" />
    <span v-else>Picture</span>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { safePictureSrc } from '../media'

const props = defineProps({ data: { type: Object, required: true } })
const pictureSrc = computed(() => safePictureSrc(props.data.image))
</script>
