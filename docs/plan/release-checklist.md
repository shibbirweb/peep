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
| Submit `https://github.com/shibbirweb/peep` on Packagist and enable the GitHub hook (Packagist shows the webhook URL and token) | packagist.org | RL-02 |
| Add the Apple secrets (`APPLE_CERTIFICATE`, `APPLE_CERTIFICATE_PASSWORD`, `APPLE_SIGNING_IDENTITY`, `APPLE_ID`, `APPLE_PASSWORD`, `APPLE_TEAM_ID`) | GitHub repository secrets | DT-13 |
| Add `WINDOWS_CERTIFICATE`, `WINDOWS_CERTIFICATE_PASSWORD` (secrets) and `WINDOWS_CERTIFICATE_THUMBPRINT` (variable) | GitHub | DT-14 |
| Generate the update key (`cd desktop && bun run tauri signer generate`); add the private key and its password as `TAURI_SIGNING_PRIVATE_KEY`, `TAURI_SIGNING_PRIVATE_KEY_PASSWORD` (secrets) and the public key as `TAURI_UPDATER_PUBKEY` (variable) | GitHub | DT-12 in release builds |
| Create the tap repository `shibbirweb/homebrew-tap` with `Casks/peep.rb` from `packaging/homebrew/peep.rb`, filled in after the first release | GitHub | RL-10 |

## Cutting a release (RL-03)

```bash
scripts/set-version.sh 1.0.0          # client, viewer, desktop app, and a CHANGELOG section
git commit -am "chore: release 1.0.0"
git tag v1.0.0
git push origin main v1.0.0
```

The tag starts `.github/workflows/release.yml`: it checks that every version is 1.0.0 and the changelog has a 1.0.0 section, runs the client, Laravel and viewer workflows, builds `peep.phar` and `peep-server.phar` with `SHA256SUMS`, creates a draft GitHub Release with the changelog notes, then builds and attaches the desktop apps (and `latest.json` for updates). Review the draft and publish it; Packagist picks the tag up from its hook.
