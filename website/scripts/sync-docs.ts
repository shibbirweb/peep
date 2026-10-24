#!/usr/bin/env bun
/**
 * Copies docs/wiki into the website, so the wiki and the website are written in one place.
 *
 *     bun scripts/sync-docs.ts              from docs/wiki (run by `bun run dev` and `bun run build`)
 *     bun scripts/sync-docs.ts <wiki-dir>   from another copy, such as a release's (scripts/build-versions.ts)
 *
 * Pages keep their folders (docs/usage, docs/developer), so links between pages stay as they are and
 * VitePress resolves them. Images go to public/images. Links to other repository files (the protocol,
 * the changelog) become GitHub links. The summary from pages.json becomes the page's description, and
 * pages.json itself goes along, for the sidebar in .vitepress/config.mts.
 *
 * website/docs and website/public/images are generated: they are ignored by git and rebuilt on every run.
 */

import { copyFileSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { isExternal, type Manifest, resolvePath, segments, WIKI_DIR } from "../../scripts/wiki";

const WEBSITE = dirname(dirname(fileURLToPath(import.meta.url)));
const ROOT = dirname(WEBSITE);
const WIKI = process.argv[2] ? resolve(process.argv[2]) : join(ROOT, WIKI_DIR);
const DOCS = join(WEBSITE, "docs");
const IMAGES = join(WEBSITE, "public", "images");
/** Links to source files point where the docs come from: main, or a version's tag or branch (PEEP_DOCS_REF). */
const BLOB = `https://github.com/shibbirweb/peep/blob/${process.env.PEEP_DOCS_REF ?? "main"}`;

/** Rewrites one page's links for the website. Code blocks and inline code are left alone. */
function rewriteLinks(markdown: string, from: string): string {
  return segments(markdown)
    .map((segment) => {
      if (segment.code) {
        return segment.text;
      }

      return segment.text
        .split(/(`[^`\n]*`)/)
        .map((part) => (part.startsWith("`") ? part : rewriteProse(part, from)))
        .join("");
    })
    .join("\n");
}

function rewriteProse(prose: string, from: string): string {
  return prose.replace(/(!?)\[([^\]]*)\]\(([^)\s]+)\)/g, (whole, bang: string, text: string, target: string) => {
    if (isExternal(target)) {
      return whole;
    }

    const anchor = target.includes("#") ? `#${target.split("#")[1]}` : "";
    const path = resolvePath(from, target);

    if (bang === "!") {
      return `![${text}](/images/${path.split("/").pop()})`;
    }

    if (path.startsWith(`${WIKI_DIR}/`)) {
      return whole;
    }

    return `[${text}](${BLOB}/${path}${anchor})`;
  });
}

/**
 * For the next version (PEEP_DOCS_CHANNEL=beta), `composer require shibbirweb/peep` installs the beta:
 * `:@beta` lets Composer take the newest beta or stable version, so it is right before and after a release.
 * Only lines that run `composer require`, in code and prose alike.
 */
function betaInstall(markdown: string): string {
  if (process.env.PEEP_DOCS_CHANNEL !== "beta") {
    return markdown;
  }

  return markdown.replace(/^.*composer require.*$/gm, (line) => line.replace(/shibbirweb\/peep(?![:\w/-])/g, "shibbirweb/peep:@beta"));
}

function frontmatter(summary: string): string {
  return `---\ndescription: ${JSON.stringify(summary)}\n---\n\n`;
}

const manifest = JSON.parse(readFileSync(join(WIKI, "pages.json"), "utf8")) as Manifest;

rmSync(DOCS, { recursive: true, force: true });
rmSync(IMAGES, { recursive: true, force: true });
mkdirSync(IMAGES, { recursive: true });

const entries = [...manifest.usage, ...manifest.developer];
for (const entry of entries) {
  const markdown = readFileSync(join(WIKI, entry.page), "utf8");
  const out = join(DOCS, entry.page);
  mkdirSync(dirname(out), { recursive: true });
  writeFileSync(out, frontmatter(entry.summary) + betaInstall(rewriteLinks(markdown, `${WIKI_DIR}/${entry.page}`)));
}

copyFileSync(join(WIKI, "pages.json"), join(DOCS, "pages.json"));

const images = readdirSync(join(WIKI, "images")).filter((name) => name.endsWith(".png"));
for (const image of images) {
  copyFileSync(join(WIKI, "images", image), join(IMAGES, image));
}

console.log(`Copied ${entries.length} pages and ${images.length} images from ${process.argv[2] ?? WIKI_DIR}`);
