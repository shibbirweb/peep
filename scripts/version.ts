#!/usr/bin/env bun
/**
 * Read and change Peep's one version number, which several files carry.
 *
 * `src/Version.php` (the client, what Packagist users install) is the source of truth; the
 * viewer, the desktop app and its Cargo files carry the same number so nothing drifts.
 *
 *     bun scripts/version.ts show               print the version
 *     bun scripts/version.ts check [x.y.z]      verify every file agrees (and, given, is x.y.z with a changelog section)
 *     bun scripts/version.ts set 1.2.0          move every file at once
 *     bun scripts/version.ts notes [1.2.0]      print a changelog section (Unreleased by default): the release notes
 *     bun scripts/version.ts pending            say whether a release is due, and which
 *     bun scripts/version.ts bump <level>       move to the next version: beta | release | patch | minor | major | x.y.z
 *     bun scripts/version.ts untried            list unreleased commits that change the program (not docs, test, chore)
 *     bun scripts/version.ts next-ticket        print the next free REL ticket number for the release commit
 *
 * Releases are normally cut by the "Prepare release" workflow, which runs `bump` and opens
 * the release pull request; merging it publishes the release (see docs/wiki/developer/Releasing.md).
 */

import { execFileSync } from "node:child_process";
import { readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import {
  cargoLockVersion,
  cargoTomlVersion,
  changelogSection,
  dateChangelog,
  isBeta,
  jsonVersion,
  type Level,
  nextTicket,
  nextVersion,
  phpConstant,
  type Place,
  SEMVER,
  setCargoLockVersion,
  setCargoTomlVersion,
  setJsonVersion,
  setPhpConstant,
  suggestLevel,
  untriedSubjects,
} from "./versioning";

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const CHANGELOG = join(ROOT, "CHANGELOG.md");
const REPOSITORY = "shibbirweb/peep";
const CRATE = "peep-desktop";
/** The ticket key release commits use, like `chore:[REL-3] release 1.2.0`. */
const TICKET_KEY = "REL";

const PLACES: Place[] = [
  { file: "src/Version.php", read: phpConstant, write: setPhpConstant },
  { file: "server/src/Version.php", read: phpConstant, write: setPhpConstant },
  { file: "desktop/src-tauri/tauri.conf.json", read: jsonVersion, write: setJsonVersion },
  { file: "desktop/package.json", read: jsonVersion, write: setJsonVersion },
  { file: "desktop/src-tauri/Cargo.toml", read: cargoTomlVersion, write: setCargoTomlVersion },
  {
    file: "desktop/src-tauri/Cargo.lock",
    read: (text) => cargoLockVersion(text, CRATE),
    write: (text, version) => setCargoLockVersion(text, CRATE, version),
  },
];

const read = (path: string) => readFileSync(join(ROOT, path), "utf8");

function git(...args: string[]): string | null {
  try {
    return execFileSync("git", args, { cwd: ROOT, encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] }).trim();
  } catch {
    return null;
  }
}

function authoritative(): string {
  const version = phpConstant(read(PLACES[0].file));
  if (version === null) {
    console.error(`could not find VERSION in ${PLACES[0].file}`);
    process.exit(1);
  }
  return version;
}

function check(expected?: string): number {
  const found = PLACES.map((place) => [place.file, place.read(read(place.file))] as const);
  const target = expected ?? found[0][1];
  const width = Math.max(...found.map(([file]) => file.length));

  for (const [file, value] of found) {
    console.log(`  ${file.padEnd(width)}  ${value ?? "(missing)"}${value === target ? "" : "   <- disagrees"}`);
  }

  let failed = found.some(([, value]) => value !== target) || target === null;
  if (failed) {
    console.error(`\nRun \`bun scripts/version.ts set ${target ?? "x.y.z"}\` and commit.`);
  }

  if (expected !== undefined && changelogSection(read("CHANGELOG.md"), expected) === null) {
    console.error(`CHANGELOG.md has no [${expected}] section.`);
    failed = true;
  }

  if (failed) {
    return 1;
  }

  console.log(`\nall files agree on ${target}`);
  return 0;
}

function set(version: string): number {
  if (!SEMVER.test(version)) {
    console.error(`'${version}' is not a semver version, expected something like 1.2.0 or 1.2.0-beta.1`);
    return 2;
  }

  for (const place of PLACES) {
    const text = read(place.file);
    const updated = place.write(text, version);
    if (updated === null) {
      console.error(`could not rewrite the version in ${place.file}`);
      return 1;
    }
    writeFileSync(join(ROOT, place.file), updated, "utf8");
    console.log(`${place.file}: ${place.read(text)} -> ${version}`);
  }

  console.log();
  return check();
}

function notes(version?: string): number {
  const section = changelogSection(readFileSync(CHANGELOG, "utf8"), version);
  const label = (version ?? "Unreleased").replace(/^v/, "");
  if (section === null) {
    console.error(`CHANGELOG.md has no [${label}] section to take release notes from`);
    return 1;
  }
  if (section === "") {
    console.error(`the [${label}] section of CHANGELOG.md is empty`);
    return 1;
  }
  console.log(section);
  return 0;
}

