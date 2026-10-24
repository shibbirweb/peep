/**
 * The versions of the docs on the website, worked out from git.
 *
 * Every minor version gets its docs from the newest stable tag of that minor (v0.1.3 for 0.1), and the
 * newest of them is the latest version, at the root of the site. Older ones live under /v0.1/ and so on.
 * "Next" is the docs on develop (the beta line), under /next/. A tag without docs/wiki/pages.json (from
 * before the wiki) is skipped.
 *
 * Used by .vitepress/config.mts (for the version menu) and scripts/build-versions.ts (to build them all).
 */

import { execFileSync } from "node:child_process";
import { dirname } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = dirname(dirname(dirname(fileURLToPath(import.meta.url))));

export interface DocsVersion {
  /** `latest`, `v0.1` or `next`; PEEP_DOCS_VERSION names the one being built. */
  id: string;
  /** What the version menu shows. */
  label: string;
  /** The git tag or branch its docs come from. */
  ref: string;
  /** Where it is published, relative to the site root: "" for the latest, `v0.1/`, `next/`. */
  path: string;
  latest: boolean;
  next: boolean;
}

function git(...args: string[]): string | null {
  try {
    return execFileSync("git", args, { cwd: ROOT, encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] }).trim();
  } catch {
    return null;
  }
}

function hasDocs(ref: string): boolean {
  return git("cat-file", "-e", `${ref}:docs/wiki/pages.json`) !== null;
}

/** The newest stable tag of each minor version, newest minor first. */
function stableMinors(): { minor: string; tag: string }[] {
  const newest = new Map<string, { tag: string; patch: number }>();

  for (const tag of (git("tag", "--list", "v*") ?? "").split("\n")) {
    const match = tag.match(/^v(\d+)\.(\d+)\.(\d+)$/);
    if (!match) {
      continue;
    }

    const minor = `${match[1]}.${match[2]}`;
    const patch = Number(match[3]);
    if ((newest.get(minor)?.patch ?? -1) < patch) {
      newest.set(minor, { tag, patch });
    }
  }

  const number = (minor: string) => minor.split(".").map(Number);

  return [...newest.entries()]
    .map(([minor, { tag }]) => ({ minor, tag }))
    .filter(({ tag }) => hasDocs(tag))
    .sort((a, b) => number(b.minor)[0] - number(a.minor)[0] || number(b.minor)[1] - number(a.minor)[1]);
}

/** The branch the next version's docs come from: develop on GitHub, or the local branch. */
function nextRef(): string | null {
  for (const ref of ["origin/develop", "develop"]) {
    if (git("rev-parse", "--verify", "--quiet", `${ref}^{commit}`) !== null && hasDocs(ref)) {
      return ref;
    }
  }

  return null;
}

export function docsVersions(): DocsVersion[] {
  const versions: DocsVersion[] = stableMinors().map(({ minor, tag }, index) => ({
    id: index === 0 ? "latest" : `v${minor}`,
    label: `v${minor}`,
    ref: tag,
    path: index === 0 ? "" : `v${minor}/`,
    latest: index === 0,
    next: false,
  }));

  const ref = nextRef();
  if (ref) {
    versions.push({ id: "next", label: "Next (beta)", ref, path: "next/", latest: false, next: true });
  }

  return versions;
}
