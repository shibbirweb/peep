/**
 * A viewer full of demo data and a headless Chrome to show it, for scripts/screenshots.ts and
 * scripts/video.ts.
 *
 * Starts the viewer from server/ (needs PHP 8.5, Composer dependencies and a built UI:
 * cd server && composer install && composer build:ui) with a scratch settings folder, sends
 * demo data (scripts/screenshots/demo.php and a storage/logs folder), and starts a small app
 * that webhooks call back. Chrome is driven over the DevTools protocol; set CHROME to the
 * browser binary when it is not in the default place.
 */

import { mkdirSync, mkdtempSync, rmSync, utimesSync, writeFileSync } from "node:fs";
import { createServer } from "node:net";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

export const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));

const CHROME =
  process.env.CHROME ??
  (process.platform === "darwin" ? "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" : "google-chrome");

export interface Demo {
  /** The viewer's address, e.g. http://127.0.0.1:50123. */
  viewer: string;
  port: number;
  /** The demo shop's storage/logs. */
  logFolder: string;
  stop(): void;
}

/** One DevTools connection to a page: commands, evaluation and events. */
export class DevTools {
  private id = 0;
  private pending = new Map<number, (value: { result?: Record<string, unknown> }) => void>();
  private listeners = new Map<string, ((params: Record<string, unknown>) => void)[]>();

  constructor(private socket: WebSocket) {
    socket.onmessage = (event) => {
      const message = JSON.parse(String(event.data));

      if (message.id !== undefined) {
        this.pending.get(message.id)?.(message);
        this.pending.delete(message.id);
      } else {
        for (const listener of this.listeners.get(message.method) ?? []) {
          listener(message.params ?? {});
        }
      }
    };
  }

  send(method: string, params: object = {}): Promise<{ result?: Record<string, unknown> }> {
    const id = ++this.id;
    this.socket.send(JSON.stringify({ id, method, params }));
    return new Promise((resolve) => this.pending.set(id, resolve));
  }

  on(method: string, listener: (params: Record<string, unknown>) => void): void {
    this.listeners.set(method, [...(this.listeners.get(method) ?? []), listener]);
  }

  async eval(expression: string): Promise<unknown> {
    const answer = await this.send("Runtime.evaluate", { expression, awaitPromise: true, returnByValue: true });
    return (answer.result?.result as { value?: unknown } | undefined)?.value;
  }

  close(): void {
    this.socket.close();
  }
}

export function freePort(): Promise<number> {
  return new Promise((resolve) => {
    const server = createServer();
    server.listen(0, "127.0.0.1", () => {
      const address = server.address();
      server.close(() => resolve(typeof address === "object" && address ? address.port : 0));
    });
  });
}

export async function waitFor(check: () => Promise<boolean>, what: string): Promise<void> {
  for (let attempt = 0; attempt < 100; attempt++) {
    if (await check().catch(() => false)) {
      return;
    }
    await Bun.sleep(100);
  }
  throw new Error(`${what} did not come up`);
}

/** A UI action, with the header the viewer asks of them. */
export async function post(url: string, body: unknown): Promise<unknown> {
  const response = await fetch(url, { method: "POST", headers: { "Content-Type": "application/json", "X-Peep-UI": "1" }, body: JSON.stringify(body) });
  return response.json();
}

/** Starts the viewer and fills it with the demo shop. */
export async function startDemo(): Promise<Demo> {
  const config = mkdtempSync(join(tmpdir(), "peep-demo-"));
  const port = await freePort();
  const appPort = await freePort();
  const viewer = `http://127.0.0.1:${port}`;
  writeFileSync(join(config, "settings.json"), JSON.stringify({ theme: "light", smtp: false, history: false }));

  const env = { ...process.env, PEEP_CONFIG_DIR: config, PEEP_PORT: String(port), APP_PORT: String(appPort) };
  const processes = [
    Bun.spawn(["php", "-d", "zend.assertions=-1", "server/bin/peep-server", "serve", `--port=${port}`, "--open=none"], { cwd: ROOT, env, stdout: "ignore", stderr: "ignore" }),
    Bun.spawn(["php", "-S", `127.0.0.1:${appPort}`, "scripts/screenshots/receiver.php"], { cwd: ROOT, stdout: "ignore", stderr: "ignore" }),
  ];
  const removable = [config];
  const stop = () => {
    for (const process of processes) {
      process.kill();
    }
    for (const folder of removable) {
      rmSync(folder, { recursive: true, force: true });
    }
  };

  try {
    await waitFor(async () => (await fetch(`${viewer}/_peep/health`)).ok, "The viewer");
    Bun.spawnSync(["php", "scripts/screenshots/demo.php"], { cwd: ROOT, env });

    // Webhook rules from the presets, pointed at the demo app, and one delivery of each.
    const presets = ((await (await fetch(`${viewer}/_peep/ui/webhooks/presets`)).json()) as { presets: { id: string; rule: object }[] }).presets;
    const mailgun = (await post(`${viewer}/_peep/ui/webhooks/rules`, {
      rule: { ...presets.find((preset) => preset.id === "mailgun")?.rule, url: `http://127.0.0.1:${appPort}/webhooks/mailgun` },
    })) as { rule: { id: string } };
    await post(`${viewer}/_peep/ui/webhooks/rules`, { rule: presets.find((preset) => preset.id === "twilio")?.rule });
    await post(`${viewer}/_peep/ui/webhooks/rules/${mailgun.rule.id}/test`, { event: "accepted" });
    await post(`${viewer}/_peep/ui/webhooks/rules/${mailgun.rule.id}/test`, { event: "delivered" });
    await post(`${viewer}/_peep/ui/switches`, { project: "shop", switches: { slow_query_ms: 250 } });
    const logs = await demoLogs(viewer);
    removable.push(logs.root);

    return { viewer, port, logFolder: logs.folder, stop };
  } catch (error) {
    stop();
    throw error;
  }
}