/** Commits no release tag contains yet, merges left out. */
function unreleasedSubjects(): string[] {
  const out = git("log", "HEAD", "--no-merges", "--format=%s", "--not", "--tags=v*");
  return out ? out.split("\n").filter(Boolean) : [];
}

function lastTag(): string | null {
  return git("for-each-ref", "--sort=-creatordate", "--count=1", "--format=%(refname:short)", "refs/tags/v*") || null;
}

/** The last stable release, for the changelog's comparison link. */
function lastStable(): string | null {
  const tags = (git("tag", "--list", "v*", "--sort=-v:refname") ?? "").split("\n").filter((tag) => /^v\d+\.\d+\.\d+$/.test(tag));
  return tags[0]?.slice(1) ?? null;
}

function pending(): number {
  const current = authoritative();
  const subjects = unreleasedSubjects();
  console.log(`Current version  ${current}`);
  console.log(`Last release     ${lastTag() ?? "none yet"}`);
  if (subjects.length === 0) {
    console.log("\nNothing has landed since that release.");
    return 0;
  }
  const level = suggestLevel(subjects);
  console.log(`Unreleased       ${subjects.length} commits`);
  if (isBeta(current)) {
    console.log(`\nThe next beta would be ${nextVersion(current, "beta")}; finishing it makes ${nextVersion(current, "release")}.`);
  } else {
    console.log(`\nA ${level} release would be ${nextVersion(current, level)}, or ${nextVersion(current, "beta", level)} as a beta.`);
  }
  console.log('Run the "Prepare release" workflow on main to cut it; it bumps the version for you.');
  return 0;
}

function untried(): number {
  const out = git("log", "HEAD", "--no-merges", "--format=%h %s", "--not", "--tags=v*") ?? "";
  const lines = out.split("\n").filter(Boolean);
  const subjects = new Set(untriedSubjects(lines.map((line) => line.slice(line.indexOf(" ") + 1))));
  for (const line of lines) {
    if (subjects.has(line.slice(line.indexOf(" ") + 1))) {
      console.log(line);
    }
  }
  return 0;
}

/**
 * Moves every file to the next version for `requested` and, for a stable version, dates the
 * changelog. Leaves everything alone when nothing has landed since the last release, except
 * finishing a beta. The workflow treats an unchanged version as "nothing to release".
 */
function bump(requested: string | undefined): number {
  const levels: Level[] = ["beta", "release", "patch", "minor", "major"];
  if (!requested || !(levels.includes(requested as Level) || SEMVER.test(requested))) {
    console.error(`Usage: bun scripts/version.ts bump <${levels.join(" | ")} | x.y.z>`);
    return 2;
  }

  const current = authoritative();
  const subjects = unreleasedSubjects();
  const finishing = isBeta(current) && requested === "release";
  if (subjects.length === 0 && !finishing && !SEMVER.test(requested)) {
    console.log(`Nothing has landed since ${lastTag() ?? current}, so there is nothing for a new release to hold.`);
    return 0;
  }

  const target = SEMVER.test(requested) ? requested : nextVersion(current, requested as Level, suggestLevel(subjects));
  if (!target) {
    console.error(
      isBeta(current)
        ? `${current} is a beta: move it on with \`bump beta\` or finish it with \`bump release\``
        : `cannot make a '${requested}' release from ${current}; \`bump release\` only finishes a beta`,
    );
    return 2;
  }

  const changelog = readFileSync(CHANGELOG, "utf8");
  // Betas are published with the Unreleased notes as they are, so they must exist.
  if (!changelogSection(changelog)) {
    console.error("CHANGELOG.md has nothing under [Unreleased]. Describe what the release holds there first.");
    return 1;
  }
  if (!isBeta(target) && changelogSection(changelog, target) !== null) {
    console.error(`CHANGELOG.md already has a [${target}] section, so ${target} looks released already`);
    return 1;
  }

  console.log(`${requested}: ${current} -> ${target}\n`);
  if (set(target) !== 0) {
    return 1;
  }

  if (!isBeta(target)) {
    const today = new Date().toISOString().slice(0, 10);
    writeFileSync(CHANGELOG, dateChangelog(changelog, target, today, REPOSITORY, lastStable()), "utf8");
    console.log(`CHANGELOG.md: [Unreleased] -> [${target}] - ${today}`);
  }

  console.log(`\nCommit it as \`chore:[${TICKET_KEY}-${ticket()}] release ${target}\` and merge it into main by pull request.`);
  return 0;
}

function ticket(): number {
  return nextTicket((git("log", "--all", "--format=%s") ?? "").split("\n"), TICKET_KEY);
}

const [command, argument] = process.argv.slice(2);

const code = (() => {
  switch (command) {
    case "show":
      console.log(authoritative());
      return 0;
    case "check":
      return check(argument?.replace(/^v/, ""));
    case "set":
      return set(argument ?? "");
    case "notes":
      return notes(argument);
    case "pending":
      return pending();
    case "bump":
      return bump(argument);
    case "untried":
      return untried();
    case "next-ticket":
      console.log(ticket());
      return 0;
    default:
      console.error("Usage: bun scripts/version.ts <show | check [x.y.z] | set x.y.z | notes [x.y.z] | pending | bump <level> | untried | next-ticket>");
      return 2;
  }
})();

process.exit(code);
