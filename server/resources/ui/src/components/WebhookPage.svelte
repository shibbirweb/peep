<script>
  // WH-11 and WH-13: rules per project, presets, import and export, and the delivery log.
  import { onMount } from 'svelte';
  import { importRules, loadPresets } from '../lib/api.js';
  import { exportRules, parseImport, ruleFromPreset } from '../lib/webhooks.js';
  import DeliveryLog from './DeliveryLog.svelte';
  import RuleEditor from './RuleEditor.svelte';

  /** @type {{ rules: Array<object>, deliveries: Array<object>, project: string }} */
  let { rules, deliveries, project } = $props();

  let presets = $state([]);
  let editing = $state(null);
  let error = $state('');
  let fileInput;

  const shown = $derived(rules.filter((rule) => project === '' || rule.project === '*' || rule.project === project));
  const shownDeliveries = $derived(deliveries.filter((delivery) => project === '' || delivery.project === project));

  onMount(async () => {
    presets = await loadPresets();
  });

  function create(event) {
    const preset = presets.find((each) => each.id === event.currentTarget.value);
    event.currentTarget.value = '';

    if (preset) {
      editing = ruleFromPreset(preset, project);
    }
  }

  function download() {
    const url = URL.createObjectURL(new Blob([exportRules(shown)], { type: 'application/json' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'peep-webhooks.json';
    link.click();
    URL.revokeObjectURL(url);
  }

  async function upload(event) {
    const file = event.currentTarget.files?.[0];
    event.currentTarget.value = '';
    error = '';

    if (!file) {
      return;
    }

    try {
      await importRules(parseImport(await file.text()), false);
    } catch (exception) {
      error = `Import failed: ${exception.message}`;
    }
  }
</script>

<div class="page">
  <aside>
    <div class="tools">
      <select onchange={create} aria-label="New rule from a preset">
        <option value="">New rule…</option>
        {#each presets as preset (preset.id)}<option value={preset.id}>{preset.name}</option>{/each}
      </select>
      <button type="button" onclick={download} disabled={shown.length === 0} title="Download the rules as JSON">Export</button>
      <button type="button" onclick={() => fileInput.click()} title="Add rules from a JSON file">Import</button>
      <input type="file" accept="application/json,.json" bind:this={fileInput} onchange={upload} hidden />
    </div>
    {#if error}<p class="error">{error}</p>{/if}
    <ul>
      {#each shown as rule (rule.id)}
        <li>
          <button type="button" class="item" class:selected={editing?.id === rule.id} class:off={!rule.enabled} onclick={() => (editing = rule)}>
            <span class="name">{rule.name}</span>
            <span class="meta">{rule.trigger} · {rule.steps.map((step) => step.event).join(', ')} · {rule.project}</span>
            <span class="meta url">{rule.method} {rule.url}</span>
          </button>
        </li>
      {:else}
        <li class="empty">
          <p>No webhook rules.</p>
          <p class="hint">Start from a preset: Mailgun events, Twilio status callbacks or Vonage receipts. When a mail or SMS is caught, the viewer calls your app back with a signed request.</p>
        </li>
      {/each}
    </ul>
  </aside>

  <section class="main">
    {#if editing !== null}
      <RuleEditor rule={editing} onsaved={(rule) => (editing = rule)} ondeleted={() => (editing = null)} />
      <hr />
    {/if}
    <DeliveryLog deliveries={shownDeliveries} />
  </section>
</div>

<style>
  .page {
    display: grid;
    grid-template-columns: minmax(240px, 320px) 1fr;
    gap: 12px;
    align-items: start;
  }

  aside,
  .main {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
  }

  aside {
    position: sticky;
    top: 60px;
  }

  .tools {
    display: flex;
    gap: 6px;
    padding: 8px;
    border-bottom: 1px solid var(--border);
  }

  .tools select {
    flex: 1;
    min-width: 0;
  }

  ul {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .item {
    display: flex;
    flex-direction: column;
    gap: 2px;
    width: 100%;
    text-align: left;
    border: 0;
    border-bottom: 1px solid var(--border);
    border-radius: 0;
    padding: 8px 10px;
    background: none;
  }

  .item.selected {
    background: var(--surface-2);
  }

  .item.off {
    opacity: 0.55;
  }

  .name {
    font-weight: 600;
  }

  .meta {
    font-size: 12px;
    color: var(--muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .url {
    font-family: var(--mono);
  }

  .empty {
    padding: 16px;
    color: var(--muted);
    text-align: center;
  }

  .hint {
    font-size: 12px;
  }

  .error {
    color: var(--danger);
    font-size: 12px;
    padding: 0 10px;
  }

  .main {
    padding: 12px 16px 16px;
  }

  hr {
    border: 0;
    border-top: 1px solid var(--border);
    margin: 16px 0;
  }

  @media (max-width: 720px) {
    .page {
      grid-template-columns: 1fr;
    }

    aside {
      position: static;
    }
  }
</style>
