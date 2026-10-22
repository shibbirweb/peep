<script>
  import { onMount } from 'svelte';
  import Card from './components/Card.svelte';
  import { projectsOf } from './lib/cards.js';
  import { IDES, loadIde, saveIde } from './lib/ide.js';
  import { clearCards, connect, viewer } from './lib/stream.svelte.js';

  let project = $state('');
  let ide = $state(loadIde());

  const projects = $derived(projectsOf(viewer.cards));
  const visible = $derived(project === '' ? viewer.cards : viewer.cards.filter((card) => card.project === project));

  onMount(() => connect());

  function clear() {
    clearCards(project === '' ? null : project);
  }

  function chooseIde(event) {
    ide = event.currentTarget.value;
    saveIde(ide);
  }
</script>

<header class="toolbar">
  <strong class="brand">Peep</strong>
  <span class="status" class:online={viewer.connected} title={viewer.connected ? 'Connected' : 'Reconnecting'}></span>

  <select bind:value={project} aria-label="Project">
    <option value="">All projects</option>
    {#each projects as name (name)}<option value={name}>{name}</option>{/each}
  </select>

  <span class="spacer"></span>

  <select value={ide} onchange={chooseIde} aria-label="Editor for file links">
    {#each IDES as option (option.id)}<option value={option.id}>{option.name}</option>{/each}
  </select>
  <button type="button" onclick={clear} title="Clear the cards of the selected project">Clear</button>
</header>

<main>
  {#each visible as card (card.project + card.id)}
    <Card {card} {ide} />
  {:else}
    <div class="empty">
      <p>Waiting for <code>peep()</code> calls…</p>
      <p class="hint">Enable Peep in your app with <code>PEEP_ENABLED=true</code>, then call <code>peep($anything)</code>.</p>
    </div>
  {/each}
</main>

<style>
  .toolbar {
    position: sticky;
    top: 0;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 16px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
  }

  .brand {
    font-size: 15px;
  }

  .status {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--danger);
  }

  .status.online {
    background: var(--green);
  }

  .spacer {
    flex: 1;
  }

  main {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 12px 16px 32px;
    max-width: 1200px;
    margin: 0 auto;
  }

  .empty {
    text-align: center;
    color: var(--muted);
    padding: 64px 16px;
  }

  .hint {
    font-size: 13px;
  }
</style>
