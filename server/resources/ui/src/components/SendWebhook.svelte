<script>
  // WH-07: send a rule for this mail or SMS, with a chosen event.
  import { onMount } from 'svelte';
  import { loadPresets, sendWebhook } from '../lib/api.js';
  import { eventsOf, rulesFor } from '../lib/webhooks.js';
  import { viewer } from '../lib/stream.svelte.js';

  /** @type {{ kind: 'mail'|'sms', id: string, project: string }} */
  let { kind, id, project } = $props();

  let ruleId = $state('');
  let event = $state('');
  let notice = $state('');
  let presets = $state([]);

  onMount(async () => {
    presets = await loadPresets();
  });

  const rules = $derived(rulesFor(viewer.webhooks.rules, kind, project));
  const rule = $derived(rules.find((each) => each.id === ruleId) ?? rules[0] ?? null);
  const events = $derived(rule ? eventsOf(rule, presets) : []);

  async function send() {
    notice = '';

    try {
      await sendWebhook({ rule_id: rule.id, [`${kind}_id`]: id, event: event || undefined });
      notice = 'Sent: see the Webhooks tab.';
    } catch (exception) {
      notice = exception.message;
    }
  }
</script>

{#if rule !== null}
  <span class="send">
    <select value={rule.id} onchange={(change) => (ruleId = change.currentTarget.value)} aria-label="Webhook rule">
      {#each rules as each (each.id)}<option value={each.id}>{each.name}</option>{/each}
    </select>
    <select bind:value={event} aria-label="Event">
      <option value="">All steps</option>
      {#each events as name (name)}<option value={name}>{name}</option>{/each}
    </select>
    <button type="button" onclick={send}>Send webhook</button>
    {#if notice}<span class="notice">{notice}</span>{/if}
  </span>
{/if}

<style>
  .send {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
  }

  select {
    font-size: 12px;
    padding: 2px 4px;
  }

  .notice {
    font-size: 12px;
    color: var(--muted);
  }
</style>
