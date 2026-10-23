<script>
  // One log entry: a colored row, opened to show the message, the exception with its
  // stack and the context as a tree, JSON or the raw text.
  import { ideUrl, shortPath } from '../lib/ide.js';
  import { contextOf, firstLine, jsonNode } from '../lib/logs.js';
  import ValueTree from './ValueTree.svelte';

  /** @type {{ entry: object, open: boolean, view: 'tree'|'json'|'raw', ide: string, ontoggle: () => void }} */
  let { entry, open, view, ide, ontoggle } = $props();

  const context = $derived(contextOf(entry));
  const extra = $derived(entry.extra && (Array.isArray(entry.extra) ? entry.extra.length > 0 : Object.keys(entry.extra).length > 0) ? entry.extra : null);
  const keys = $derived(context && typeof context === 'object' ? Object.keys(context).length : 0);
  const exceptionLink = $derived(entry.exception ? ideUrl(ide, entry.exception.file, entry.exception.line) : null);

  let copied = $state(false);

  async function copy(event) {
    event.stopPropagation();
    await navigator.clipboard.writeText(entry.raw);
    copied = true;
    setTimeout(() => (copied = false), 1200);
  }
</script>

<article class="entry level-{entry.level}" class:open>
  <button type="button" class="head" onclick={ontoggle} aria-expanded={open}>
    <span class="caret">{open ? '▾' : '▸'}</span>
    <time>{entry.time}</time>
    <span class="level">{entry.level}</span>
    <span class="channel">{entry.channel}</span>
    <span class="message">{entry.exception ? `${entry.exception.class}: ${firstLine(entry.exception.message)}` : firstLine(entry.message)}</span>
    {#if keys > 0}<span class="chip">{keys} {keys === 1 ? 'key' : 'keys'}</span>{/if}
  </button>

  {#if open}
    <div class="body">
      <div class="actions"><button type="button" onclick={copy}>{copied ? 'Copied' : 'Copy'}</button></div>

      {#if view === 'raw'}
        <pre class="raw">{entry.raw}</pre>
      {:else}
        {#if entry.message.includes('\n') || (entry.exception && entry.message !== entry.exception.message)}
          <pre class="text">{entry.message}</pre>
        {/if}

        {#if entry.exception}
          <div class="exception">
            <div class="title"><strong>{entry.exception.class}</strong>{#if entry.exception.code !== '0'}<span class="muted code">(code {entry.exception.code})</span>{/if}</div>
            <div class="text">{entry.exception.message}</div>
            <div class="where">
              {#if exceptionLink}<a href={exceptionLink} title={entry.exception.file}>{shortPath(entry.exception.file)}:{entry.exception.line}</a>{:else}<span title={entry.exception.file}>{entry.exception.file}:{entry.exception.line}</span>{/if}
            </div>
            {#if entry.exception.frames.length > 0}
              <ol class="frames">
                {#each entry.exception.frames as frame, index (index)}
                  {@const link = frame.file ? ideUrl(ide, frame.file, frame.line ?? 1) : null}
                  <li>
                    <span class="call">{frame.call}</span>
                    {#if frame.file}
                      {#if link}<a href={link} title="{frame.file}:{frame.line}">{shortPath(frame.file)}:{frame.line}</a>{:else}<span class="muted" title={frame.file}>{shortPath(frame.file)}:{frame.line}</span>{/if}
                    {/if}
                  </li>
                {/each}
              </ol>
            {/if}
            {#each entry.exception.previous as previous, index (index)}
              <div class="previous">Caused by {previous}</div>
            {/each}
          </div>
        {/if}

        {#if context !== null}
          <div class="label">Context</div>
          {#if view === 'json'}
            <pre>{JSON.stringify(context, null, 2)}</pre>
          {:else if typeof context === 'object'}
            <div class="tree"><ValueTree node={jsonNode(context)} /></div>
          {:else}
            <pre>{String(context)}</pre>
          {/if}
        {/if}

        {#if extra !== null}
          <div class="label">Extra</div>
          {#if view === 'json'}<pre>{JSON.stringify(extra, null, 2)}</pre>{:else}<div class="tree"><ValueTree node={jsonNode(extra)} /></div>{/if}
        {/if}
      {/if}
    </div>
  {/if}
</article>

<style>
  .entry {
    --level: var(--gray);
    background: var(--surface);
    border: 1px solid var(--border);
    border-left: 4px solid var(--level);
    border-radius: 6px;
  }

  .level-debug {
    --level: var(--gray);
  }

  .level-info {
    --level: var(--blue);
  }

  .level-notice {
    --level: var(--green);
  }

  .level-warning {
    --level: var(--yellow);
  }

  .level-error {
    --level: var(--red);
  }

  .level-critical {
    --level: var(--orange);
  }

  .level-alert,
  .level-emergency {
    --level: var(--purple);
  }

  .head {
    display: flex;
    align-items: baseline;
    gap: 10px;
    width: 100%;
    text-align: left;
    border: 0;
    background: none;
    padding: 5px 10px;
    font-size: 13px;
  }

  .caret {
    color: var(--muted);
    width: 1em;
    flex: none;
  }

  time {
    font-family: var(--mono);
    font-size: 12px;
    color: var(--muted);
    flex: none;
  }

  .level {
    flex: none;
    min-width: 72px;
    text-align: center;
    text-transform: uppercase;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.03em;
    padding: 1px 6px;
    border-radius: 999px;
    color: color-mix(in srgb, var(--level) 65%, var(--text));
    background: color-mix(in srgb, var(--level) 16%, transparent);
  }

  .level-emergency .level,
  .level-alert .level {
    color: white;
    background: var(--level);
  }

  .channel {
    flex: none;
    color: var(--muted);
    font-size: 12px;
  }

  .message {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-family: var(--mono);
    font-size: 12.5px;
  }

  .open .message {
    white-space: normal;
    overflow-wrap: anywhere;
  }

  .chip {
    flex: none;
    font-size: 11px;
    color: var(--muted);
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 0 6px;
  }

  .body {
    padding: 4px 12px 12px 30px;
    border-top: 1px dashed var(--border);
  }

  .actions {
    display: flex;
    justify-content: flex-end;
  }

  .actions button {
    font-size: 12px;
    padding: 1px 8px;
  }

  pre {
    margin: 4px 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    background: var(--surface-2);
    border-radius: 6px;
    padding: 8px 10px;
    max-height: 60vh;
    overflow: auto;
  }

  .text {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-family: var(--mono);
    font-size: 12.5px;
  }

  .exception {
    margin: 6px 0;
    padding: 8px 10px;
    border-radius: 6px;
    background: color-mix(in srgb, var(--red) 8%, transparent);
    border: 1px solid color-mix(in srgb, var(--red) 30%, transparent);
  }

  .where,
  .previous {
    font-size: 12px;
    margin-top: 2px;
  }

  .previous {
    color: var(--muted);
    font-family: var(--mono);
    overflow-wrap: anywhere;
  }

  .frames {
    margin: 6px 0 0;
    padding-left: 2em;
    font-family: var(--mono);
    font-size: 12px;
  }

  .frames li {
    padding: 1px 0;
    overflow-wrap: anywhere;
  }

  .call {
    margin-right: 8px;
  }

  .label {
    margin-top: 8px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--muted);
  }

  .tree {
    padding: 4px 0;
  }

  .muted {
    color: var(--muted);
  }

  .code {
    margin-left: 6px;
  }
</style>
