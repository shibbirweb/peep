// Pure helpers behind scripts/version.ts, kept separate so they can be tested (bun test scripts).

export const SEMVER = /^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/;

/** A version written in source: the file, how to read it, how to write it. */
export interface Place {
  file: string;
  read: (text: string) => string | null;
  write: (text: string, version: string) => string | null;
}

/** `const VERSION = '1.2.3';` (client) or `const string VERSION = '1.2.3';` (viewer). */
export function phpConstant(text: string): string | null {
  return /const (?:string )?VERSION = '([^']+)';/.exec(text)?.[1] ?? null;
}

export function setPhpConstant(text: string, version: string): string | null {
  const constant = /(const (?:string )?VERSION = ')[^']+(';)/;
  return constant.test(text) ? text.replace(constant, `$1${version}$2`) : null;
}

/** The top-level "version" of a JSON file (tauri.conf.json, package.json). */
export function jsonVersion(text: string): string | null {
  try {
    const value = JSON.parse(text).version;
    return typeof value === "string" ? value : null;
  } catch {
    return null;
  }
}

/** Rewrites the first "version" key only, keeping the file's formatting. */
export function setJsonVersion(text: string, version: string): string | null {
  const key = /("version"\s*:\s*")[^"]*(")/;
  return key.test(text) ? text.replace(key, `$1${version}$2`) : null;
}

/** The version from the `[package]` table only, never a dependency's. */
export function cargoTomlVersion(text: string): string | null {
  let inPackage = false;
  for (const line of text.split("\n")) {
    const trimmed = line.trim();
    if (trimmed.startsWith("[")) {
      inPackage = trimmed === "[package]";
      continue;
    }
    if (inPackage) {
      const match = /^version\s*=\s*"([^"]+)"/.exec(trimmed);
      if (match) {
        return match[1];
      }
    }
  }
  return null;
}

export function setCargoTomlVersion(text: string, version: string): string | null {
  const packageTable = /(\[package\][^[]*?version\s*=\s*)"[^"]+"/;
  return packageTable.test(text) ? text.replace(packageTable, `$1"${version}"`) : null;
}

/** The version Cargo.lock records for `crate`. */
export function cargoLockVersion(text: string, crate: string): string | null {
  for (const block of text.split("[[package]]")) {
    if (new RegExp(`^\\s*name\\s*=\\s*"${crate}"\\s*$`, "m").test(block)) {
      return /^\s*version\s*=\s*"([^"]+)"\s*$/m.exec(block)?.[1] ?? null;
    }
  }
  return null;
}

/** The crate's own entry has no checksum, so only its version line changes; no cargo needed. */
export function setCargoLockVersion(text: string, crate: string, version: string): string | null {
  const entry = new RegExp(`(\\[\\[package\\]\\]\\s*\\nname\\s*=\\s*"${crate}"\\s*\\nversion\\s*=\\s*)"[^"]+"`);
  return entry.test(text) ? text.replace(entry, `$1"${version}"`) : null;
}

/**
 * The release the README's tour video comes from: its jsDelivr links name the tag, as in
 * `cdn.jsdelivr.net/gh/shibbirweb/peep@v1.2.3/demo/peep-tour.mp4`, so every branch plays the video of
 * the version it is at. Null when there is no such link, or the links disagree.
 */
export function readmeTourVersion(text: string): string | null {
  const versions = [...text.matchAll(/cdn\.jsdelivr\.net\/gh\/[^/@\s]+\/[^/@\s]+@v([^/\s)]+)\/demo\//g)].map((match) => match[1]);
  return versions.length > 0 && versions.every((version) => version === versions[0]) ? versions[0] : null;
}

export function setReadmeTourVersion(text: string, version: string): string | null {
  const link = /(cdn\.jsdelivr\.net\/gh\/[^/@\s]+\/[^/@\s]+@v)[^/\s)]+(\/demo\/)/g;
  return link.test(text) ? text.replace(link, `$1${version}$2`) : null;
}

export function isBeta(version: string): boolean {
  return /-beta\.\d+$/.test(version);
}

export type Level = "patch" | "minor" | "major" | "beta" | "release";

