#!/usr/bin/env bun
/**
 * Takes the wiki's screenshots of the viewer: docs/wiki/images/*.png.
 *
 *     bun scripts/screenshots.ts            every screenshot
 *     bun scripts/screenshots.ts mail sms   only these
 *
 * Starts the viewer from server/ (needs PHP 8.5, Composer dependencies and a built UI:
 * cd server && composer install && composer build:ui) with a scratch settings folder, sends
 * demo data (scripts/screenshots/demo.php), starts a small app that webhooks call back, and
 * drives headless Chrome over the DevTools protocol. Set CHROME to the browser binary when
 * it is not in the default place.
 */

import { mkdirSync, mkdtempSync, rmSync, utimesSync, writeFileSync } from "node:fs";
import { createServer } from "node:net";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const IMAGES = join(ROOT, "docs", "wiki", "images");
const CHROME =
  process.env.CHROME ??
  (process.platform === "darwin" ? "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" : "google-chrome");
const WIDTH = 1280;
const HEIGHT = 820;

type Step = (page: Page) => Promise<void>;

/** Every screenshot: its file name and how to get the page there. */
const SHOTS: Record<string, Step> = {
  viewer: async (page) => {
    await page.tab("Cards");
    await page.project("");
  },
  "viewer-dark": async (page) => {
    await page.tab("Cards");
    await page.project("");
    await page.eval("document.documentElement.dataset.theme = 'dark'");
  },
  filters: async (page) => {
    await page.tab("Cards");
    await page.project("shop");
    await page.eval("[...document.querySelectorAll('.chip')].find((chip) => chip.textContent === 'Queries').click()");
  },
  search: async (page) => {
    await page.tab("Cards");
    await page.project("shop");
    await page.eval("{ const input = document.querySelector('input.search'); input.value = 'declined'; input.dispatchEvent(new Event('input', { bubbles: true })); }");
    await Bun.sleep(400);
  },
  "query-switches": async (page) => {
    await page.tab("Cards");
    await page.project("shop");
    await page.eval("{ const select = document.querySelector('.switches select'); select.value = 'on'; select.dispatchEvent(new Event('change', { bubbles: true })); }");
    await Bun.sleep(400);
  },
  settings: async (page) => {
    await page.tab("Cards");
    await page.click(".toolbar button", "Settings");
    await Bun.sleep(600);
  },
  mail: async (page) => {
    await page.tab("Mail");
    await page.click(".item", "Order #1042");
    await Bun.sleep(3000);
  },
  "mail-mobile": async (page) => {
    await page.tab("Mail");
    await page.click(".item", "Order #1042");
    await Bun.sleep(800);
    await page.click(".tabs button", "Mobile");
    await Bun.sleep(3000);
  },
  sms: async (page) => {
    await page.tab("SMS");
  },
  "delivery-log": async (page) => {
    await page.tab("Webhooks");
    await page.eval("document.querySelector('aside .item')?.click()");
    await Bun.sleep(400);
    await page.eval("document.querySelector('.delivery .line')?.click()");
    await Bun.sleep(600);
    await page.eval("document.querySelector('.log').scrollIntoView()");
    await Bun.sleep(300);
  },
  logs: async (page) => {
    await page.tab("Logs");
    await page.project("shop");
    await Bun.sleep(1500);
    await page.click(".entry .head", "PaymentDeclined");
    await Bun.sleep(400);
  },
  // Last: the paused request stays on screen.
  pause: async (page) => {
    await page.pause();
    await page.tab("Cards");
    await page.project("shop");
  },
  webhooks: async (page) => {
    await page.tab("Webhooks");
    await page.eval("document.querySelector('aside .item')?.click()");
    await Bun.sleep(400);
    await page.eval("document.querySelector('.delivery .line')?.click()");
    await Bun.sleep(600);
  },
};

class Page {
  private id = 0;
  private pending = new Map<number, (value: { result?: { result?: { value?: unknown } } }) => void>();

  constructor(
    private socket: WebSocket,
    private viewer: string,
  ) {
    socket.onmessage = (event) => {
      const message = JSON.parse(String(event.data));
      this.pending.get(message.id)?.(message);
      this.pending.delete(message.id);
    };
  }

  send(method: string, params: object = {}): Promise<{ result?: { result?: { value?: unknown }; data?: string } }> {
    const id = ++this.id;
    this.socket.send(JSON.stringify({ id, method, params }));
    return new Promise((resolve) => this.pending.set(id, resolve));
  }

  async eval(expression: string): Promise<unknown> {
    const answer = await this.send("Runtime.evaluate", { expression, awaitPromise: true, returnByValue: true });
    return answer.result?.result?.value;
  }

  async click(selector: string, text: string): Promise<void> {
    await this.eval(`[...document.querySelectorAll(${JSON.stringify(selector)})].find((element) => element.textContent.includes(${JSON.stringify(text)}))?.click()`);
    await Bun.sleep(300);
  }

