# AGENTS.md

Guidance for AI coding agents (Claude Code, Codex, Cursor, Copilot, Gemini CLI and others) working in this repository. `CLAUDE.md` imports this file, so every agent follows the same rules.

Read [CONTRIBUTING.md](CONTRIBUTING.md) first: it has the setup, the repository layout, the checks to run and the client rules (code in `src/` and `tests/` must parse on PHP 7.1 and run on PHP 8.5). The wiki pages [Contributing](docs/wiki/developer/Contributing.md) and [Releasing](docs/wiki/developer/Releasing.md) cover CI and releases. The rules below come on top of those.

## Branches

- Pull requests go into `develop`, the beta line. `main` only takes merges from `develop` when a release is promoted.
- Start every change on a new branch from the latest `develop`. Never commit straight to `develop` or `main`.
- Add every user-visible change under **Unreleased** in `CHANGELOG.md` in the same pull request.

## Commits and pull requests

- Commit messages: `feat:[TICKET] summary` for new features and `fix:[TICKET] summary` for bug fixes. The ticket goes in square brackets straight after the colon (no space before the bracket), followed by a one-line summary. Example: `fix:[RL-16] do not require a changelog section when checking a beta version`.
- Other changes use `docs:`, `test:`, `chore:` or `ci:`. Release commits are `chore:[REL-n] release x.y.z` (`bun scripts/version.ts next-ticket` prints the next number).
- Do not use co-authors. No `Co-Authored-By` lines (or any other co-author trailer) in commits, squash merges or pull request descriptions, for Claude or anyone else.
- No tool attribution either: never add lines such as `🤖 Generated with [Claude Code](https://claude.com/claude-code)` or "Generated with ..." to commits, pull request descriptions, comments or release notes.
- Pull request test plans: plain bullet points describing what was run and checked, no checkboxes.
- Commit locally as the work goes; push only when every step of the task is done, unless asked to push sooner.
- Never commit `docs/research/`. It is local only and excluded through `.git/info/exclude`.
- After every commit, clear the desktop app's Cargo build to save disk space: `cargo clean --manifest-path desktop/src-tauri/Cargo.toml`. A local `.git/hooks/post-commit` hook does it; in a clone without the hook, run it by hand. Leave the shared crate cache in `~/.cargo` alone.

## Writing

- Never use the em-dash character in code, comments, commits, pull request text or docs. Use a comma, a colon, parentheses or a new sentence instead.

## Code style

- `if` statements always use full block syntax with curly braces, in every language. No single-line ifs.
- PHP arrays such as `$fillable`, `$casts` and other configuration arrays: one key per line, not inline.
- JavaScript and TypeScript: end statements with semicolons, and put a trailing comma after the last item of multi-line objects and arrays (new and edited code). This does not apply to PHP calls and parameter lists in the client, where trailing commas are a PHP 7.1 parse error.
- Name parameters after the thing they hold (`$mailId`, `$projectName`), not `$id` or `$name`.
- Default lookups into external data so a missing key never errors (`?? null`, `?? []`).
- Match the surrounding code: its comment density, naming and idioms.
