<script setup lang="ts">
import { useData, withBase } from "vitepress";
import { ref } from "vue";

const { isDark } = useData();

const shots = [
  { key: "viewer", label: "Cards", image: "/images/viewer.png", dark: "/images/viewer-dark.png", alt: "The Peep viewer with cards from a shop app, grouped by request" },
  { key: "queries", label: "Queries", image: "/images/query-switches.png", alt: "Query debugging switches in the viewer toolbar" },
  { key: "mail", label: "Mail", image: "/images/mail.png", alt: "The mail inbox with an HTML mail" },
  { key: "sms", label: "SMS", image: "/images/sms.png", alt: "The SMS inbox with a thread per number" },
  { key: "webhooks", label: "Webhooks", image: "/images/webhooks.png", alt: "The webhook simulator with a Mailgun rule" },
  { key: "logs", label: "Logs", image: "/images/logs.png", alt: "Log files as colored entries with the stack of an exception" },
];

const active = ref(shots[0].key);
</script>

<template>
  <section class="showcase">
    <div class="showcase-tabs" role="tablist" aria-label="Screens of the viewer">
      <button
        v-for="shot in shots"
        :key="shot.key"
        type="button"
        role="tab"
        :aria-selected="active === shot.key"
        :class="{ active: active === shot.key }"
        @click="active = shot.key"
      >
        {{ shot.label }}
      </button>
    </div>
    <div class="showcase-window">
      <div class="showcase-bar" aria-hidden="true">
        <span></span>
        <span></span>
        <span></span>
      </div>
      <template v-for="shot in shots" :key="shot.key">
        <img
          v-show="active === shot.key"
          :src="withBase(isDark && shot.dark ? shot.dark : shot.image)"
          :alt="shot.alt"
          :loading="shot.key === 'viewer' ? 'eager' : 'lazy'"
        />
      </template>
    </div>
  </section>
</template>
