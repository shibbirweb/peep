<script setup lang="ts">
import { useData } from "vitepress";
import { computed } from "vue";
import type { DocsVersion } from "../../../scripts/versions";
import type { PeepThemeConfig } from "../../config.mts";

const props = defineProps<{
  version: DocsVersion;
}>();

const { page, theme } = useData<PeepThemeConfig>();

const isCurrent = computed(() => theme.value.peep.current.path === props.version.path);

/**
 * The same page in that version. Each version is its own VitePress build, so the link starts from the
 * site root, not this build's base, and has target="_self": VitePress's router leaves links with a
 * target alone, so the browser loads the other build instead of this one routing to it.
 */
const href = computed(() => {
  const route = page.value.relativePath.replace(/(^|\/)index\.md$/, "$1").replace(/\.md$/, "");

  return `${theme.value.peep.siteBase}${props.version.path}${route}`;
});
</script>

<template>
  <div class="VersionLink">
    <a :href="href" target="_self" :class="{ active: isCurrent }">
      {{ version.label }}
      <span v-if="version.latest" class="tag">latest</span>
    </a>
  </div>
</template>

<style scoped>
/* Looks like VitePress's own menu links (VPMenuLink). */
.VersionLink a {
  display: flex;
  align-items: center;
  gap: 8px;
  border-radius: 6px;
  padding: 0 12px;
  line-height: 32px;
  font-size: 14px;
  font-weight: 500;
  color: var(--vp-c-text-1);
  white-space: nowrap;
  transition: background-color 0.25s, color 0.25s;
}

.VersionLink a:hover {
  color: var(--vp-c-brand-1);
  background-color: var(--vp-c-default-soft);
}

.VersionLink a.active {
  color: var(--vp-c-brand-1);
}

.tag {
  border-radius: 999px;
  padding: 0 8px;
  line-height: 18px;
  font-size: 11px;
  font-weight: 600;
  color: var(--vp-c-brand-1);
  background: var(--vp-c-brand-soft);
}

/* In the mobile menu the links are bigger, like VPNavScreenMenuGroupLink. */
.VPNavScreenMenuGroup .VersionLink a {
  padding: 0 0 0 12px;
  line-height: 32px;
  font-size: 14px;
}
</style>
