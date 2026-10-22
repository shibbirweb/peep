<script>
  import { clock } from '../lib/format.js';
  import { ideUrl, shortPath } from '../lib/ide.js';
  import { cardText } from '../lib/text.js';
  import Payload from './Payload.svelte';

  /** @type {{ card: object, ide: string }} */
  let { card, ide } = $props();

  // Kept outside the component: the list unmounts cards that scroll out of view.
  const toggled = toggledCards;

  const link = $derived(card.origin ? ideUrl(ide, card.origin.file, card.origin.line) : null);
  const cardKey = $derived(`${card.project}:${card.id}`);

  // peep()->hide() sends a card collapsed; the header toggles it.
  let flip = $state(toggled.has(`${card.project}:${card.id}`));
  const collapsed = $derived(card.hidden !== flip);
  let copied = $state(false);

  function toggle() {
    flip = !flip;

    if (flip) {
      toggled.add(cardKey);
    } else {
      toggled.delete(cardKey);
    }
  }

  async function copy() {
    try {
      await navigator.clipboard.writeText(cardText(card));
      copied = true;
      setTimeout(() => (copied = false), 1200);
    } catch {
      copied = false;
    }
  }
</script>

<script module>
  const toggledCards = new Set();
</script>

<article class="card size-{card.size}" style:--card-color={card.color ? `var(--${card.color})` : 'var(--border)'}>
  <header>
    <button class="collapse" type="button" onclick={toggle} aria-expanded={!collapsed} title={collapsed ? 'Show' : 'Hide'}>{collapsed ? '▸' : '▾'}</button>
    {#if card.label}<span class="label">{card.label}</span>{/if}
    {#if card.origin}
      {#if link}
        <a class="origin" href={link} title="{card.origin.file}:{card.origin.line}">{shortPath(card.origin.file)}:{card.origin.line}</a>
      {:else}
        <span class="origin" title={card.origin.file}>{shortPath(card.origin.file)}:{card.origin.line}</span>
      {/if}
    {/if}
    <span class="spacer"></span>
    <span class="request" title="{card.request.kind}: {card.request.label}">{card.request.label}</span>
    <time>{clock(card.created_at)}</time>
    <button class="copy" type="button" onclick={copy} title="Copy as text">{copied ? 'Copied' : 'Copy'}</button>
  </header>

  {#if !collapsed}
    {#each card.payloads as payload, index (index)}
      <Payload {payload} {ide} expand={card.expand} />
    {/each}
  {/if}
</article>

<style>
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-left: 4px solid var(--card-color);
    border-radius: 8px;
    padding: 8px 12px 10px;
  }

  header {
    display: flex;
    align-items: baseline;
    gap: 10px;
    margin-bottom: 6px;
    font-size: 12px;
    color: var(--muted);
    min-width: 0;
  }

  .collapse {
    border: 0;
    background: none;
    padding: 0;
    color: var(--muted);
    width: 1em;
  }

  .label {
    color: var(--text);
    font-weight: 600;
    font-size: 13px;
  }

  .origin {
    font-family: var(--mono);
    text-decoration: none;
    white-space: nowrap;
  }

  a.origin:hover {
    text-decoration: underline;
  }

  .spacer {
    flex: 1;
  }

  .copy {
    font-size: 11px;
    padding: 0 6px;
    opacity: 0;
  }

  .card:hover .copy,
  .copy:focus-visible {
    opacity: 1;
  }

  .request {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 40%;
  }

  time {
    white-space: nowrap;
  }

  .size-small {
    font-size: 12px;
  }

  .size-large :global(.value) {
    font-size: 15px;
  }
</style>
