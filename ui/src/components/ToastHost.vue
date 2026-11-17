<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <div class="toasts" aria-live="polite">
    <div v-for="toast in toasts" :key="toast.id" :class="['toast', toast.kind]">
      <span>{{ toast.message }}</span>
      <div v-if="toast.progress !== null" class="bar"><i :style="{ width: toast.progress + '%' }"></i></div>
      <button class="ghost" aria-label="Dismiss" @click="dismiss(toast.id)">×</button>
    </div>
  </div>
</template>

<script setup>
import { toasts, dismiss } from '../stores/toasts'
</script>
