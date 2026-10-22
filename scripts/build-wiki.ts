#!/usr/bin/env bun
/**
 * Checks docs/wiki and turns it into GitHub wiki pages.
 *
 * A GitHub wiki is a separate git repository (`<repo>.wiki.git`) with a flat list of pages, so
 * nothing in docs/wiki reaches it by being merged. The Wiki workflow (.github/workflows/wiki.yml)
 * runs this and pushes the result.
 *
 *     bun scripts/build-wiki.ts --check        only check (CI runs this on every push and pull request)
 *     bun scripts/build-wiki.ts <out-dir>      check, then write the wiki repository's files
 *
 * docs/wiki/pages.json is the map. The check fails when a listed page is missing or a page is not
 * listed, an image is missing or unused, a link points nowhere, or a page breaks the style rules
 * (title line, no em-dash, at most MAX_WORDS words, valid Mermaid blocks).
 *
 * On the way out, `# Title` lines are dropped (the wiki prints the page name), links between pages
 * become wiki links, images go to images/, links to other repository files become GitHub URLs, and
 * Home, _Sidebar and _Footer are generated from the manifest.
 */

import { copyFileSync, existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { findLinks, isExternal, type Manifest, manifestProblems, pageName, pageProblems, resolvePath, rewriteLinks, stripTitle, WIKI_DIR } from "./wiki";

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const WIKI = join(ROOT, WIKI_DIR);
const REPOSITORY = "shibbirweb/peep";
/** The wiki is published from main, so its links to source files point there too. */
const BLOB = `https://github.com/${REPOSITORY}/blob/main`;
const GENERATED = `<!-- Generated from ${WIKI_DIR} in ${REPOSITORY}. Do not edit it in the wiki: the next publish overwrites it. -->`;

const read = (path: string) => readFileSync(path, "utf8");

function listFiles(folder: string, extension: string): string[] {
  const directory = join(WIKI, folder);
  return existsSync(directory) ? readdirSync(directory).filter((name) => name.endsWith(extension)).sort() : [];
}

function check(manifest: Manifest): string[] {
  const problems = manifestProblems(manifest);
  const listed = [...manifest.usage, ...manifest.developer].map((entry) => entry.page);
  const usedImages = new Set<string>();

  for (const folder of ["usage", "developer"]) {
    for (const file of listFiles(folder, ".md")) {
      if (!listed.includes(`${folder}/${file}`)) {
        problems.push(`${folder}/${file} is not listed in pages.json`);
      }
    }
  }

  for (const page of listed) {
    const path = join(WIKI, page);
    if (!existsSync(path)) {
      problems.push(`pages.json lists ${page}, which does not exist`);
      continue;
    }

    const markdown = read(path);
    problems.push(...pageProblems(page, markdown));

    const from = `${WIKI_DIR}/${page}`;
    for (const link of findLinks(markdown)) {
      if (isExternal(link.target)) {
        continue;
      }

      const target = resolvePath(from, link.target);
      if (!existsSync(join(ROOT, target))) {
        problems.push(`${page}: ${link.image ? "image" : "link"} ${link.target} points nowhere`);
      } else if (link.image) {
        usedImages.add(target.split("/").pop() ?? target);
      }
    }
  }

  for (const image of listFiles("images", ".png")) {
    if (!usedImages.has(image)) {
      problems.push(`images/${image} is not used by any page`);
    }
  }

  return problems;
}

function build(manifest: Manifest, out: string): void {
  mkdirSync(join(out, "images"), { recursive: true });
  const pages = new Set([...manifest.usage, ...manifest.developer].map((entry) => `${WIKI_DIR}/${entry.page}`));

  for (const entry of [...manifest.usage, ...manifest.developer]) {
    const markdown = stripTitle(read(join(WIKI, entry.page)));
    writeFileSync(join(out, `${pageName(entry.page)}.md`), `${GENERATED}\n\n${rewriteLinks(markdown, `${WIKI_DIR}/${entry.page}`, pages, BLOB)}`);
  }

  for (const image of listFiles("images", ".png")) {
    copyFileSync(join(WIKI, "images", image), join(out, "images", image));
  }

  const list = (entries: Manifest["usage"]) => entries.map((entry) => `- [${pageName(entry.page).replaceAll("-", " ")}](${pageName(entry.page)}): ${entry.summary}`).join("\n");

  writeFileSync(
    join(out, "Home.md"),
    [
      GENERATED,
      "",
      "Peep shows what your PHP app is doing in a separate window: values you send with `peep()`, queries, logs, exceptions and jobs, and the mail and SMS it sends. It works in any PHP app from PHP 7.1 to 8.5 and sets itself up in Laravel.",
      "",
      "![The Peep viewer](images/viewer.png)",
      "",
      "## Using Peep",
      "",
      list(manifest.usage),
      "",
      "## Working on Peep",
      "",
      list(manifest.developer),
      "",
    ].join("\n"),
  );

  const sidebar = (title: string, entries: Manifest["usage"]) => [`**${title}**`, "", ...entries.map((entry) => `- [${pageName(entry.page).replaceAll("-", " ")}](${pageName(entry.page)})`)].join("\n");
  writeFileSync(join(out, "_Sidebar.md"), [GENERATED, "", "[Home](Home)", "", sidebar("Using Peep", manifest.usage), "", sidebar("Working on Peep", manifest.developer), ""].join("\n"));
  writeFileSync(
    join(out, "_Footer.md"),
    `${GENERATED}\n\nThis wiki is written in [${WIKI_DIR}](https://github.com/${REPOSITORY}/tree/main/${WIKI_DIR}); change it there by pull request.\n`,
  );
}

const argument = process.argv[2];
if (!argument) {
  console.error("Usage: bun scripts/build-wiki.ts --check | <out-dir>");
  process.exit(2);
}

const manifest = JSON.parse(read(join(WIKI, "pages.json"))) as Manifest;
const problems = check(manifest);

if (problems.length > 0) {
  console.error(`The wiki has ${problems.length} problem${problems.length === 1 ? "" : "s"}:\n`);
  for (const problem of problems) {
    console.error(`  ${problem}`);
  }
  process.exit(1);
}

const count = manifest.usage.length + manifest.developer.length;
if (argument === "--check") {
  console.log(`The wiki is fine: ${count} pages, ${listFiles("images", ".png").length} images.`);
} else {
  build(manifest, argument);
  console.log(`Wrote ${count} pages and Home, _Sidebar and _Footer to ${argument}`);
}
