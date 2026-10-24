---
layout: home
title: Peep
titleTemplate: A debugger for PHP apps

hero:
  name: Peep
  text: Debug any PHP app in a separate window
  tagline: Call peep($value) anywhere and watch values, queries, jobs, logs and exceptions arrive live, in a desktop app or your browser. PHP 7.1 to 8.5, Laravel 5.5 to 13.
  image:
    src: /logo.svg
    alt: Peep
  actions:
    - theme: brand
      text: Get started
      link: /docs/usage/Getting-Started
    - theme: alt
      text: Download the app
      link: https://github.com/shibbirweb/peep/releases/latest
    - theme: alt
      text: View on GitHub
      link: https://github.com/shibbirweb/peep

features:
  - icon: <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#5b5bd6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 16 4-4-4-4"/><path d="m6 8-4 4 4 4"/><path d="m14.5 4-5 16"/></svg>
    title: peep() from any code
    details: Send any value with colors, labels, tables, JSON, timing, traces, counters and pause. Plain PHP, Symfony, Slim and WordPress work too.
    link: /docs/usage/API-Reference
    linkText: API reference
  - icon: <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#5b5bd6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
    title: Laravel out of the box
    details: Queries, jobs, events, cache, the HTTP client, views, requests, logs, exceptions, mail and dump(), with no code at all.
    link: /docs/usage/Laravel
    linkText: Laravel guide
  - icon: <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#5b5bd6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
    title: Query debugging
    details: Every query with its bindings and time, slow and duplicate (N+1) badges, switched on and off from the viewer while the app runs.
    link: /docs/usage/Query-Debugging
    linkText: Query debugging
  - icon: <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#5b5bd6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
    title: Mail and SMS catcher
    details: Catch what your app sends over SMTP, a Laravel mailer, and Mailgun-, Twilio- and Vonage-style APIs, in a real inbox.
    link: /docs/usage/Mail-and-SMS
    linkText: Mail and SMS
  - icon: <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#5b5bd6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 16.98h-5.99c-1.1 0-1.95.94-2.48 1.9A4 4 0 0 1 2 17c.01-.7.2-1.4.57-2"/><path d="m6 17 3.13-5.78c.53-.97.1-2.18-.5-3.1a4 4 0 1 1 6.89-4.06"/><path d="m12 6 3.13 5.73C15.66 12.7 16.9 13 18 13a4 4 0 0 1 0 8"/></svg>
    title: Webhook simulator
    details: Call your app back with signed Mailgun, Twilio and Vonage events, from templates, with retries and a delivery log.
    link: /docs/usage/Webhooks
    linkText: Webhooks
  - icon: <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#5b5bd6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 12h-5"/><path d="M15 8h-5"/><path d="M19 17V5a2 2 0 0 0-2-2H4"/><path d="M8 21h12a2 2 0 0 0 2-2v-1a1 1 0 0 0-1-1H11a1 1 0 0 0-1 1v1a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v2a1 1 0 0 0 1 1h3"/></svg>
    title: Logs tab
    details: Your app's log files as colored entries, with the exception's stack, context trees, live watching and filters.
    link: /docs/usage/Logs
    linkText: Logs
  - icon: <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#5b5bd6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/></svg>
    title: Desktop app or browser
    details: A native app for macOS, Windows and Linux with tray, hotkey and notifications, or the viewer in a browser tab.
    link: /docs/usage/Desktop-App
    linkText: Desktop app
  - icon: <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#5b5bd6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
    title: Debugging by AI agents
    details: A debug report, a CLI and an MCP server give Claude Code, Codex, Cursor and others what Peep caught, as text.
    link: /docs/usage/AI-Agents
    linkText: AI agents
  - icon: <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#5b5bd6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
    title: Safe by default
    details: Off in production, and it never slows down or breaks your app when the viewer is closed.
    link: /docs/usage/Configuration
    linkText: Configuration
---

<div class="home-section">

## The two-minute tour

<div class="home-video">
  <video controls preload="none" poster="https://cdn.jsdelivr.net/gh/shibbirweb/peep@v0.1.0/demo/peep-tour.png" src="https://cdn.jsdelivr.net/gh/shibbirweb/peep@v0.1.0/demo/peep-tour.mp4"></video>
</div>

</div>

<div class="home-cta">

## Free and open source

<p class="home-lead">MIT licensed. If Peep saves you time, a star helps other PHP developers find it.</p>

<div class="home-cta-actions">
  <a href="./docs/usage/Getting-Started">Read the docs</a>
  <a class="alt" href="https://github.com/shibbirweb/peep">Star on GitHub</a>
</div>

</div>
