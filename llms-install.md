# Installing Peep: instructions for AI agents

These are for an AI coding agent (Claude Code, Codex, Cursor, Copilot, Gemini CLI and others) asked to "install Peep" for a user. People can follow them too, but the [README](README.md) is written for people.

If you were asked to change Peep's own code instead, read [AGENTS.md](AGENTS.md).

## What you are installing

Peep is a debugger for PHP apps. It has two pieces, and a working install needs both:

| Piece | What it is | Where it goes |
|-------|------------|---------------|
| The viewer | The app that shows what the user's PHP code sends | Once, on the user's machine |
| The package `shibbirweb/peep` | Gives the project `peep()` and sends to the viewer | In each PHP project, as a dev dependency |

Done means: the viewer runs, the package is in the project, Peep is enabled in development, and a test card reached the viewer (step 6).

## Rules

- Ask before anything that needs `sudo` or administrator rights, installs outside the user's home folder (except `/Applications` on macOS), or changes system PHP settings.
- Never add the package to production dependencies: always `--dev`.
- Never turn Peep on for production. Never commit a file that turns it on.
- Do not create a `peep()` call in the user's code except the temporary test in step 6, and remove that.
- Tell the user what you are about to do in one or two sentences before step 3, and what you did at the end.

## 1. Find the version to install

Use the newest **stable** release. Until Peep 1.0 there is none, so use the newest **pre-release** and tell the user it is a beta.

```bash
# Newest stable release; HTTP 404 means there is none yet.
curl -fsSL https://api.github.com/repos/shibbirweb/peep/releases/latest

# Otherwise the newest release of any kind (the first item).
curl -fsSL "https://api.github.com/repos/shibbirweb/peep/releases?per_page=1"
```

From the answer, take `tag_name` (for example `v0.1.0-beta.2`). Below, `TAG` is that tag and `VERSION` is the tag without the `v` (`0.1.0-beta.2`). With the GitHub CLI: `gh release list --repo shibbirweb/peep --limit 1`.

Every file of a release downloads from:

```text
https://github.com/shibbirweb/peep/releases/download/TAG/FILE
```

## 2. Look at the machine and the project

Find out, without asking:

| What | How |
|------|-----|
| Operating system and CPU | `uname -sm` (macOS: `Darwin arm64` is Apple silicon, `Darwin x86_64` is Intel). Windows PowerShell: `$env:PROCESSOR_ARCHITECTURE` |
| Whether a viewer already runs | `curl -fsS http://127.0.0.1:23517/_peep/health` answers `{"app":"peep",...}` when one does |
| The project | The folder with `composer.json`. Laravel when there is an `artisan` file and `laravel/framework` in `composer.json` |
| The project's PHP | `php -v` (any PHP from 7.1 to 8.5 works) |
| Docker | `docker-compose.yml`, `compose.yaml` or Laravel Sail (`vendor/bin/sail`); the app then runs in a container |

Ask the user only when you cannot tell: which project (if there are several), and whether they want the desktop app or the browser viewer when step 3's default does not fit.

## 3. Install the viewer

If step 2 found a viewer already running, skip to step 4.

Choose:

- **Desktop app** (default): for a machine with a desktop (macOS, Windows, Linux with a desktop). It brings its own PHP 8.5.
- **Browser viewer** (`peep-server.phar`): for a machine without a desktop (a server, WSL without a GUI), or when the user asks for it. It needs PHP 8.5 on the machine: check `php -v`.

The desktop app is not signed with an Apple or Microsoft certificate yet. Installing it from the terminal as below avoids the "unidentified developer" prompts, because a file downloaded with `curl` or PowerShell is not marked as coming from the internet. Tell the user that the app is unsigned.

### macOS

`ARCH` is `aarch64` on Apple silicon and `x64` on Intel.

```bash
curl -fL -o /tmp/Peep.dmg "https://github.com/shibbirweb/peep/releases/download/TAG/Peep_VERSION_ARCH.dmg"
hdiutil attach -nobrowse -quiet /tmp/Peep.dmg -mountpoint /tmp/peep-dmg
rm -rf /Applications/Peep.app
cp -R /tmp/peep-dmg/Peep.app /Applications/
hdiutil detach -quiet /tmp/peep-dmg
rm /tmp/Peep.dmg
xattr -dr com.apple.quarantine /Applications/Peep.app 2>/dev/null || true
open -a Peep
```

Quit Peep first if it is running (`osascript -e 'quit app "Peep"'`), since the copy replaces it.

### Windows (PowerShell)

```powershell
$installer = "$env:TEMP\Peep-setup.exe"
Invoke-WebRequest -Uri "https://github.com/shibbirweb/peep/releases/download/TAG/Peep_VERSION_x64-setup.exe" -OutFile $installer
Start-Process $installer -ArgumentList "/S" -Wait
Remove-Item $installer
```

`/S` installs silently for the current user, without administrator rights. Then ask the user to start **Peep** from the Start menu, or start it yourself:

```powershell
Start-Process (Get-ChildItem "$env:LOCALAPPDATA\Peep" -Filter *.exe | Where-Object Name -notmatch 'uninstall' | Select-Object -First 1).FullName
```

If Windows 11's Smart App Control blocks it, use the browser viewer instead; do not turn Smart App Control off.

### Linux

`ARCH` is `amd64` or `arm64` for the `.deb`, `amd64` or `aarch64` for the AppImage.

On Ubuntu and Debian (needs `sudo`: ask first):

```bash
curl -fL -o /tmp/peep.deb "https://github.com/shibbirweb/peep/releases/download/TAG/Peep_VERSION_ARCH.deb"
sudo apt install -y /tmp/peep.deb
rm /tmp/peep.deb
```

