#!/usr/bin/env bun
/**
 * Records a two-minute tour of the viewer's main features, with captions and a cursor that
 * clicks and types: demo/peep-tour.mp4, and its poster for the README, demo/peep-tour.png.
 *
 *     bun scripts/video.ts
 *     bun scripts/video.ts --poster        only the poster, without recording
 *     TOUR_TIMES=1 bun scripts/video.ts    also prints when each scene starts and where it cuts
 *     TOUR_KEEP=1 bun scripts/video.ts     keeps the frames and the in-between files in build/video
 *
 * Needs ffmpeg, and what scripts/demo.ts needs (PHP 8.5, the server's Composer dependencies,
 * a built UI and Chrome). Chrome sends a frame whenever the page changes (screencast); each
 * frame is held until the next one, so the video plays at the speed it was recorded.
 *
 * Scenes are joined with transitions (ffmpeg xfade), and the music is synthesized by
 * scripts/music.ts, an original track with a whoosh on every transition, so the video can be
 * uploaded anywhere without a license question.
 */

import { appendFileSync, mkdirSync, readFileSync, rmSync, symlinkSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { DevTools, ROOT, sendCard, startChrome, startDemo } from "./demo.ts";
import { INTRO_SECONDS, promoTrack, writeWav } from "./music.ts";

const WIDTH = 1280;
const HEIGHT = 800;
const FPS = 30;
const DEMO = join(ROOT, "demo");
const OUT = join(ROOT, "build", "video");
const FRAMES = join(OUT, "frames");
const SEQUENCE = join(OUT, "sequence");

/** Seconds each transition takes, and the transition between each pair of scenes, in turn. */
const TRANSITION = 0.6;
const TRANSITIONS = ["fade", "smoothleft", "circleopen", "slideup", "smoothright", "wipeleft", "smoothdown", "smoothup", "circleopen", "slideleft", "fadeblack", "fade"];

/** Cursor, captions and title cards, drawn over every page the tour opens. */
const OVERLAY = String.raw`(() => {
  const install = () => {
    if (document.getElementById('tour-cursor')) return;
    const style = document.createElement('style');
    style.textContent = ${"`"}
      #tour-cursor { position: fixed; left: 0; top: 0; z-index: 2147483647; pointer-events: none; transform: translate(640px, 420px); filter: drop-shadow(0 2px 3px rgba(0,0,0,.35)); }
      #tour-ring { position: fixed; left: 0; top: 0; z-index: 2147483646; width: 36px; height: 36px; margin: -18px 0 0 -18px; border-radius: 50%; border: 3px solid #5b5bd6; opacity: 0; pointer-events: none; }
      #tour-ring.on { animation: tour-ring .5s ease-out; }
      @keyframes tour-ring { from { opacity: .9; transform: var(--at) scale(.4); } to { opacity: 0; transform: var(--at) scale(1.6); } }
      #tour-caption { position: fixed; left: 50%; bottom: 28px; z-index: 2147483645; transform: translateX(-50%); max-width: 82%; padding: 12px 22px; border-radius: 14px; background: rgba(17,17,19,.86); color: #fff; font: 500 20px/1.35 system-ui, -apple-system, sans-serif; text-align: center; box-shadow: 0 8px 30px rgba(0,0,0,.25); opacity: 0; transition: opacity .35s; pointer-events: none; }
      #tour-caption.on { opacity: 1; }
      #tour-caption b { color: #a9a9ff; font-weight: 700; margin-right: 8px; }
      #tour-title { position: fixed; inset: 0; z-index: 2147483644; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px; background: radial-gradient(circle at 50% 40%, #2b2b6e, #111113 70%); color: #fff; font-family: system-ui, -apple-system, sans-serif; opacity: 0; transition: opacity .6s; pointer-events: none; }
      #tour-title.on { opacity: 1; }
      #tour-title h1 { margin: 0; font-size: 84px; letter-spacing: -.02em; }
      #tour-title p { margin: 0; font-size: 26px; color: #c9c9ff; }
      #tour-title code { margin-top: 18px; padding: 10px 18px; border-radius: 10px; background: rgba(255,255,255,.1); font: 20px ui-monospace, Menlo, monospace; }
    ${"`"};
    document.head.appendChild(style);
    const cursor = document.createElement('div');
    cursor.id = 'tour-cursor';
    cursor.innerHTML = '<svg width="26" height="30" viewBox="0 0 26 30"><path d="M2 2 L2 24 L8 18.5 L12.5 28 L16.5 26.2 L12 17 L20 17 Z" fill="#111" stroke="#fff" stroke-width="2" stroke-linejoin="round"/></svg>';
    const ring = document.createElement('div');
    ring.id = 'tour-ring';
    const caption = document.createElement('div');
    caption.id = 'tour-caption';
    const title = document.createElement('div');
    title.id = 'tour-title';
    document.body.append(title, caption, ring, cursor);
    window.__tour = {
      move(x, y, ms) {
        cursor.style.transition = 'transform ' + ms + 'ms cubic-bezier(.45,0,.2,1)';
        cursor.style.transform = 'translate(' + x + 'px, ' + y + 'px)';
      },
      ring(x, y) {
        ring.style.setProperty('--at', 'translate(' + x + 'px, ' + y + 'px)');
        ring.style.transform = 'translate(' + x + 'px, ' + y + 'px)';
        ring.classList.remove('on');
        void ring.offsetWidth;
        ring.classList.add('on');
      },
      caption(name, text) {
        if (!text) { caption.classList.remove('on'); return; }
        caption.innerHTML = '';
        const strong = document.createElement('b');
        strong.textContent = name;
        caption.append(strong, document.createTextNode(text));
        caption.classList.add('on');
      },
      // A modal dialog draws above everything; the overlay joins it while it is open.
      into(element) {
        (element || document.body).append(caption, ring, cursor);
      },
      title(html) {
        if (!html) { title.classList.remove('on'); return; }
        title.innerHTML = html;
        title.classList.add('on');
      },
    };
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();`;

/** A terminal for the agent scene: a command is typed, then its output scrolls in. */
function terminalPage(output: string): string {
  const lines = JSON.stringify(output.split("\n"));
  return `<!doctype html><meta charset="utf-8"><title>Terminal</title>
<style>
  body { margin: 0; height: 100vh; background: #f6f7f9; display: flex; align-items: flex-start; justify-content: center; padding-top: 36px; box-sizing: border-box; font-family: system-ui, sans-serif; }
  .window { width: 1120px; height: 610px; border-radius: 12px; background: #16161a; box-shadow: 0 20px 60px rgba(0,0,0,.35); display: flex; flex-direction: column; overflow: hidden; }
  .bar { height: 34px; background: #26262c; display: flex; align-items: center; gap: 8px; padding: 0 14px; color: #9ba1a6; font-size: 13px; }
  .bar i { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
  pre { margin: 0; padding: 16px 20px; flex: 1; overflow: hidden; color: #e6e6e6; font: 14px/1.45 ui-monospace, Menlo, monospace; white-space: pre-wrap; }
  .prompt { color: #3dd68c; }
  .heading { color: #a9a9ff; font-weight: 700; }
  .problem { color: #ff9592; }
</style>
<div class="window"><div class="bar"><i style="background:#ff5f57"></i><i style="background:#febc2e"></i><i style="background:#28c840"></i><span style="margin-left:12px">~/code/shop</span></div><pre id="out"></pre></div>
<script>
  const out = document.getElementById('out');
  const lines = ${lines};
  const add = (html) => { out.insertAdjacentHTML('beforeend', html); out.scrollTop = out.scrollHeight; };
  const escape = (text) => text.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c]);
  window.term = {
    type(command) {
      add('<span class="prompt">$ </span>');
      return new Promise((done) => {
        let index = 0;
        const timer = setInterval(() => {
          add(escape(command[index++]));
          if (index === command.length) { clearInterval(timer); add('\\n'); done(); }
        }, 55);
      });
    },
    print(perLine) {
      return new Promise((done) => {
        let index = 0;
        let problems = false;
        const timer = setInterval(() => {
          const line = lines[index++];
          if (line.startsWith('## ')) problems = line === '## Problems';
          const kind = line.startsWith('#') ? 'heading' : (problems && line.startsWith('- ') ? 'problem' : '');
          add((kind ? '<span class="' + kind + '">' + escape(line) + '</span>' : escape(line)) + '\\n');
          if (index === lines.length) { clearInterval(timer); done(); }
        }, perLine);
      });
    },
  };
</script>`;
}

class Tour {
  private x = 640;
  private y = 420;
  private frames: { file: string; time: number }[] = [];

  /** When each scene started (wall clock seconds, the clock of Chrome's frame times). */
  readonly scenes: { name: string; time: number }[] = [];

  constructor(
    private page: DevTools,
    private viewer: string,
  ) {
    page.on("Page.screencastFrame", (params) => {
      const file = join(FRAMES, `${String(this.frames.length).padStart(6, "0")}.jpg`);
      writeFileSync(file, Buffer.from(String(params.data), "base64"));
      this.frames.push({ file, time: Number((params.metadata as { timestamp: number }).timestamp) });
      void page.send("Page.screencastFrameAck", { sessionId: params.sessionId });
    });
  }

  async record(): Promise<void> {
    await this.page.send("Page.addScriptToEvaluateOnNewDocument", { source: OVERLAY });
    await this.open(this.viewer);
    await this.page.send("Page.startScreencast", { format: "jpeg", quality: 88, maxWidth: WIDTH, maxHeight: HEIGHT, everyNthFrame: 1 });
  }

  /** Stops recording; returns the ffconcat list that holds each frame until the next. */
  /** Marks the start of a scene: the video cuts and transitions here. */
  scene(name: string): void {
    this.scenes.push({ name, time: Date.now() / 1000 });
    if (process.env.TOUR_TIMES) {
      console.log(`${(Date.now() / 1000 - (this.frames[0]?.time ?? Date.now() / 1000)).toFixed(1).padStart(6)} s  ${name}`);
    }
  }

  /** Seconds into the video where each scene after the first starts. */
  cuts(): number[] {
    const first = this.frames[0]?.time ?? 0;

    return this.scenes.slice(1).map((scene) => scene.time - first);
  }

  /**
   * Stops recording and lays the frames on an exact timeline: video frame n shows the last
   * frame Chrome sent at or before n/FPS seconds, linked into `sequence` as 000000.jpg, ...
   * (Chrome sends frames only when the page changes, so a still page repeats one.)
   *
   * @return The number of video frames.
   */
  async stop(sequence: string): Promise<number> {
    await this.wait(400);
    // Chrome's frame times use the wall clock; the guard keeps a clock surprise from freezing the end.
    const last = this.frames[this.frames.length - 1]?.time ?? 0;
    const now = Date.now() / 1000;
    const end = now > last && now - last < 30 ? now : last + 2;
    await this.page.send("Page.stopScreencast");

    mkdirSync(sequence, { recursive: true });
    const first = this.frames[0].time;
    const count = Math.ceil((end - first) * FPS);
    let shown = 0;

    for (let frame = 0; frame < count; frame++) {
      const time = first + frame / FPS;
      while (shown + 1 < this.frames.length && this.frames[shown + 1].time <= time) {
        shown++;
      }
      symlinkSync(this.frames[shown].file, join(sequence, `${String(frame).padStart(6, "0")}.jpg`));
    }

    if (process.env.TOUR_TIMES) {
      console.log(`${this.frames.length} frames from Chrome over ${(end - first).toFixed(1)} s, ${count} video frames`);
    }

    return count;
  }


  async open(url: string): Promise<void> {
    await this.page.send("Page.navigate", { url });
    await this.wait(1400);
    await this.page.eval(`window.__tour?.move(${this.x}, ${this.y}, 0)`);
  }

  wait(milliseconds: number): Promise<void> {
    return Bun.sleep(milliseconds);
  }

  async caption(name: string, text: string): Promise<void> {
    await this.page.eval(`window.__tour.caption(${JSON.stringify(name)}, ${JSON.stringify(text)})`);
  }

  async title(html: string): Promise<void> {
    await this.page.eval(`window.__tour.title(${JSON.stringify(html)})`);
  }

  /** Moves the cursor to the element, scrolling it into view first when needed. */
  async point(selector: string, text = "", nth = 0): Promise<boolean> {
    const found = (await this.page.eval(`(() => {
      const element = [...document.querySelectorAll(${JSON.stringify(selector)})].filter((each) => each.textContent.includes(${JSON.stringify(text)}))[${nth}];
      if (!element) return null;
      const rect = element.getBoundingClientRect();
      if (rect.top < 90 || rect.bottom > innerHeight - 110) { element.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
      return true;
    })()`)) as boolean | null;

    if (!found) {
      console.warn(`not found: ${selector} "${text}"`);
      return false;
    }

    await this.wait(450);
    const center = (await this.page.eval(`(() => {
      const element = [...document.querySelectorAll(${JSON.stringify(selector)})].filter((each) => each.textContent.includes(${JSON.stringify(text)}))[${nth}];
      const rect = element.getBoundingClientRect();
      return [Math.round(rect.left + Math.min(rect.width / 2, 60)), Math.round(rect.top + rect.height / 2)];
    })()`)) as [number, number];

    const distance = Math.hypot(center[0] - this.x, center[1] - this.y);
    const duration = Math.round(Math.min(900, Math.max(350, distance * 0.9)));
    [this.x, this.y] = center;
    await this.page.eval(`window.__tour.move(${this.x}, ${this.y}, ${duration})`);
    await this.wait(duration + 120);

    return true;
  }

  async click(selector: string, text = "", nth = 0): Promise<void> {
    if (!(await this.point(selector, text, nth))) {
      return;
    }

    await this.page.eval(`(() => {
      window.__tour.ring(${this.x}, ${this.y});
      [...document.querySelectorAll(${JSON.stringify(selector)})].filter((each) => each.textContent.includes(${JSON.stringify(text)}))[${nth}].click();
    })()`);
    await this.wait(500);
  }

  async doubleClick(selector: string, text = ""): Promise<void> {
    if (!(await this.point(selector, text))) {
      return;
    }

    await this.page.eval(`(() => {
      const element = [...document.querySelectorAll(${JSON.stringify(selector)})].find((each) => each.textContent.includes(${JSON.stringify(text)}));
      window.__tour.ring(${this.x}, ${this.y});
      setTimeout(() => window.__tour.ring(${this.x}, ${this.y}), 160);
      element.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
    })()`);
    await this.wait(600);
  }

  async type(selector: string, text: string): Promise<void> {
    await this.click(selector);

    for (let index = 1; index <= text.length; index++) {
      await this.page.eval(`(() => {
        const input = document.querySelector(${JSON.stringify(selector)});
        input.value = ${JSON.stringify(text.slice(0, index))};
        input.dispatchEvent(new Event('input', { bubbles: true }));
      })()`);
      await this.wait(70);
    }
  }

  async select(selector: string, value: string): Promise<void> {
    await this.point(selector);
    await this.page.eval(`(() => {
      window.__tour.ring(${this.x}, ${this.y});
      const select = document.querySelector(${JSON.stringify(selector)});
      select.value = ${JSON.stringify(value)};
      select.dispatchEvent(new Event('change', { bubbles: true }));
    })()`);
    await this.wait(600);
  }

  async scroll(y: number): Promise<void> {
    await this.page.eval(`window.scrollTo({ top: ${y}, behavior: 'smooth' })`);
    await this.wait(900);
  }

  eval(expression: string): Promise<unknown> {
    return this.page.eval(expression);
  }
}

async function scenes(tour: Tour, viewer: string, logFolder: string, report: string): Promise<void> {
  // 1. Title.
  tour.scene("Title");
  await tour.title("<h1>Peep</h1><p>See what your PHP app is doing, while it runs</p>");
  await tour.wait(3000);
  await tour.title("<p style=\"font-size:22px;color:#9ba1a6\">Why Peep?</p><p>No more dd() and reload, var_dump in the page, or tailing laravel.log.</p><p>Values, queries, logs, mail, SMS and webhooks in one window.</p>");
  await tour.wait(4200);

  // 2. Cards. The cut from the title cards reveals the app.
  tour.scene("Cards");
  await tour.title("");
  await tour.wait(500);
  await tour.caption("No dd() and reload", "peep($anything) shows here, grouped by request, while the page keeps working");
  await tour.point(".group", "POST /checkout");
  await tour.wait(900);
  await tour.click(".toggle", "", 0);
  await tour.wait(900);
  await tour.scroll(420);
  await tour.wait(700);
  await tour.scroll(0);

  // 3. Live.
  tour.scene("Live");
  await tour.caption("Live", "See it as it happens: cards arrive the moment the code runs");
  await tour.wait(600);
  const request = { id: `live-${Date.now()}`, kind: "http", label: "POST /cart/items" };
  await sendCard(viewer, "live-1", request, [{ type: "log", content: { values: [{ type: "string", value: "Adding espresso beans to the cart", length: 33 }] }, origin: { file: "/Users/you/code/shop/app/Http/Controllers/CartController.php", line: 27 } }, { type: "color", content: { color: "green" }, origin: null }]);
  await tour.wait(900);
  await sendCard(viewer, "live-2", request, [{ type: "query", content: { sql: "select * from products where id = ?", bindings: [12], time_ms: 1.8, connection: "mysql", slow: false, duplicate_count: null }, origin: { file: "/Users/you/code/shop/app/Http/Controllers/CartController.php", line: 31 } }]);
  await tour.wait(900);
  await sendCard(viewer, "live-3", request, [{ type: "table", content: { label: "Cart", rows: { type: "array", length: 2, children: [{ key: 0, node: { type: "array", length: 2, children: [{ key: "item", node: { type: "string", value: "Espresso beans", length: 14 } }, { key: "qty", node: { type: "int", value: 2 } }] } }, { key: 1, node: { type: "array", length: 2, children: [{ key: "item", node: { type: "string", value: "Mug", length: 3 } }, { key: "qty", node: { type: "int", value: 1 } }] } }] } }, origin: { file: "/Users/you/code/shop/app/Http/Controllers/CartController.php", line: 35 } }, { type: "label", content: { label: "Cart after adding" }, origin: null }]);
  await tour.wait(2200);

  // 4. Projects, filters, search.
  tour.scene("Projects, filters, search");
  await tour.caption("Find it fast", "The one value you need in seconds: filter by project, kind, color or text");
  await tour.click("nav.projects button", "blog");
  await tour.wait(600);
  await tour.click("nav.projects button", "shop");
  await tour.click(".chip", "Queries");
  await tour.wait(1000);
  await tour.click(".chip", "Queries");
  await tour.type("input.search", "declined");
  await tour.wait(1400);
  await tour.eval("(() => { const input = document.querySelector('input.search'); input.value = ''; input.dispatchEvent(new Event('input', { bubbles: true })); })()");
  await tour.wait(400);

  // 5. Query switches.
  tour.scene("Query switches");
  await tour.caption("Slow and N+1 queries", "Switch query capture on from here: no code change, no restart");
  await tour.select(".switches select", "on");
  await tour.wait(2200);

  // 6. Pause.
  tour.scene("Pause");
  await tour.caption("Less noise", "Pause receiving, or stop the app on a line with pause() until you continue");
  await tour.click(".toolbar button", "Pause receiving");
  await tour.wait(1600);
  await tour.click(".banner button", "Resume");
  await tour.wait(600);

  // 7. Mail.
  tour.scene("Mail");
  await tour.caption("Safe email testing", "Every mail is caught, never sent: check HTML on desktop and mobile");
  await tour.click("nav.views button", "Mail");
  await tour.click(".item", "Order #1042");
  await tour.wait(1800);
  await tour.click(".tabs button", "Mobile");
  await tour.wait(1400);
  await tour.click(".tabs button", "Desktop");
  await tour.wait(700);

  // 8. SMS.
  tour.scene("SMS");
  await tour.caption("No test phones", "SMS from Twilio, Vonage or Laravel notifications land here instead");
  await tour.click("nav.views button", "SMS");
  await tour.click(".item", "+1555");
  await tour.wait(2400);

  // 9. Webhooks (the demo"s deliveries belong to no project: shown under All).
  tour.scene("Webhooks");
  await tour.caption("Webhooks without ngrok", "Signed Mailgun, Twilio and Vonage callbacks to your local app");
  await tour.click("nav.views button", "Webhooks");
  await tour.click("nav.projects button", "All");
  await tour.point("aside .item", "Mailgun");
  await tour.wait(700);
  await tour.click(".delivery .line", "");
  await tour.wait(1800);

  // 10. Logs.
  tour.scene("Logs");
  await tour.caption("Readable logs", "laravel.log as clean, colored entries with the exception and its stack");
  await tour.click("nav.views button", "Logs");
  await tour.wait(1000);
  await tour.click(".entry .head", "PaymentDeclined");
  await tour.wait(1600);
  await tour.click(".views button", "JSON");
  await tour.wait(1200);
  await tour.click(".views button", "Tree");
  await tour.caption("Readable logs", "Only what matters: double-click error for errors and worse");
  await tour.doubleClick(".levels .level", "error");
  await tour.wait(1300);
  await tour.doubleClick(".levels .level", "debug");
  await tour.caption("Readable logs", "Watch the log live while you use the app, or pause it to read");
  appendFileSync(join(logFolder, "laravel.log"), `[2026-10-23 09:42:01] local.INFO: Order placed {"order_id":1044,"total":18,"items":1} \n`);
  await tour.wait(1200);
  appendFileSync(join(logFolder, "laravel.log"), `[2026-10-23 09:42:03] local.WARNING: Stock low {"product":"espresso-beans","left":3} \n`);
  await tour.wait(1200);
  await tour.click(".live", "Live");
  await tour.wait(900);
  await tour.click(".tools button", "Newest first");
  await tour.wait(1000);

  // 11. Settings.
  tour.scene("Settings");
  await tour.caption("Jump to the code", "Every file path opens in your editor: PhpStorm, VS Code, Cursor and more");
  await tour.click(".toolbar button", "Settings");
  await tour.eval("window.__tour.into(document.querySelector('dialog[open]'))");
  await tour.wait(2000);
  await tour.click("dialog .close", "");
  await tour.eval("window.__tour.into(null)");
  await tour.wait(500);

  // 12. AI agents.
  tour.scene("AI agents");
  await tour.caption("", "");
  await tour.open(`data:text/html;base64,${Buffer.from(terminalPage(report)).toString("base64")}`);
  await tour.caption("Let AI debug with you", "peep report gives Claude, Codex or Cursor the whole story, problems first");
  await tour.wait(600);
  await tour.eval("term.type('vendor/bin/peep report --project=shop')");
  await tour.wait(2400);
  await tour.eval("term.print(85)");
  await tour.wait(4600);

  // 13. The end.
  tour.scene("The end");
  await tour.caption("", "");
  await tour.title("<h1>Peep</h1><p>Less guessing, faster fixes</p><code>composer require shibbirweb/peep --dev</code><p style=\"font-size:18px;color:#9ba1a6\">github.com/shibbirweb/peep</p>");
  await tour.wait(4500);
}

/** The README's picture of the video: the title card with a play button over the viewer. */
function posterPage(): string {
  const viewer = readFileSync(join(ROOT, "docs", "wiki", "images", "viewer.png")).toString("base64");

  return `<!doctype html><meta charset="utf-8">
<style>
  body { margin: 0; width: ${WIDTH}px; height: ${HEIGHT}px; overflow: hidden; font-family: system-ui, -apple-system, sans-serif; color: #fff; background: #111113; }
  .shot { position: absolute; inset: -20px; background: url(data:image/png;base64,${viewer}) center / cover; filter: blur(3px) brightness(.45); transform: scale(1.04); }
  .shade { position: absolute; inset: 0; background: radial-gradient(circle at 50% 42%, rgba(43,43,110,.55), rgba(17,17,19,.92) 72%); }
  .content { position: relative; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px; text-align: center; }
  h1 { margin: 0; font-size: 92px; letter-spacing: -.02em; }
  p { margin: 0; font-size: 28px; color: #c9c9ff; }
  .play { margin-top: 26px; width: 128px; height: 128px; border-radius: 50%; background: #5b5bd6; box-shadow: 0 12px 40px rgba(91,91,214,.55), 0 0 0 10px rgba(255,255,255,.12); display: flex; align-items: center; justify-content: center; }
  .play::after { content: ''; margin-left: 10px; border-style: solid; border-width: 26px 0 26px 44px; border-color: transparent transparent transparent #fff; }
  .note { margin-top: 18px; font-size: 22px; color: #e6e6ff; }
</style>
<div class="shot"></div><div class="shade"></div>
<div class="content"><h1>Peep</h1><p>See what your PHP app is doing, while it runs</p><div class="play"></div><div class="note">Watch the 2-minute tour</div></div>`;
}

async function poster(page: DevTools, file: string): Promise<void> {
  await page.send("Page.navigate", { url: `data:text/html;base64,${Buffer.from(posterPage()).toString("base64")}` });
  await Bun.sleep(800);
  const answer = await page.send("Page.captureScreenshot", { format: "png" });
  writeFileSync(file, Buffer.from(String(answer.result?.data ?? ""), "base64"));
}

function ffmpeg(args: string[], what: string): void {
  const result = Bun.spawnSync(["ffmpeg", "-y", "-loglevel", "error", ...args], { stdout: "inherit", stderr: "inherit" });

  if (result.exitCode !== 0) {
    throw new Error(`ffmpeg could not ${what}`);
  }
}

function duration(file: string): number {
  return Number(Bun.spawnSync(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", file]).stdout.toString().trim());
}

/**
 * Cuts the recording at the scenes and joins them with transitions.
 *
 * @return The moments (seconds) of the transitions in the joined video, and its length.
 */
function joinScenes(recording: string, cuts: number[], output: string): { transitions: number[]; seconds: number } {
  const length = duration(recording);
  const bounds = [0, ...cuts.filter((cut) => cut > TRANSITION * 2 && cut < length - TRANSITION * 2), length];
  const count = bounds.length - 1;
  const transitions: number[] = [];

  // One input, split and trimmed by timestamp: exact, unlike seeking each piece.
  const filters = [`[0:v]split=${count}${Array.from({ length: count }, (_, index) => `[p${index}]`).join("")}`];
  for (let index = 0; index < count; index++) {
    filters.push(`[p${index}]trim=start=${bounds[index].toFixed(3)}:end=${bounds[index + 1].toFixed(3)},setpts=PTS-STARTPTS[s${index}]`);
  }

  let previous = "[s0]";
  let offset = 0;
  for (let index = 1; index < count; index++) {
    offset += bounds[index] - bounds[index - 1] - TRANSITION;
    filters.push(`${previous}[s${index}]xfade=transition=${TRANSITIONS[(index - 1) % TRANSITIONS.length]}:duration=${TRANSITION}:offset=${offset.toFixed(3)}[x${index}]`);
    transitions.push(offset + TRANSITION / 2);
    previous = `[x${index}]`;
  }

  if (process.env.TOUR_TIMES) {
    console.log(`recording ${length.toFixed(2)} s, cuts at ${bounds.map((bound) => bound.toFixed(1)).join(", ")}; transitions at ${transitions.map((moment) => moment.toFixed(1)).join(", ")}`);
  }

  ffmpeg(["-i", recording, "-filter_complex", filters.join(";"), "-map", previous, "-c:v", "libx264", "-preset", "slow", "-crf", "20", "-pix_fmt", "yuv420p", output], "join the scenes");

  return { transitions, seconds: duration(output) };
}

async function main(): Promise<void> {
  mkdirSync(DEMO, { recursive: true });

  if (process.argv.includes("--poster")) {
    const chrome = await startChrome(WIDTH, HEIGHT);
    try {
      await poster(chrome.page, join(DEMO, "peep-tour.png"));
    } finally {
      chrome.stop();
    }
    console.log("demo/peep-tour.png");

    return;
  }

  rmSync(OUT, { recursive: true, force: true });
  mkdirSync(FRAMES, { recursive: true });

  const demo = await startDemo();
  let cuts: number[] = [];

  try {
    // What an agent reads at the end, made by the real command against the demo viewer.
    // Only the summary and the problems: the rest scrolls by too fast to read.
    const full = Bun.spawnSync(["php", "bin/peep", "report", "--project=shop"], { cwd: ROOT, env: { ...process.env, PEEP_PORT: String(demo.port) } }).stdout.toString();
    const report = `${full.slice(0, full.indexOf("## Requests"))}## Requests\n\n... every request with its cards, then mail and SMS\n`;

    const chrome = await startChrome(WIDTH, HEIGHT);
    try {
      const tour = new Tour(chrome.page, demo.viewer);
      await tour.record();
      await scenes(tour, demo.viewer, demo.logFolder, report);
      await tour.stop(SEQUENCE);
      cuts = tour.cuts();
      await poster(chrome.page, join(DEMO, "peep-tour.png"));
    } finally {
      chrome.stop();
    }
  } finally {
    demo.stop();
  }

  const recording = join(OUT, "recording.mp4");
  ffmpeg(["-framerate", String(FPS), "-i", join(SEQUENCE, "%06d.jpg"), "-vf", `scale=${WIDTH}:${HEIGHT}:flags=lanczos,format=yuv420p`, "-c:v", "libx264", "-preset", "veryfast", "-crf", "12", recording], "encode the frames");

  const joined = join(OUT, "joined.mp4");
  const { transitions, seconds } = joinScenes(recording, cuts, joined);

  // The drop lands on the first cut, from the title cards into the app.
  const lead = (transitions[0] ?? INTRO_SECONDS) - INTRO_SECONDS;
  const soundtrack = join(OUT, "music.wav");
  writeWav(soundtrack, promoTrack(seconds - lead, transitions.map((moment) => moment - lead)));
  const align = lead >= 0 ? `adelay=${Math.round(lead * 1000)}:all=1` : `atrim=start=${(-lead).toFixed(3)},asetpts=PTS-STARTPTS`;

  // About -14 LUFS, the level YouTube and most platforms play music at. AAC adds about 1.7 dB to
  // the peaks of crisp hats and clicks, so the limiter sits at -3 dB to land them near -1.3 dB.
  const video = join(DEMO, "peep-tour.mp4");
  ffmpeg(["-i", joined, "-i", soundtrack, "-map", "0:v", "-map", "1:a", "-c:v", "copy", "-af", `${align},loudnorm=I=-13.5:TP=-2:LRA=11,aresample=48000,alimiter=limit=0.7:level=false`, "-c:a", "aac", "-b:a", "256k", "-shortest", "-movflags", "+faststart", video], "add the music");

  if (!process.env.TOUR_KEEP) {
    rmSync(OUT, { recursive: true, force: true });
  }
  console.log(`${video.replace(`${ROOT}/`, "")} and demo/peep-tour.png`);
}

await main();
