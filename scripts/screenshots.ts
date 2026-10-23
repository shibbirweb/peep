#!/usr/bin/env bun
/**
 * Takes the wiki's screenshots of the viewer: docs/wiki/images/*.png.
 *
 *     bun scripts/screenshots.ts            every screenshot
 *     bun scripts/screenshots.ts mail sms   only these
 *
 * The viewer, its demo data and Chrome come from scripts/demo.ts (see there for what it needs).
 */

import { writeFileSync } from "node:fs";
import { join } from "node:path";
import { DevTools, ROOT, sendCard, startChrome, startDemo } from "./demo.ts";

const IMAGES = join(ROOT, "docs", "wiki", "images");
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
  constructor(
    private devTools: DevTools,
    private viewer: string,
  ) {}

  send(method: string, params: object = {}): Promise<{ result?: Record<string, unknown> }> {
    return this.devTools.send(method, params);
  }

  eval(expression: string): Promise<unknown> {
    return this.devTools.eval(expression);
  }

  async click(selector: string, text: string): Promise<void> {
    await this.eval(`[...document.querySelectorAll(${JSON.stringify(selector)})].find((element) => element.textContent.includes(${JSON.stringify(text)}))?.click()`);
    await Bun.sleep(300);
  }

  /** A paused request, for the pause banner. */
  async pause(): Promise<void> {
    await sendCard(this.viewer, "pause-card", { id: "pause", kind: "http", label: "POST /checkout" }, [
      { type: "lock", content: { name: "checkout-pause" }, origin: { file: "/Users/you/code/shop/app/Http/Controllers/CheckoutController.php", line: 21 } },
    ]);
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
    writeFileSync(join(IMAGES, `${file}.png`), Buffer.from(String(answer.result?.data ?? ""), "base64"));
  }
}

async function main(): Promise<void> {
  const wanted = process.argv.slice(2);
  const demo = await startDemo();

  try {
    const chrome = await startChrome(WIDTH, HEIGHT);
    const page = new Page(chrome.page, demo.viewer);

    try {
      for (const [name, step] of Object.entries(SHOTS)) {
        if (wanted.length > 0 && !wanted.includes(name)) {
          continue;
        }

        await page.send("Page.navigate", { url: demo.viewer });
        await Bun.sleep(1500);
        await step(page);
        await Bun.sleep(500);
        await page.shot(name);
        console.log(`docs/wiki/images/${name}.png`);
      }
    } finally {
      chrome.stop();
    }
  } finally {
    demo.stop();
  }
}

await main();
