<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
  <section v-if="tabs.length" class="console-dock">
    <div class="tabs" role="tablist">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        role="tab"
        :aria-selected="tab.id === active"
        :class="{ active: tab.id === active }"
        @click="activateConsole(tab.id)"
      >
        {{ tab.name }} <span class="kind">{{ tab.kind }}</span>
      </button>
      <button class="ghost" aria-label="Close all consoles" @click="closeAll">Close all</button>
    </div>
    <div class="console-body">
      <template v-for="tab in tabs" :key="tab.id">
        <div v-show="tab.id === active" class="console-pane">
          <button class="ghost close" aria-label="Close console" @click="closeConsole(tab.id)">×</button>
          <TelnetConsole v-if="tab.kind !== 'vnc'" :ws-path="tab.wsPath" />
          <VncConsole v-else :ws-path="tab.wsPath" :password="tab.password" />
        </div>
      </template>
    </div>
  </section>
</template>

<script setup>
import { consoleState, closeConsole, activateConsole } from '../stores/consoles'
import TelnetConsole from '../consoles/TelnetConsole.vue'
import VncConsole from '../consoles/VncConsole.vue'

const { tabs, active } = consoleState
function closeAll() {
  for (const tab of [...tabs]) closeConsole(tab.id)
}
</script>
