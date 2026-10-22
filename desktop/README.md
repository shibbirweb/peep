# Peep desktop app

Tauri 2 app for macOS, Windows and Linux. It shows the Peep viewer in a native window and runs the viewer server (`peep-server.phar`) with its own static PHP 8.5, so nothing else needs to be installed.

## What it does

- **Starts the viewer, or reuses one.** If a viewer already answers on the port from `settings.json` (for example `php peep-server.phar serve` in a terminal), the window uses it and leaves it running on quit. Otherwise the app starts its bundled viewer and stops it on quit; the viewer also stops by itself when the app is gone after a crash or force quit (`serve --exit-with=<pid>`).
- **Display mode** (Settings in the viewer): *Desktop window* shows the window; *Browser* keeps the app in the tray and opens the viewer in your browser.
- **Tray** with the unread mail and SMS count, Show Peep, Open in browser, Always on top, Start at login, Quit. Closing the window keeps Peep in the tray.
- **Hotkey** Cmd+Alt+P (Ctrl+Alt+P on Windows and Linux) shows and hides the window.
- **Notifications** for `notify()` and exceptions while the window is in the background (switch them on in the viewer's Settings).
- **`peep://open`**: `peep-server serve --open=window` opens the app when it is installed.
- **Updates** from GitHub Releases, in builds that carry an update key.

## Build it

Needs Rust, Bun and the viewer PHAR.

```bash
# 1. The viewer PHAR (PHP 8.5 and Bun)
cd server
composer install
composer build:ui
composer build:phar

# 2. The app
cd ../desktop
bun install
bun run build          # downloads the static PHP, then "tauri build"
```

`bun run dev` starts the app in development mode. `scripts/fetch-php.sh [target]` downloads the static PHP 8.5 build of static-php-cli for a target and checks its pinned SHA-256; `scripts/prepare-sidecar.sh` also copies the PHAR into place.

Set `PEEP_DESKTOP_TRACE=1` to print what the viewer page asks of the app, and `PEEP_CONFIG_DIR` to use another settings folder.

## Release builds

`.github/workflows/desktop.yml` builds macOS (arm64, x86_64), Linux (x86_64, arm64) and Windows on every tag and attaches them to a draft GitHub Release. These secrets and variables switch on signing and updates; without them the builds are unsigned:

| Name | Kind | For |
|------|------|-----|
| `APPLE_CERTIFICATE`, `APPLE_CERTIFICATE_PASSWORD`, `APPLE_SIGNING_IDENTITY` | secrets | macOS code signing (Developer ID Application certificate, base64 .p12) |
| `APPLE_ID`, `APPLE_PASSWORD`, `APPLE_TEAM_ID` | secrets | macOS notarization (app-specific password) |
| `WINDOWS_CERTIFICATE`, `WINDOWS_CERTIFICATE_PASSWORD` | secrets | Windows code signing (base64 .pfx) |
| `WINDOWS_CERTIFICATE_THUMBPRINT` | variable | The certificate's thumbprint |
| `TAURI_SIGNING_PRIVATE_KEY`, `TAURI_SIGNING_PRIVATE_KEY_PASSWORD` | secrets | Signs update packages (`bun run tauri signer generate`) |
| `TAURI_UPDATER_PUBKEY` | variable | The matching public key, built into the app |

Design notes: [implementation plan, section 12](../docs/plan/implementation.md#12-desktop-app-phase-8).