/**
 * The next version for a level. From a stable version, `beta` starts the first beta of the
 * next minor (or `suggested`) release; from a beta, `beta` is the next beta and `release`
 * finishes it.
 */
export function nextVersion(current: string, level: Level, suggested: "patch" | "minor" | "major" = "minor"): string | null {
  const [core] = current.split("-");
  const [major, minor, patch] = core.split(".").map(Number);
  const stable = {
    patch: `${major}.${minor}.${patch + 1}`,
    minor: `${major}.${minor + 1}.0`,
    major: `${major + 1}.0.0`,
  };
  const beta = /-beta\.(\d+)$/.exec(current);
  if (beta) {
    if (level === "beta") {
      return `${core}-beta.${Number(beta[1]) + 1}`;
    }
    return level === "release" ? core : null;
  }
  if (level === "beta") {
    return `${stable[suggested]}-beta.1`;
  }
  return level === "release" ? null : stable[level];
}

/** The commit type of a subject, like "feat" for `feat:[MS-01] catch mail`, and whether it is breaking (`feat!:`). */
function commitKind(subject: string): { type: string; breaking: boolean } {
  const match = /^\s*([A-Za-z]+)(?:\([^)]*\))?(!)?\s*:/.exec(subject);
  return { type: match?.[1]?.toLowerCase() ?? "", breaking: match?.[2] === "!" };
}

/** A breaking commit suggests a major release, feat: a minor one, anything else a patch. */
export function suggestLevel(subjects: string[]): "patch" | "minor" | "major" {
  const kinds = subjects.map(commitKind);
  if (kinds.some((kind) => kind.breaking)) {
    return "major";
  }
  return kinds.some((kind) => kind.type === "feat") ? "minor" : "patch";
}

/**
 * Commits that change what people run: anything but docs, test and chore (release commits
 * are chore). A stable release may only finish a beta when none of these landed after it.
 */
export function untriedSubjects(subjects: string[]): string[] {
  return subjects.filter((subject) => !["docs", "test", "chore"].includes(commitKind(subject).type));
}

/** The next free ticket number for `key` (like REL), one past the highest used. */
export function nextTicket(subjects: string[], key: string): number {
  const used = subjects.flatMap((subject) =>
    [...subject.matchAll(new RegExp(`\\[${key}-(\\d+)\\]`, "g"))].map((match) => Number(match[1])),
  );
  return used.length > 0 ? Math.max(...used) + 1 : 1;
}

/** A changelog section without its heading; `[Unreleased]` when no version is given. */
export function changelogSection(text: string, version?: string): string | null {
  const label = version === undefined ? "Unreleased" : version.replace(/^v/, "");
  const start = text.indexOf(`## [${label}]`);
  if (start === -1) {
    return null;
  }
  const body = text.slice(text.indexOf("\n", start) + 1);
  const end = body.search(/^## \[/m);
  return (end === -1 ? body : body.slice(0, end))
    .split("\n")
    .filter((line) => !/^\[[^\]]+\]:\s*\S+/.test(line))
    .join("\n")
    .trim();
}

/**
 * Dates the changelog for a stable release: `## [Unreleased]` becomes `## [x.y.z] - YYYY-MM-DD`,
 * a fresh empty Unreleased section goes above it, and the comparison links at the bottom follow.
 */
export function dateChangelog(text: string, version: string, date: string, repository: string, previous: string | null): string {
  if (!text.includes("## [Unreleased]")) {
    return text;
  }

  let dated = text.replace("## [Unreleased]", `## [Unreleased]\n\n## [${version}] - ${date}`);
  const base = `https://github.com/${repository}`;
  const unreleased = `[Unreleased]: ${base}/compare/v${version}...HEAD`;
  const released = previous ? `[${version}]: ${base}/compare/v${previous}...v${version}` : `[${version}]: ${base}/releases/tag/v${version}`;

  dated = /^\[Unreleased\]:.*$/m.test(dated) ? dated.replace(/^\[Unreleased\]:.*$/m, `${unreleased}\n${released}`) : `${dated.trimEnd()}\n\n${unreleased}\n${released}\n`;

  return dated;
}
