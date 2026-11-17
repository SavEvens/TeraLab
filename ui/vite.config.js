// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

const apiTarget = process.env.TERALAB_UI_API || 'http://127.0.0.1:8094'
const wsTarget = process.env.TERALAB_UI_WS || 'ws://127.0.0.1:8091'

export default defineConfig({
  base: '/ui/',
  plugins: [vue()],
  server: {
    port: 5173,
    proxy: {
      '/api': { target: apiTarget, changeOrigin: true },
      '/ws': { target: wsTarget, ws: true }
    }
  },
  build: {
    outDir: 'dist',
    sourcemap: false
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.js']
  }
})
