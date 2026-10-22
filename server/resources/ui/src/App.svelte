<script>
  import { onMount } from 'svelte';
  import Card from './components/Card.svelte';
  import FilterBar from './components/FilterBar.svelte';
  import MailInbox from './components/MailInbox.svelte';
  import SmsInbox from './components/SmsInbox.svelte';
  import WebhookPage from './components/WebhookPage.svelte';
  import QuerySwitches from './components/QuerySwitches.svelte';
  import SettingsDialog from './components/SettingsDialog.svelte';
  import VirtualList from './components/VirtualList.svelte';
  import { clearCards, loadScreen, newScreen, setReceiving } from './lib/api.js';
  import { projectsOf } from './lib/cards.js';
  import { clock } from './lib/format.js';
  import { emptyFilters, matches } from './lib/filters.js';
  import { groupRows } from './lib/groups.js';
  import { setUnread } from './lib/desktop.js';
  import { connect, viewer } from './lib/stream.svelte.js';

  let project = $state('');
  let filters = $state(emptyFilters());
  let search = $state('');
  let collapsedGroups = $state(new Set());
  let archived = $state(null);
  let showSettings = $state(false);
  let tab = $state('cards');

  // Typing in the search box filters after a short pause, so long lists never block typing.
  let searchTimer = 0;
  $effect(() => {
    const value = filters.search;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => (search = value), 150);

    return () => clearTimeout(searchTimer);
  });

  const source = $derived(archived === null ? viewer.cards : archived.cards);
  // Projects stay listed after a new screen or clear: their switches and screens still matter.
  const projects = $derived(
    [...new Set([...projectsOf(viewer.cards), ...viewer.screens.map((screen) => screen.project), ...Object.keys(viewer.switches), ...(project !== '' ? [project] : [])])].sort(),
  );
  const counts = $derived(
    viewer.cards.reduce((totals, card) => {
      totals[card.project] = (totals[card.project] ?? 0) + 1;

      return totals;
    }, {}),
  );
  const inProject = $derived(project === '' ? source : source.filter((card) => card.project === project));
  const visible = $derived(inProject.filter((card) => matches(card, { ...filters, search })));
  const rows = $derived(groupRows(visible, collapsedGroups));

  // The switches need one project: the selected one, or the only one there is.
  const switchProject = $derived(project !== '' ? project : projects.length === 1 ? projects[0] : null);
  const screens = $derived(viewer.screens.filter((screen) => project === '' || screen.project === project));
  const unreadMail = $derived(viewer.mails.filter((mail) => !mail.read && (project === '' || mail.project === project)).length);
  const unreadSms = $derived(viewer.sms.filter((sms) => !sms.read && (project === '' || sms.project === project)).length);

  // The desktop app shows unread mail and SMS of every project on its tray and dock.
  $effect(() => {
    setUnread(viewer.mails.filter((mail) => !mail.read).length + viewer.sms.filter((sms) => !sms.read).length);
  });

  // From a mail or SMS to the cards of the request or job that sent it (MS-08, MS-15).
  function showRequest(requestId) {
    archived = null;
    filters = { ...emptyFilters(), request: requestId };
    tab = 'cards';
  }

  onMount(() => connect());

  $effect(() => {
    const theme = viewer.settings.theme;

    if (theme === 'light' || theme === 'dark') {
      document.documentElement.dataset.theme = theme;
    } else {
      delete document.documentElement.dataset.theme;
    }
  });

  function clear() {
    clearCards(project === '' ? null : project);
  }

  function startScreen() {
    const target = project !== '' ? project : switchProject;

    if (target !== null) {
      newScreen(target);
    }
  }

  async function openScreen(event) {
    const id = event.currentTarget.value;
    event.currentTarget.value = '';

    if (id !== '') {
      const screen = await loadScreen(id);
      archived = { ...screen, cards: [...screen.cards].reverse() };
    }
  }

  function toggleGroup(requestId) {
    const next = new Set(collapsedGroups);

    if (next.has(requestId)) {
      next.delete(requestId);
    } else {
      next.add(requestId);
    }

    collapsedGroups = next;
  }
</script>

