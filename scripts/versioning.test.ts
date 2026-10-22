import { describe, expect, test } from "bun:test";
import {
  cargoLockVersion,
  cargoTomlVersion,
  changelogSection,
  dateChangelog,
  jsonVersion,
  nextTicket,
  nextVersion,
  phpConstant,
  setCargoLockVersion,
  setCargoTomlVersion,
  setJsonVersion,
  setPhpConstant,
  suggestLevel,
  untriedSubjects,
} from "./versioning";

describe("version places", () => {
  test("PHP constants of the client and the viewer", () => {
    expect(phpConstant("    public const VERSION = '0.3.0';")).toBe("0.3.0");
    expect(phpConstant("    public const string VERSION = '0.3.0';")).toBe("0.3.0");
    expect(setPhpConstant("public const string VERSION = '0.3.0';", "1.0.0")).toBe("public const string VERSION = '1.0.0';");
  });

  test("JSON keeps its formatting", () => {
    const text = '{\n  "productName": "Peep",\n  "version": "0.3.0",\n  "plugins": { "x": { "version": "9" } }\n}\n';
    expect(jsonVersion(text)).toBe("0.3.0");
    expect(setJsonVersion(text, "1.0.0")).toBe(text.replace('"0.3.0"', '"1.0.0"'));
  });

  test("Cargo.toml: only the package's version", () => {
    const toml = '[package]\nname = "peep-desktop"\nversion = "0.3.0"\n\n[dependencies]\ntauri = { version = "2" }\n';
    expect(cargoTomlVersion(toml)).toBe("0.3.0");
    expect(setCargoTomlVersion(toml, "1.0.0")).toContain('version = "1.0.0"');
    expect(setCargoTomlVersion(toml, "1.0.0")).toContain('tauri = { version = "2" }');
  });

  test("Cargo.lock: only our crate", () => {
    const lock = '[[package]]\nname = "other"\nversion = "0.3.0"\n\n[[package]]\nname = "peep-desktop"\nversion = "0.3.0"\n';
    expect(cargoLockVersion(lock, "peep-desktop")).toBe("0.3.0");
    expect(setCargoLockVersion(lock, "peep-desktop", "1.0.0")).toBe(lock.replace(/"0.3.0"\n$/, '"1.0.0"\n'));
  });
});

describe("next versions", () => {
  test("stable levels", () => {
    expect(nextVersion("1.2.3", "patch")).toBe("1.2.4");
    expect(nextVersion("1.2.3", "minor")).toBe("1.3.0");
    expect(nextVersion("1.2.3", "major")).toBe("2.0.0");
    expect(nextVersion("1.2.3", "release")).toBeNull();
  });

  test("betas", () => {
    expect(nextVersion("1.2.3", "beta")).toBe("1.3.0-beta.1");
    expect(nextVersion("1.2.3", "beta", "major")).toBe("2.0.0-beta.1");
    expect(nextVersion("1.3.0-beta.1", "beta")).toBe("1.3.0-beta.2");
    expect(nextVersion("1.3.0-beta.2", "release")).toBe("1.3.0");
    expect(nextVersion("1.3.0-beta.2", "minor")).toBeNull();
  });

  test("the commits suggest the level", () => {
    expect(suggestLevel(["fix:[SU-12] allow var-dumper 6.0"])).toBe("patch");
    expect(suggestLevel(["fix: x", "feat:[MS-01] catch mail"])).toBe("minor");
    expect(suggestLevel(["feat!:[PR-08] protocol v2"])).toBe("major");
  });

  test("untried commits leave docs, tests and chores out", () => {
    expect(untriedSubjects(["docs: wiki", "chore:[REL-1] release 1.0.0", "test: more", "fix: bug"])).toEqual(["fix: bug"]);
  });

  test("release tickets", () => {
    expect(nextTicket(["chore:[REL-3] release 1.0.0", "chore:[REL-7] release 1.1.0", "feat:[MS-01] x"], "REL")).toBe(8);
    expect(nextTicket([], "REL")).toBe(1);
  });
});

describe("changelog", () => {
  const text = [
    "# Changelog",
    "",
    "## [Unreleased]",
    "",
    "### Added",
    "",
    "- Mail inbox.",
    "",
    "## [0.3.0] - 2026-10-01",
    "",
    "### Fixed",
    "",
    "- A bug.",
    "",
    "[Unreleased]: https://github.com/shibbirweb/peep/compare/v0.3.0...HEAD",
    "[0.3.0]: https://github.com/shibbirweb/peep/releases/tag/v0.3.0",
    "",
  ].join("\n");

  test("sections without their heading or link lines", () => {
    expect(changelogSection(text)).toBe("### Added\n\n- Mail inbox.");
    expect(changelogSection(text, "v0.3.0")).toBe("### Fixed\n\n- A bug.");
    expect(changelogSection(text, "9.9.9")).toBeNull();
  });

  test("dating a release moves the notes and the links", () => {
    const dated = dateChangelog(text, "0.4.0", "2026-10-22", "shibbirweb/peep", "0.3.0");

    expect(changelogSection(dated)).toBe("");
    expect(changelogSection(dated, "0.4.0")).toBe("### Added\n\n- Mail inbox.");
    expect(dated).toContain("[Unreleased]: https://github.com/shibbirweb/peep/compare/v0.4.0...HEAD");
    expect(dated).toContain("[0.4.0]: https://github.com/shibbirweb/peep/compare/v0.3.0...v0.4.0");
    expect(dated).toContain("[0.3.0]: https://github.com/shibbirweb/peep/releases/tag/v0.3.0");
  });
});
