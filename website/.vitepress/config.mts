import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { type DefaultTheme, defineConfigWithTheme } from "vitepress";
import { withMermaid } from "vitepress-plugin-mermaid";
import { type DocsVersion, docsVersions } from "../scripts/versions";

/** The docs as scripts/sync-docs.ts copied them, from docs/wiki or from a version's tag or branch. */
const DOCS = join(dirname(fileURLToPath(import.meta.url)), "..", "docs");
const REPOSITORY = "https://github.com/shibbirweb/peep";
/** Where the site lives on GitHub Pages; versions other than the latest sit below it (/peep/v0.1/, /peep/next/). */
const SITE_BASE = "/peep/";

/** What the theme needs to know about versions: the version menu and the banner on older and next docs. */
export interface PeepThemeConfig extends DefaultTheme.Config {
  peep: {
    siteBase: string;
    current: DocsVersion;
    versions: DocsVersion[];
  };
}

/**
 * scripts/build-versions.ts builds every version with PEEP_DOCS_VERSION set to its id. Without it (bun run
 * dev, bun run build) this is the working tree, shown at the root as "Local".
 */
const versions = docsVersions();
const current: DocsVersion = versions.find((version) => version.id === process.env.PEEP_DOCS_VERSION) ?? {
  id: "local",
  label: "Local",
  ref: "HEAD",
  path: "",
  latest: true,
  next: false,
};

interface Manifest {
  usage: { page: string; summary: string }[];
  developer: { page: string; summary: string }[];
}

const manifest = JSON.parse(readFileSync(join(DOCS, "pages.json"), "utf8")) as Manifest;

/**
 * The sidebar groups. Pages keep the order of docs/wiki/pages.json inside a group, and a page that
 * is listed there but not here lands in "More", so a new wiki page always shows up.
 */
const GROUPS: { text: string; pages: string[] }[] = [
  {
    text: "Getting started",
    pages: ["Getting-Started", "Installation", "Version-Support", "Configuration", "The-Viewer"],
  },
  {
    text: "API reference",
    pages: ["API-Reference", "API-Values", "API-Inspecting", "API-Control", "API-Laravel"],
  },
  {
    text: "Frameworks",
    pages: ["Laravel", "Query-Debugging", "Plain-PHP", "Symfony", "Slim", "WordPress", "Global-Install"],
  },
  {
    text: "Features",
    pages: ["Mail-and-SMS", "Webhooks", "Logs", "Desktop-App", "Finding-Leftover-Calls", "AI-Agents"],
  },
  {
    text: "Help",
    pages: ["Troubleshooting"],
  },
];