<header class="toolbar">
  <div class="line">
    <strong class="brand">Peep</strong>
    <span class="status" class:online={viewer.connected} title={viewer.connected ? 'Connected' : 'Reconnecting'}></span>

    <nav class="views" aria-label="Views">
      <button type="button" class:active={tab === 'cards'} onclick={() => (tab = 'cards')}>Cards</button>
      <button type="button" class:active={tab === 'mail'} onclick={() => (tab = 'mail')}>Mail{#if unreadMail > 0} <span class="badge">{unreadMail}</span>{/if}</button>
      <button type="button" class:active={tab === 'sms'} onclick={() => (tab = 'sms')}>SMS{#if unreadSms > 0} <span class="badge">{unreadSms}</span>{/if}</button>
      <button type="button" class:active={tab === 'webhooks'} onclick={() => (tab = 'webhooks')}>Webhooks</button>
    </nav>

    <nav class="projects" aria-label="Projects">
      <button type="button" class:active={project === ''} onclick={() => (project = '')}>All <span class="count">{viewer.cards.length}</span></button>
      {#each projects as name (name)}
        <button type="button" class:active={project === name} onclick={() => (project = name)}>{name} <span class="count">{counts[name] ?? 0}</span></button>
      {/each}
    </nav>

    <span class="spacer"></span>

    <button type="button" class:paused={viewer.receivingPaused} onclick={() => setReceiving(!viewer.receivingPaused)} title="Ignore incoming messages while paused">
      {viewer.receivingPaused ? 'Resume receiving' : 'Pause receiving'}
    </button>
    {#if screens.length > 0}
      <select onchange={openScreen} aria-label="Previous screens">
        <option value="">Previous screens</option>
        {#each screens as screen (screen.id)}
          <option value={screen.id}>{screen.title || 'Untitled'} · {screen.project} · {screen.count} cards · {clock(screen.archived_at)}</option>
        {/each}
      </select>
    {/if}
    <button type="button" onclick={startScreen} disabled={switchProject === null && project === ''} title="Move the current cards to Previous screens">New screen</button>
    <button type="button" onclick={clear} title="Clear the cards of the selected project">Clear</button>
    <button type="button" onclick={() => (showSettings = true)} aria-label="Settings" title="Settings">Settings</button>
  </div>

  {#if tab === 'cards'}
    <div class="line">
      <FilterBar bind:filters shown={visible.length} total={inProject.length} />
      <span class="spacer"></span>
      {#if switchProject !== null}<QuerySwitches project={switchProject} switches={viewer.switches[switchProject]} />{/if}
    </div>
  {/if}
</header>

<main>
  {#if viewer.receivingPaused}
    <div class="banner">Receiving is paused: new messages are ignored. <button type="button" onclick={() => setReceiving(false)}>Resume</button></div>
  {/if}

  {#if archived !== null}
    <div class="banner">
      Previous screen <strong>{archived.title || 'Untitled'}</strong> of {archived.project}, {archived.count} cards, moved aside at {clock(archived.archived_at)}.
      <button type="button" onclick={() => (archived = null)}>Back to live</button>
    </div>
  {/if}

  {#if tab === 'mail'}
    <MailInbox mails={viewer.mails} {project} smtpPort={viewer.settings.smtp_port} onrequest={showRequest} />
  {:else if tab === 'sms'}
    <SmsInbox messages={viewer.sms} {project} onrequest={showRequest} />
  {:else if tab === 'webhooks'}
    <WebhookPage rules={viewer.webhooks.rules} deliveries={viewer.webhooks.deliveries} {project} />
  {:else if rows.length === 0}
    <div class="empty">
      {#if inProject.length > 0}
        <p>No cards match the filters.</p>
      {:else}
        <p>Waiting for <code>peep()</code> calls…</p>
        <p class="hint">Enable Peep in your app with <code>PEEP_ENABLED=true</code>, then call <code>peep($anything)</code>.</p>
      {/if}
    </div>
  {:else}
    <VirtualList items={rows} key={(row) => row.key}>
      {#snippet row(item)}
        {#if item.kind === 'group'}
          <button type="button" class="group" onclick={() => toggleGroup(item.request.id)} aria-expanded={!item.collapsed}>
            <span class="arrow">{item.collapsed ? '▸' : '▾'}</span>
            <span class="kind">{item.request.kind}</span>
            <span class="label">{item.request.label}</span>
            <span class="spacer"></span>
            <span class="count">{item.count} cards</span>
            {#if project === ''}<span class="count">{item.project}</span>{/if}
          </button>
        {:else}
          <Card card={item.card} ide={viewer.settings.ide} />
        {/if}
      {/snippet}
    </VirtualList>
  {/if}
</main>

{#if showSettings}<SettingsDialog onclose={() => (showSettings = false)} />{/if}

<style>
  .toolbar {
    position: sticky;
    top: 0;
    z-index: 2;
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 8px 16px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
  }

  .line {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
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

  .views {
    display: flex;
    gap: 2px;
    padding: 2px;
    background: var(--surface-2);
    border-radius: 8px;
  }

  .views button {
    border: 0;
    background: none;
    padding: 3px 12px;
  }

  .views button.active {
    background: var(--surface);
    box-shadow: 0 0 0 1px var(--border);
    font-weight: 600;
  }

  .badge {
    font-size: 11px;
    border-radius: 999px;
    padding: 0 6px;
    background: var(--accent);
    color: white;
    font-weight: 600;
  }

  .projects {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
  }

  .projects button {
    border-color: transparent;
    background: none;
  }

  .projects button.active {
    background: var(--surface-2);
    border-color: var(--border);
    font-weight: 600;
  }

  .count {
    color: var(--muted);
    font-size: 12px;
    font-weight: normal;
  }

  .spacer {
    flex: 1;
  }

  .paused {
    border-color: var(--orange);
    color: var(--orange);
  }

  button:disabled {
    opacity: 0.5;
    cursor: default;
  }

  .banner {
    margin: 0 0 12px;
    padding: 8px 12px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 8px;
    display: flex;
    gap: 10px;
    align-items: center;
  }

  main {
    padding: 12px 16px 32px;
    max-width: 1200px;
    margin: 0 auto;
  }

  .group {
    display: flex;
    align-items: baseline;
    gap: 10px;
    width: 100%;
    text-align: left;
    background: none;
    border: 0;
    border-bottom: 1px dashed var(--border);
    border-radius: 0;
    padding: 4px 2px;
    font-size: 12px;
    color: var(--muted);
  }

  .group .label {
    color: var(--text);
    font-family: var(--mono);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .group .kind {
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.04em;
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
