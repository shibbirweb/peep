#!/usr/bin/env bun
/**
 * Builds the website with every version of the docs, into .vitepress/dist. The Website workflow publishes it.
 *
 *     bun scripts/build-versions.ts      (bun run build:versions)
 *
 * The versions come from git (scripts/versions.ts): the latest stable minor at the root, older minors
 * under /v0.1/ and so on, and develop under /next/. Each one is a VitePress build of this website with
 * the docs/wiki of its tag or branch, so older docs get the current look and version menu. Builds go
 * into .vitepress/versions first, because VitePress empties its output folder, then are put together.
 */

import { execSync } from "node:child_process";
import { cpSync, mkdirSync, rmSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { docsVersions } from "./versions";

const WEBSITE = dirname(dirname(fileURLToPath(import.meta.url)));
const ROOT = dirname(WEBSITE);
const WORK = join(WEBSITE, ".vitepress", "versions");
const DIST = join(WEBSITE, ".vitepress", "dist");

const run = (command: string, env: Record<string, string> = {}) =>
  execSync(command, { cwd: WEBSITE, stdio: "inherit", env: { ...process.env, ...env } });

const versions = docsVersions();
if (versions.length === 0) {
  console.error("No versions found: no stable tag with docs/wiki, and no develop branch. Is this a full clone (fetch-depth: 0)?");
  process.exit(1);
}

rmSync(WORK, { recursive: true, force: true });

for (const version of versions) {
  console.log(`\n${version.label}: docs from ${version.ref}, published at /${version.path}`);

  const source = join(WORK, "src", version.id);
  const out = join(WORK, "out", version.id);
  mkdirSync(source, { recursive: true });
  // The subtree (ref:docs/wiki), not the path: .gitattributes marks /docs export-ignore for Composer,
  // and git archive would leave it out.
  execSync(`git archive ${version.ref}:docs/wiki | tar -x -C "${source}"`, { cwd: ROOT, stdio: "inherit" });

  // Links to repository files point at the tag, or at the branch for next, whose install commands get :@beta.
  const ref = version.next ? version.ref.replace(/^origin\//, "") : version.ref;
  run(`bun scripts/sync-docs.ts "${source}"`, { PEEP_DOCS_REF: ref, PEEP_DOCS_CHANNEL: version.next ? "beta" : "stable" });
  run("bun --bun vitepress build", { PEEP_DOCS_VERSION: version.id, PEEP_DOCS_OUT_DIR: out });
}

rmSync(DIST, { recursive: true, force: true });
for (const version of versions) {
  cpSync(join(WORK, "out", version.id), join(DIST, version.path), { recursive: true });
}
rmSync(WORK, { recursive: true, force: true });

// Leave website/docs as the working tree has it, for bun run dev.
run("bun scripts/sync-docs.ts");

console.log(`\nBuilt ${versions.map((version) => version.label).join(", ")} into .vitepress/dist`);
