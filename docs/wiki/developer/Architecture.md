# Architecture

One repository, three programs, one protocol between them.

```mermaid
flowchart TB
    subgraph Client["Client: shibbirweb/peep (src/, PHP 7.1+)"]
        Helper["peep(), Peep card API"] --> Serializer["ValueSerializer and casters"]
        Laravel["Laravel adapter: watchers, macros, peep mailer"] --> Helper
        Bridges["PSR-3, Monolog, VarDumper, error handler"] --> Helper
        Serializer --> Dispatcher["Dispatcher: availability cache, rate limit, switches"]
        Dispatcher --> Transport["Stream or curl transport"]
    end
    subgraph Viewer["Viewer: server/ (PHP 8.5, AMPHP)"]
        Http["HTTP server and guards"] --> Cards["CardStore + SQLite history"]
        Http --> Inbox["Mail and SMS inboxes"]
        Smtp["SMTP server"] --> Inbox
        Inbox --> Webhooks["Webhook runner"]
        Cards --> Hub["Event hub (Server-Sent Events)"]
        Inbox --> Hub
        Webhooks --> Hub
    end
    subgraph UI["UI: server/resources/ui (Svelte 5)"]
        Page["Cards, Mail, SMS, Webhooks, Settings"]
    end
    subgraph Desktop["Desktop app: desktop/ (Tauri 2)"]
        Shell["Window, tray, updates"] --> Sidecar["Bundled PHP 8.5 + peep-server.phar"]
    end
    Transport -- "POST /_peep/envelope" --> Http
    Hub -- "GET /_peep/stream" --> Page
    Page -- "POST /_peep/ui/*" --> Http
    Webhooks -. "signed callbacks" .-> App["Your app"]
    Sidecar --> Http
    Shell --> Page
```

## One `peep()` call

```mermaid
sequenceDiagram
    participant Code as Your code
    participant Peep as Peep card
    participant D as Dispatcher
    participant V as Viewer
    participant UI as Viewer page
    Code->>Peep: peep($order)->green()
    Peep->>Peep: is it on, and is the viewer known to be up?
    Peep->>D: payloads (value tree, color)
    D->>V: POST /_peep/envelope {card, payloads, meta}
    V-->>D: 202 {switches, switches_ttl}
    V->>UI: card.created (SSE)
    Note over D: a failed connection marks the viewer down for 30 s
```

## Where things live

| Folder | What | Notes |
|--------|------|-------|
| `src/` | The client, the only Packagist package | PHP 7.1 syntax, no `declare(strict_types)`, no warnings on any PHP |
| `src/Laravel/` | Laravel adapter | Auto-discovered; registers nothing when Peep is off |
| `server/` | The viewer | PHP 8.5, AMPHP v3; shipped as `peep-server.phar`, not on Packagist |
| `server/resources/ui/` | The viewer page | Svelte 5, built with Bun and Vite into `server/public/` |
| `desktop/` | The desktop app | Tauri 2, Rust; runs the viewer as a sidecar |
| `scripts/` | Release, wiki and screenshot scripts | Bun |
| `docs/wiki/` | This wiki | Published by CI |

The [protocol](Protocol.md) is the contract between the client and the viewer; the plan documents in `docs/plan/` explain each feature's design.
