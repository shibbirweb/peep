<script>
  import { formatBytes } from '../lib/format.js';
  import { shortPath } from '../lib/ide.js';
  import { inlineBindings } from '../lib/sql.js';
  import HtmlFrame from './HtmlFrame.svelte';
  import ValueTree from './ValueTree.svelte';

  /** @type {{ payload: { type: string, content: object } }} */
  let { payload } = $props();

  const content = $derived(payload.content ?? {});
  let showDetails = $state(false);
</script>

{#if payload.type === 'query'}
  <pre class="sql">{inlineBindings(content.sql ?? '', content.bindings ?? [])}</pre>
  <div class="meta">
    {#if content.time_ms !== null && content.time_ms !== undefined}<span>{content.time_ms} ms</span>{/if}
    {#if content.connection}<span>{content.connection}{content.driver ? ` (${content.driver})` : ''}</span>{/if}
    {#if content.slow}<span class="badge warn">slow</span>{/if}
    {#if content.duplicate_count}<span class="badge dup">duplicate × {content.duplicate_count}</span>{/if}
  </div>
{:else if payload.type === 'query_count'}
  <div><strong>{content.count}</strong> {content.count === 1 ? 'query' : 'queries'} in {content.time_ms} ms</div>
{:else if payload.type === 'event'}
  <div class="title">{content.name}</div>
  {#if content.payload}<ValueTree node={content.payload} depth={1} />{/if}
{:else if payload.type === 'job'}
  <div class="title"><span class="badge status-{content.status}">{content.status}</span> {content.name}</div>
  <div class="meta">
    {#if content.queue}<span>queue {content.queue}</span>{/if}
    {#if content.connection}<span>{content.connection}</span>{/if}
    {#if content.attempts}<span>attempt {content.attempts}</span>{/if}
    {#if content.time_ms !== undefined}<span>{content.time_ms} ms</span>{/if}
  </div>
  {#if content.exception}<div class="error">{content.exception}</div>{/if}
{:else if payload.type === 'cache'}
  <div class="title"><span class="badge cache-{content.action}">{content.action}</span> <code>{content.key}</code>{#if content.store}{' · '}{content.store}{/if}{#if content.ttl}{' · '}{content.ttl} s{/if}</div>
  {#if content.value}<ValueTree node={content.value} depth={1} />{/if}
{:else if payload.type === 'http'}
  <div class="title">
    <strong>{content.method}</strong> {content.url}
    {#if content.status}<span class="badge" class:warn={content.status >= 400}>{content.status}</span>{:else if content.error}<span class="badge warn">{content.error}</span>{:else}<span class="badge">pending</span>{/if}
    {#if content.time_ms !== undefined}<span class="muted">{content.time_ms} ms</span>{/if}
  </div>
  <button type="button" class="details" onclick={() => (showDetails = !showDetails)}>{showDetails ? 'Hide' : 'Show'} details</button>
  {#if showDetails}
    <div class="label">Request headers</div><pre>{JSON.stringify(content.headers ?? {}, null, 2)}</pre>
    {#if content.body}<div class="label">Request body</div><pre>{content.body}</pre>{/if}
    {#if content.response_headers}<div class="label">Response headers</div><pre>{JSON.stringify(content.response_headers, null, 2)}</pre>{/if}
    {#if content.response_body}<div class="label">Response body</div><pre>{content.response_body}</pre>{/if}
  {/if}
{:else if payload.type === 'view'}
  <div class="title">{content.name}</div>
  {#if content.path}<div class="meta"><span title={content.path}>{shortPath(content.path)}</span></div>{/if}
  {#if content.data}<ValueTree node={content.data} depth={1} />{/if}
{:else if payload.type === 'request'}
  <div class="title">
    <strong>{content.method}</strong> {content.uri}
    <span class="badge" class:warn={content.status >= 400}>{content.status}</span>
  </div>
  <div class="meta">
    {#if content.route}<span>route {content.route}</span>{/if}
    {#if content.action}<span>{content.action}</span>{/if}
    {#if content.time_ms !== null}<span>{content.time_ms} ms</span>{/if}
    <span>peak {formatBytes(content.peak_memory ?? 0)}</span>
  </div>
  <button type="button" class="details" onclick={() => (showDetails = !showDetails)}>{showDetails ? 'Hide' : 'Show'} details</button>
  {#if showDetails}
    {#if content.middleware?.length}<div class="label">Middleware</div><pre>{content.middleware.join('\n')}</pre>{/if}
    <div class="label">Headers</div><pre>{JSON.stringify(content.headers ?? {}, null, 2)}</pre>
    {#if content.input}<div class="label">Input</div><ValueTree node={content.input} depth={1} />{/if}
  {/if}
{:else if payload.type === 'log_message'}
  <div class="title"><span class="badge level-{content.level}">{content.level}</span> {content.message}</div>
  {#if content.context && content.context.length > 0}<ValueTree node={content.context} depth={1} />{/if}
{:else if payload.type === 'mail_preview'}
  <div class="title">{content.subject}</div>
  <div class="meta">
    {#if content.from?.length}<span>from {content.from.join(', ')}</span>{/if}
    {#if content.to?.length}<span>to {content.to.join(', ')}</span>{/if}
  </div>
  <div class="mail-body"><HtmlFrame html={String(content.html ?? '')} title="Mail" /></div>
{/if}

<style>
  pre {
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
  }

  .sql {
    color: var(--text);
  }

  .title {
    margin-bottom: 2px;
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    font-size: 12px;
    color: var(--muted);
    margin-top: 2px;
  }

  .badge {
    display: inline-block;
    padding: 0 6px;
    border-radius: 999px;
    font-size: 11px;
    background: var(--surface-2);
    color: var(--muted);
  }

  .warn,
  .status-failed,
  .level-error,
  .level-critical,
  .level-alert,
  .level-emergency {
    background: color-mix(in srgb, var(--red) 22%, transparent);
    color: var(--text);
  }

  .dup,
  .level-warning,
  .level-deprecation,
  .cache-missed {
    background: color-mix(in srgb, var(--yellow) 30%, transparent);
    color: var(--text);
  }

  .status-processed,
  .cache-hit {
    background: color-mix(in srgb, var(--green) 22%, transparent);
    color: var(--text);
  }

  .error {
    color: var(--danger);
    margin-top: 4px;
  }

  .muted {
    color: var(--muted);
    font-size: 12px;
  }

  .details {
    font-size: 12px;
    padding: 1px 8px;
    margin-top: 4px;
  }

  .label {
    font-size: 11px;
    color: var(--muted);
    margin-top: 6px;
  }

  .mail-body {
    margin-top: 4px;
  }
</style>
