# Release checklist (Phase 9)

What is left before `v1.0.0`, and what needs an account or a decision only the owner can make.

## Name check (RL-01)

A preliminary web search on 2026-10-22 (Justia's USPTO mirror and a general search) found:

- No live registration of the word mark **PEEP** for software (Nice class 9) in the results.
- Related class 9 marks, none of them "Peep" alone: PEEPCHAT (registered 2018, photo and video sharing apps), SOFTWARE PEEPS (2024 application, not yet examined), and the cancelled PEEPSPOT and WEBPEEP. Other "Peep" marks found were for clothing (PEEP STUDIO, cancelled; PEEP SHOW) and confectionery (PEEPS).
- No PHP debugger or developer tool named Peep, no Packagist package `shibbirweb/peep` yet, and no Homebrew formula or cask named `peep`.

This is not a clearance search. Before 1.0, search the official databases for the exact mark in class 9 (and 42 for software services): the USPTO's [Trademark Search](https://tmsearch.uspto.gov/) and EUIPO's [eSearch plus](https://euipo.europa.eu/eSearch/), and record the result here.

## Accounts and secrets

| Step | Where | For |
|------|-------|-----|
| Push the repository and check that every workflow passes on `main` | GitHub | SU-12, SU-13, DT-15 |
| Either set the variable `PACKAGIST_AUTO_PUBLISH` to `true` with the secrets `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` (the Release workflow creates the package on the first release and updates it on every one after), or submit the repository on packagist.org and enable its GitHub hook | packagist.org, GitHub | RL-02, RL-12 |
| Open the Wiki tab once and save any page, so the Wiki workflow can publish `docs/wiki` | GitHub | RL-11 |
| Add the Apple secrets (`APPLE_CERTIFICATE`, `APPLE_CERTIFICATE_PASSWORD`, `APPLE_SIGNING_IDENTITY`, `APPLE_ID`, `APPLE_PASSWORD`, `APPLE_TEAM_ID`) | GitHub repository secrets | DT-13 |
| Add `WINDOWS_CERTIFICATE`, `WINDOWS_CERTIFICATE_PASSWORD` (secrets) and `WINDOWS_CERTIFICATE_THUMBPRINT` (variable) | GitHub | DT-14 |
| Generate the update key (`cd desktop && bun run tauri signer generate`); add the private key and its password as `TAURI_SIGNING_PRIVATE_KEY`, `TAURI_SIGNING_PRIVATE_KEY_PASSWORD` (secrets) and the public key as `TAURI_UPDATER_PUBKEY` (variable) | GitHub | DT-12 in release builds |
| Create the tap repository `shibbirweb/homebrew-tap` with `Casks/peep.rb` from `packaging/homebrew/peep.rb`, filled in after the first release | GitHub | RL-10 |

## Cutting a release (RL-03, RL-13)

Releases follow nibtree's model: `develop` is the beta line, `main` is stable, and workflows do the bumping, tagging and publishing. The full description, with diagrams, is the wiki's [Releasing](../wiki/developer/Releasing.md) page.

1. Create the `develop` branch from `main` and make it the default branch (both done), and protect `develop` and `main`: pull requests and passing CI.
2. Tick Settings, Actions, General, "Allow GitHub Actions to create and approve pull requests".
3. Actions, **Beta release** on `develop`: opens `chore:[REL-n] release 0.1.0-beta.1`. Merge it; **Publish beta** publishes the pre-release and **Release** attaches the PHARs and desktop apps.
4. Actions, **Stable release** on `develop`, merge its pull request, then merge the develop to main pull request that **Promote stable** opens. **Publish stable** publishes the release; **Release** builds it and, with `PACKAGIST_AUTO_PUBLISH`, creates or updates the Packagist package.

`bun scripts/version.ts pending` says what the next release would be at any time.