/** The page's `# Title` line, or its file name when the line is missing. */
function title(page: string): string {
  const markdown = readFileSync(join(DOCS, page), "utf8");
  const name = page.split("/").pop()?.replace(/\.md$/, "") ?? page;

  return markdown.match(/^# (.+)$/m)?.[1].trim() ?? name.replaceAll("-", " ");
}

function item(page: string): DefaultTheme.SidebarItem {
  return { text: title(page), link: `/docs/${page.replace(/\.md$/, "")}` };
}

function sidebar(): DefaultTheme.SidebarItem[] {
  const name = (page: string) => page.split("/").pop()?.replace(/\.md$/, "") ?? page;
  const grouped = new Set(GROUPS.flatMap((group) => group.pages));
  const groups: DefaultTheme.SidebarItem[] = GROUPS.map((group) => ({
    text: group.text,
    items: manifest.usage.filter((entry) => group.pages.includes(name(entry.page))).map((entry) => item(entry.page)),
  }));

  const rest = manifest.usage.filter((entry) => !grouped.has(name(entry.page)));
  if (rest.length > 0) {
    groups.push({ text: "More", items: rest.map((entry) => item(entry.page)) });
  }

  groups.push({
    text: "Working on Peep",
    collapsed: true,
    items: manifest.developer.map((entry) => item(entry.page)),
  });

  return groups.filter((group) => (group.items?.length ?? 0) > 0);
}

/** The version menu: every version (same page in another version), then the release notes. */
function versionMenu(): DefaultTheme.NavItem {
  return {
    text: current.label,
    items: [
      ...versions.map((version) => ({
        component: "VersionLink",
        props: { version },
      })),
      { text: "Release notes", link: `${REPOSITORY}/releases` },
      { text: "Changelog", link: `${REPOSITORY}/blob/main/CHANGELOG.md` },
    ],
  };
}

export default withMermaid(
  defineConfigWithTheme<PeepThemeConfig>({
    title: "Peep",
    titleTemplate: ":title · Peep",
    description: "Debug any PHP app in a separate window. Values, queries, jobs, logs, exceptions, mail and SMS, live in a desktop app or your browser.",
    lang: "en-US",
    base: `${SITE_BASE}${current.path}`,
    outDir: process.env.PEEP_DOCS_OUT_DIR,
    cleanUrls: true,
    lastUpdated: true,
    srcExclude: ["README.md"],
    head: [
      ["link", { rel: "icon", type: "image/svg+xml", href: "/peep/logo.svg" }],
      ["meta", { name: "theme-color", content: "#5b5bd6" }],
      ["meta", { property: "og:type", content: "website" }],
      ["meta", { property: "og:title", content: "Peep: a debugger for PHP apps" }],
      ["meta", { property: "og:description", content: "Call peep($value) anywhere and watch values, queries, jobs, logs and exceptions arrive live in a separate window." }],
      ["meta", { property: "og:image", content: "https://shibbirweb.github.io/peep/images/viewer.png" }],
      ["meta", { name: "twitter:card", content: "summary_large_image" }],
    ],
    markdown: {
      theme: {
        light: "github-light",
        dark: "github-dark",
      },
      // PHPStan's .neon files read like YAML.
      languageAlias: {
        neon: "yaml",
      },
      config: (md) => {
        // Inline code such as `{{ mail.message_id }}` (the webhook templates) must not be read as Vue.
        const codeInline = md.renderer.rules.code_inline;
        md.renderer.rules.code_inline = (tokens, index, options, env, self) => {
          const html = codeInline ? codeInline(tokens, index, options, env, self) : self.renderToken(tokens, index, options);

          return html.replace(/^<code/, "<code v-pre");
        };
      },
    },
    themeConfig: {
      logo: "/logo.svg",
      peep: {
        siteBase: SITE_BASE,
        current,
        versions,
      },
      nav: [
        { text: "Guide", link: "/docs/usage/Getting-Started", activeMatch: "/docs/usage/(?!API)" },
        { text: "API", link: "/docs/usage/API-Reference", activeMatch: "/docs/usage/API" },
        { text: "Laravel", link: "/docs/usage/Laravel" },
        { text: "Download", link: `${REPOSITORY}/releases/latest` },
        versionMenu(),
        {
          text: "More",
          items: [
            { text: "Packagist", link: "https://packagist.org/packages/shibbirweb/peep" },
            { text: "Contributing", link: "/docs/developer/Contributing" },
            { text: "Report a bug", link: `${REPOSITORY}/issues/new?template=bug_report.yml` },
          ],
        },
      ],
      sidebar: {
        "/docs/": sidebar(),
      },
      socialLinks: [{ icon: "github", link: REPOSITORY }],
      search: {
        provider: "local",
      },
      editLink: {
        // Pages are copied from docs/wiki, and changes go into develop by pull request. VitePress sends
        // this function to the browser as source, so it cannot use REPOSITORY.
        pattern: ({ filePath }) => `https://github.com/shibbirweb/peep/edit/develop/${filePath.replace(/^docs\//, "docs/wiki/")}`,
        text: "Edit this page on GitHub",
      },
      outline: {
        level: [2, 3],
      },
      footer: {
        message: "Released under the MIT License.",
        copyright: "Copyright © MD. Shibbir Ahmed",
      },
    },
    vite: {
      optimizeDeps: {
        // Mermaid imports fastdom, a CommonJS module; pre-bundling converts it, or the dev server's pages stay blank.
        include: ["mermaid"],
      },
    },
    mermaid: {},
  }),
);