/** Starts headless Chrome at the given size and connects to its page. */
export async function startChrome(width: number, height: number): Promise<{ page: DevTools; stop(): void }> {
  const debugPort = await freePort();
  const profile = mkdtempSync(join(tmpdir(), "peep-chrome-"));
  const chrome = Bun.spawn(
    [CHROME, "--headless=new", `--remote-debugging-port=${debugPort}`, `--user-data-dir=${profile}`, "--no-first-run", "--hide-scrollbars", `--window-size=${width},${height}`, "about:blank"],
    { stdout: "ignore", stderr: "ignore" },
  );
  const stop = () => {
    chrome.kill();
    rmSync(profile, { recursive: true, force: true });
  };

  let target: { webSocketDebuggerUrl: string } | undefined;
  await waitFor(async () => {
    target = ((await (await fetch(`http://127.0.0.1:${debugPort}/json`)).json()) as { type: string; webSocketDebuggerUrl: string }[]).find((each) => each.type === "page");
    return target !== undefined;
  }, "Chrome");

  const socket = new WebSocket(target?.webSocketDebuggerUrl ?? "");
  await new Promise((resolve) => (socket.onopen = resolve));
  const page = new DevTools(socket);
  await page.send("Page.enable");
  await page.send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile: false });

  return {
    page,
    stop: () => {
      page.close();
      stop();
    },
  };
}

/** Sends one card to the viewer, as an app would. */
export async function sendCard(viewer: string, card: string, request: { id: string; kind: string; label: string }, payloads: object[], project = "shop"): Promise<void> {
  const now = Date.now() / 1000;
  await fetch(`${viewer}/_peep/envelope`, {
    method: "POST",
    body: JSON.stringify({
      protocol: 1,
      card,
      payloads,
      meta: { project, hostname: "dev", php_version: "8.4.12", peep_version: "1.0.0", request: { ...request, started_at: now }, sent_at: now },
    }),
  });
}

/**
 * A storage/logs folder for the shop, reported the way the Laravel adapter does it. It lives
 * under /tmp (not the long per-user temp folder) because the Logs tab shows its path.
 */
async function demoLogs(viewer: string): Promise<{ folder: string; root: string }> {
  const root = mkdtempSync(join(process.platform === "win32" ? tmpdir() : "/tmp", "peep-shop-"));
  const folder = join(root, "storage", "logs");
  mkdirSync(folder, { recursive: true });
  writeFileSync(join(folder, "laravel-2026-10-22.log"), '[2026-10-22 18:02:11] local.INFO: Deployed {"release":"v2.14.0"} \n');
  const yesterday = new Date(Date.now() - 86400000);
  utimesSync(join(folder, "laravel-2026-10-22.log"), yesterday, yesterday);
  writeFileSync(
    join(folder, "laravel.log"),
    String.raw`[2026-10-23 09:41:02] local.INFO: Order placed {"order_id":1042,"total":129.9,"items":3}
[2026-10-23 09:41:02] local.DEBUG: Cache miss {"key":"shipping.rates.de"}
[2026-10-23 09:41:03] local.WARNING: Slow payment gateway response {"gateway":"stripe","ms":2380}
[2026-10-23 09:41:05] local.ERROR: Card declined {"order_id":1042,"customer":{"id":77,"email":"ada@example.test"},"exception":"[object] (App\\Exceptions\\PaymentDeclined(code: 402): Card declined at /Users/you/code/shop/app/Services/PaymentService.php:58)
[stacktrace]
#0 /Users/you/code/shop/app/Http/Controllers/CheckoutController.php(34): App\\Services\\PaymentService->charge()
#1 /Users/you/code/shop/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): App\\Http\\Controllers\\CheckoutController->store()
#2 {main}
"}
[2026-10-23 09:41:06] local.NOTICE: Customer notified {"customer":"ada@example.test","channel":"mail"}
[2026-10-23 09:41:09] local.INFO: Order placed {"order_id":1043,"total":49,"items":1}
[2026-10-23 09:41:12] local.CRITICAL: Queue worker stopped {"queue":"default","reason":"Allowed memory size exhausted"}
`,
  );

  const now = Date.now() / 1000;
  await fetch(`${viewer}/_peep/envelope`, {
    method: "POST",
    body: JSON.stringify({
      protocol: 1,
      card: "logs-card",
      payloads: [{ type: "log", content: { values: [{ type: "string", value: "Logs ready", length: 10 }] }, origin: { file: "/Users/you/code/shop/routes/web.php", line: 8 } }],
      meta: { project: "shop", hostname: "dev", php_version: "8.4.12", peep_version: "1.0.0", request: { id: "logs", kind: "cli", label: "artisan about", started_at: now }, sent_at: now, logs: { path: folder, timezone: "UTC" } },
    }),
  });

  return { folder, root };
}