  /** A paused request, for the pause banner. */
  async pause(): Promise<void> {
    const now = Date.now() / 1000;
    await fetch(`${this.viewer}/_peep/envelope`, {
      method: "POST",
      body: JSON.stringify({
        protocol: 1,
        card: "pause-card",
        payloads: [{ type: "lock", content: { name: "checkout-pause" }, origin: { file: "/Users/you/code/shop/app/Http/Controllers/CheckoutController.php", line: 21 } }],
        meta: { project: "shop", hostname: "dev", php_version: "8.4.12", peep_version: "1.0.0", request: { id: "pause", kind: "http", label: "POST /checkout", started_at: now }, sent_at: now },
      }),
    });
    await Bun.sleep(300);
  }

  tab(name: string): Promise<void> {
    return this.click("nav.views button", name);
  }

  async project(name: string): Promise<void> {
    await this.eval(
      `[...document.querySelectorAll('nav.projects button')].find((button) => ${name === "" ? "button.textContent.trim().startsWith('All')" : `button.textContent.trim().startsWith(${JSON.stringify(name)})`})?.click()`,
    );
    await Bun.sleep(300);
  }

  async shot(file: string): Promise<void> {
    const answer = await this.send("Page.captureScreenshot", { format: "png" });
    writeFileSync(join(IMAGES, `${file}.png`), Buffer.from(answer.result?.data ?? "", "base64"));
  }
}

/** A storage/logs folder for the shop, reported the way the Laravel adapter does it. */
async function demoLogs(viewer: string, config: string): Promise<void> {
  const folder = join(config, "shop-logs");
  mkdirSync(folder);
  writeFileSync(join(folder, "laravel-2026-10-22.log"), "[2026-10-22 18:02:11] local.INFO: Deployed {\"release\":\"v2.14.0\"} \n");
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
}

function freePort(): Promise<number> {
  return new Promise((resolve) => {
    const server = createServer();
    server.listen(0, "127.0.0.1", () => {
      const address = server.address();
      server.close(() => resolve(typeof address === "object" && address ? address.port : 0));
    });
  });
}

async function waitFor(check: () => Promise<boolean>, what: string): Promise<void> {
  for (let attempt = 0; attempt < 100; attempt++) {
    if (await check().catch(() => false)) {
      return;
    }
    await Bun.sleep(100);
  }
  throw new Error(`${what} did not come up`);
}

async function post(url: string, body: unknown): Promise<unknown> {
  const response = await fetch(url, { method: "POST", headers: { "Content-Type": "application/json", "X-Peep-UI": "1" }, body: JSON.stringify(body) });
  return response.json();
}

async function main(): Promise<void> {
  const wanted = process.argv.slice(2);
  const config = mkdtempSync(join(tmpdir(), "peep-screenshots-"));
  const port = await freePort();
  const appPort = await freePort();
  const debugPort = await freePort();
  const viewer = `http://127.0.0.1:${port}`;
  writeFileSync(join(config, "settings.json"), JSON.stringify({ theme: "light", smtp: false, history: false }));

  const env = { ...process.env, PEEP_CONFIG_DIR: config, PEEP_PORT: String(port), APP_PORT: String(appPort) };
  const processes = [
    Bun.spawn(["php", "-d", "zend.assertions=-1", "server/bin/peep-server", "serve", `--port=${port}`, "--open=none"], { cwd: ROOT, env, stdout: "ignore", stderr: "ignore" }),
    Bun.spawn(["php", "-S", `127.0.0.1:${appPort}`, "scripts/screenshots/receiver.php"], { cwd: ROOT, stdout: "ignore", stderr: "ignore" }),
  ];

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
    await demoLogs(viewer, config);

    const profile = mkdtempSync(join(tmpdir(), "peep-chrome-"));
    processes.push(
      Bun.spawn([CHROME, "--headless=new", `--remote-debugging-port=${debugPort}`, `--user-data-dir=${profile}`, "--no-first-run", "--hide-scrollbars", `--window-size=${WIDTH},${HEIGHT}`, "about:blank"], { stdout: "ignore", stderr: "ignore" }),
    );

    let target: { webSocketDebuggerUrl: string } | undefined;
    await waitFor(async () => {
      target = ((await (await fetch(`http://127.0.0.1:${debugPort}/json`)).json()) as { type: string; webSocketDebuggerUrl: string }[]).find((each) => each.type === "page");
      return target !== undefined;
    }, "Chrome");

    const socket = new WebSocket(target?.webSocketDebuggerUrl ?? "");
    await new Promise((resolve) => (socket.onopen = resolve));
    const page = new Page(socket, viewer);
    await page.send("Page.enable");
    await page.send("Emulation.setDeviceMetricsOverride", { width: WIDTH, height: HEIGHT, deviceScaleFactor: 1, mobile: false });

    for (const [name, step] of Object.entries(SHOTS)) {
      if (wanted.length > 0 && !wanted.includes(name)) {
        continue;
      }

      await page.send("Page.navigate", { url: viewer });
      await Bun.sleep(1500);
      await step(page);
      await Bun.sleep(500);
      await page.shot(name);
      console.log(`docs/wiki/images/${name}.png`);
    }

    socket.close();
    rmSync(profile, { recursive: true, force: true });
  } finally {
    for (const process of processes) {
      process.kill();
    }
    rmSync(config, { recursive: true, force: true });
  }
}

await main();