Anywhere else, the AppImage (no `sudo`):

```bash
mkdir -p ~/Applications
curl -fL -o ~/Applications/Peep.AppImage "https://github.com/shibbirweb/peep/releases/download/TAG/Peep_VERSION_ARCH.AppImage"
chmod +x ~/Applications/Peep.AppImage
nohup ~/Applications/Peep.AppImage >/dev/null 2>&1 &
```

If it says FUSE is missing, ask the user to install `libfuse2` (`libfuse2t64` on Ubuntu 24.04 and later).

### Browser viewer

```bash
mkdir -p ~/.peep
curl -fL -o ~/.peep/peep-server.phar "https://github.com/shibbirweb/peep/releases/download/TAG/peep-server.phar"
php ~/.peep/peep-server.phar serve --open=none
```

`serve` runs until it is stopped. Start it as a background process (or ask the user to run it in a terminal they keep open), and tell the user how to start it again later: `php ~/.peep/peep-server.phar serve`. The viewer is then at http://127.0.0.1:23517.

### Check

Wait a few seconds, then:

```bash
curl -fsS http://127.0.0.1:23517/_peep/health
```

It answers `{"app":"peep","protocol":1,"version":"..."}`. If not, see [Troubleshooting](docs/wiki/usage/Troubleshooting.md).

## 4. Add the package to the project

In the project folder. For a beta (step 1 found no stable release):

```bash
composer require --dev "shibbirweb/peep:@beta"
```

For a stable release:

```bash
composer require --dev shibbirweb/peep
```

With Laravel Sail, run it through Sail: `vendor/bin/sail composer require --dev ...`. Laravel discovers the package by itself; nothing else to register.

## 5. Turn Peep on

| Project | Do |
|---------|----|
| Laravel | Nothing. Peep is on whenever `APP_ENV` is not `production`. Check the local `.env` says `APP_ENV=local` |
| Other PHP, loads `.env` (for example with `vlucas/phpdotenv`) | Add `PEEP_ENABLED=true` to the local `.env`, which is not committed |
| Other PHP, no `.env` | Create `peep.php` in the project root returning `['enabled' => true];` and add `peep.php` to `.gitignore` |

When the app runs in Docker (Sail or a compose file), the container must reach the viewer on the host. Add to the app's local `.env`:

```dotenv
PEEP_HOST=host.docker.internal
PEEP_REMOTE_PATH=/var/www/html
PEEP_LOCAL_PATH=/absolute/path/of/the/project/on/the/host
```

(`/var/www/html` is the app's folder inside a Sail container; use the real one.) On Linux, Docker also needs `extra_hosts: ["host.docker.internal:host-gateway"]` on the app's service, and the viewer needs a token: see [Docker and other machines](docs/wiki/usage/Installation.md#docker-and-other-machines).

## 6. Prove it works

Send one test card from the project and read it back. `vendor/bin/peep` reads the viewer.

Laravel (with Sail: `vendor/bin/sail artisan ...`):

```bash
php artisan tinker --execute="peep('Peep is installed');"
```

`--execute` needs laravel/tinker 2.4 or later. On an older Laravel, or without Tinker, use the next command instead.

Other PHP, or any PHP project (the variable turns Peep on for this one command):

```bash
PEEP_ENABLED=true php -r 'require "vendor/autoload.php"; peep("Peep is installed");'
```

Then:

```bash
vendor/bin/peep cards --search="Peep is installed"
```

It prints the card. If it says *No cards match*, the package cannot reach the viewer or Peep is off: check step 5, and `vendor/bin/peep status`. Then clear the test card: `vendor/bin/peep clear`.

## 7. Offer the extras

Ask the user whether they want these; do not install them unasked.

- **Peep for AI agents**: `vendor/bin/peep skill --install` adds the Peep skill (`.claude/skills/peep/SKILL.md`), which teaches agents to debug with Peep. `claude mcp add peep -- php vendor/bin/peep mcp` adds Peep's MCP tools in Claude Code; other MCP clients take `{"command": "php", "args": ["vendor/bin/peep", "mcp"]}`. See [AI agents](docs/wiki/usage/AI-Agents.md).
- **Laravel mail and SMS**: `MAIL_MAILER=peep` in the local `.env` sends every mail to the viewer's inbox instead of real people. See [Mail and SMS](docs/wiki/usage/Mail-and-SMS.md).
- **Query debugging**: switch it on in the viewer's toolbar, no code change. See [Query debugging](docs/wiki/usage/Query-Debugging.md).

## 8. Tell the user

In a few lines:

- The version installed, and that it is a beta if it is.
- Which viewer, and how to open it again (the Peep app, or `php ~/.peep/peep-server.phar serve`).
- That the desktop app is unsigned, and on a Mac they may be asked to confirm it once if they ever reinstall it from a browser download.
- How to use it: `peep($anything)` in their code shows up in the viewer.
- Anything you skipped or that failed, with the exact message.

## Updating, and removing

- **Update**: the desktop app updates itself (it checks daily and asks). The package: `composer update shibbirweb/peep`. The browser viewer: download the newer `peep-server.phar` over the old one.
- **Remove**: `composer remove --dev shibbirweb/peep` and delete any `peep()` calls left (`vendor/bin/peep-clean --fix` lists and removes them, before the package is removed). The app: delete `/Applications/Peep.app` (macOS), uninstall **Peep** in Settings, Apps (Windows), remove the package (Linux: `dpkg -l | grep -i peep` shows its name, then `sudo apt remove` it) or delete the AppImage.
