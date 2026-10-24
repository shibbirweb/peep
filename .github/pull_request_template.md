## Summary

What this changes, and why. Link the issue it closes, if any (Closes #123).

## Changes

- 

## Test plan

- 

## Checklist

- The base branch is `develop`.
- The title follows `feat:[ID] summary` or `fix:[ID] summary` (feature ids are in docs/plan/features.md), or starts with `docs:` or `chore:`.
- User-visible changes have a line under **Unreleased** in CHANGELOG.md; it becomes the release notes.
- Client code still runs on PHP 7.1 (`composer check` passes).
- Docs in docs/wiki are updated when behaviour changes, and screenshots are retaken with `bun scripts/screenshots.ts` when the viewer looks different.
