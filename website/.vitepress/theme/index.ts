import type { Theme } from "vitepress";
import DefaultTheme from "vitepress/theme";
import { h } from "vue";
import HomeShowcase from "./components/HomeShowcase.vue";
import VersionBanner from "./components/VersionBanner.vue";
import VersionLink from "./components/VersionLink.vue";
import "./custom.css";

export default {
  extends: DefaultTheme,
  Layout: () => {
    return h(DefaultTheme.Layout, null, {
      "home-hero-after": () => h(HomeShowcase),
      "doc-before": () => h(VersionBanner),
    });
  },
  enhanceApp({ app }) {
    // The version menu in config.mts refers to it by name.
    app.component("VersionLink", VersionLink);
  },
} satisfies Theme;
