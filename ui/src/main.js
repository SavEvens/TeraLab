// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import '@vue-flow/controls/dist/style.css'
import '@vue-flow/minimap/dist/style.css'
import '@xterm/xterm/css/xterm.css'
import './style.css'

createApp(App).use(router).mount('#app')
