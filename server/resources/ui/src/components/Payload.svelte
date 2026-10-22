<script>
  import { formatBytes, formatXml, isSafeUrl, prettyJson } from '../lib/format.js';
  import { shortPath } from '../lib/ide.js';
  import ExceptionView from './ExceptionView.svelte';
  import Frames from './Frames.svelte';
  import LockBanner from './LockBanner.svelte';
  import TableView from './TableView.svelte';
  import ValueTree from './ValueTree.svelte';

  /** @type {{ payload: { type: string, content: object }, ide: string, expand?: object|null }} */
  let { payload, ide, expand = null } = $props();

  const content = $derived(payload.content ?? {});
  const json = $derived(payload.type === 'json' ? prettyJson(String(content.json ?? '')) : null);
</script>

<div class="payload">
  {#if payload.type === 'log'}
    {#each content.values ?? [] as value, index (index)}
      <div class="value"><ValueTree node={value} {expand} /></div>
    {/each}
  {:else if payload.type === 'text'}
    <pre>{content.text}</pre>
  {:else if payload.type === 'html'}
    <!-- No allow-scripts and no allow-same-origin: app HTML can neither run code nor reach the viewer. -->
    <iframe title="HTML" sandbox="" srcdoc={String(content.html ?? '')}></iframe>
  {:else if payload.type === 'json' && json}
    <pre class:muted={!json.ok}>{json.text}</pre>
  {:else if payload.type === 'xml'}
    <pre>{formatXml(String(content.xml ?? ''))}</pre>
  {:else if payload.type === 'table'}
    <TableView rows={content.rows} label={content.label} />
  {:else if payload.type === 'image'}
    {#if content.src}
      <img src={content.src} alt="" />
    {:else}
      <div class="muted">Image not shown: {content.error}</div>
    {/if}
  {:else if payload.type === 'file'}
    <div class="file-head" title={content.path}>{shortPath(String(content.path ?? ''))}{#if content.size !== undefined} · {formatBytes(content.size)}{/if}</div>
    {#if content.error}
      <div class="muted">{content.error}</div>
    {:else if content.binary}
      <div class="muted">Binary file (not shown).</div>
    {:else}
      <pre class="file">{content.contents}</pre>
      {#if content.truncated}<div class="muted">Only the first 1 MB is shown.</div>{/if}
    {/if}
  {:else if payload.type === 'link'}
    {#if isSafeUrl(String(content.url ?? ''))}
      <a href={content.url} target="_blank" rel="noopener noreferrer">{content.label ?? content.url}</a>
    {:else}
      <span>{content.label ?? content.url}</span>
    {/if}
  {:else if payload.type === 'separator'}
    <hr />
  {:else if payload.type === 'trace' || payload.type === 'caller'}
    {#if (content.frames ?? []).length === 0}
      <div class="muted">No caller (called from the top level).</div>
    {:else}
      <Frames frames={content.frames} {ide} />
    {/if}
  {:else if payload.type === 'exception'}
    <ExceptionView exception={content} {ide} />
  {:else if payload.type === 'measure'}
    {#if content.started}
      <div>⏱ Stopwatch started</div>
    {:else}
      <div class="measure">
        <span>⏱ {content.total_ms} ms{content.callable ? '' : ' total'}</span>
        {#if !content.callable}<span>{content.since_last_ms} ms since previous</span>{/if}
        <span class="muted">memory {formatBytes(content.memory)}, peak {formatBytes(content.peak_memory)}</span>
      </div>
    {/if}
  {:else if payload.type === 'count'}
    <div>Called <strong>{content.count}</strong> {content.count === 1 ? 'time' : 'times'}{#if content.name} · {content.name}{/if}</div>
  {:else if payload.type === 'notify'}
    <div>🔔 {content.message}</div>
  {:else if payload.type === 'lock'}
    <LockBanner name={content.name} />
  {:else if payload.type === 'new_screen'}
    {#if content.title}<h2 class="screen">{content.title}</h2>{:else}<hr />{/if}
  {:else}
    <pre class="muted">{payload.type}: {JSON.stringify(content, null, 2)}</pre>
  {/if}
</div>

<style>
  .payload + :global(.payload) {
    margin-top: 6px;
  }

  .value + .value {
    margin-top: 2px;
  }

  pre {
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
  }

  .muted {
    color: var(--muted);
  }

  iframe {
    width: 100%;
    min-height: 120px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: white;
    resize: vertical;
  }

  img {
    max-width: 100%;
    max-height: 480px;
    border-radius: 6px;
    background: repeating-conic-gradient(var(--surface-2) 0 25%, transparent 0 50%) 0 0 / 16px 16px;
  }

  .file-head {
    font-family: var(--mono);
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 4px;
  }

  .file {
    max-height: 360px;
    overflow: auto;
    background: var(--surface-2);
    padding: 6px 8px;
    border-radius: 6px;
  }

  hr {
    border: 0;
    border-top: 1px dashed var(--border);
    margin: 4px 0;
  }

  .measure {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
  }

  .screen {
    margin: 0;
    font-size: 16px;
  }
</style>
