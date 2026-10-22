<script>
  import { prettyJson } from '../lib/format.js';
  import TableView from './TableView.svelte';
  import ValueTree from './ValueTree.svelte';

  /** @type {{ payload: { type: string, content: object } }} */
  let { payload } = $props();

  const json = $derived(payload.type === 'json' ? prettyJson(String(payload.content.json ?? '')) : null);
</script>

<div class="payload">
  {#if payload.type === 'log'}
    {#each payload.content.values ?? [] as value, index (index)}
      <div class="value"><ValueTree node={value} /></div>
    {/each}
  {:else if payload.type === 'text'}
    <pre>{payload.content.text}</pre>
  {:else if payload.type === 'html'}
    <!-- No allow-scripts and no allow-same-origin: app HTML can neither run code nor reach the viewer. -->
    <iframe title="HTML" sandbox="" srcdoc={String(payload.content.html ?? '')}></iframe>
  {:else if payload.type === 'json' && json}
    <pre class:invalid={!json.ok}>{json.text}</pre>
  {:else if payload.type === 'table'}
    <TableView rows={payload.content.rows} label={payload.content.label} />
  {:else}
    <pre class="raw">{payload.type}: {JSON.stringify(payload.content, null, 2)}</pre>
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

  .invalid,
  .raw {
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
</style>
