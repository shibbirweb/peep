<script setup lang="ts">
import { useData } from "vitepress";
import { computed } from "vue";
import type { PeepThemeConfig } from "../../config.mts";

const { page, theme } = useData<PeepThemeConfig>();

const latest = computed(() => theme.value.peep?.versions.find((version) => version.latest));

/** The same page in the latest version, another build: target="_self" keeps VitePress's router out of it. */
const latestHref = computed(() => `${theme.value.peep.siteBase}${page.value.relativePath.replace(/\.md$/, "")}`);
</script>

<template>
  <div v-if="latest && !theme.peep.current.latest" class="VersionBanner custom-block warning">
    <p v-if="theme.peep.current.next">
      These docs are for the next release, in beta, so their install commands add <code>:@beta</code>. For the
      version most people use, see the <a :href="latestHref" target="_self">{{ latest.label }} docs</a>.
    </p>
    <p v-else>
      These docs are for {{ theme.peep.current.label }}, an older version. The latest is
      <a :href="latestHref" target="_self">{{ latest.label }}</a>.
    </p>
  </div>
</template>

<style scoped>
.VersionBanner {
  margin: 0 0 24px;
}

.VersionBanner p {
  margin: 0;
}
</style>
