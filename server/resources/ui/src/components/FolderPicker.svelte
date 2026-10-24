<script>
  // The Logs tab's folder picker in a browser (the desktop app opens the system's own): a
  // browser cannot tell a page a folder's path, so the viewer lists the folders and the reader
  // clicks through them. Laravel apps are marked and their storage/logs is one click away.
  import { onMount } from 'svelte';
  import { browseFolders } from '../lib/api.js';
  import { pathCrumbs } from '../lib/logs.js';

  /** @type {{ start: string, onchoose: (path: string) => void, onclose: () => void }} */
  let { start, onchoose, onclose } = $props();

  let dialog;
  let listing = $state(null);
  let error = $state('');
  let filter = $state('');
  let loading = $state(false);

  const crumbs = $derived(listing === null ? [] : pathCrumbs(listing.path));
  const shown = $derived(listing === null ? [] : listing.folders.filter((folder) => folder.name.toLowerCase().includes(filter.trim().toLowerCase())));

  onMount(() => {
    dialog.showModal();
    go(start.trim() !== '' ? start.trim() : null);
  });

  async function go(path) {
    error = '';
    loading = true;

    try {
      listing = await browseFolders(path);
      filter = '';
    } catch (exception) {
      error = exception.message;
    } finally {
      loading = false;
    }
  }

  function choose(path) {
    onchoose(path);
    dialog.close();
  }

  function laravelLogs(folder) {
    return `${folder.path}${folder.path.includes('\\') ? '\\storage\\logs' : '/storage/logs'}`;
  }
</script>

<dialog bind:this={dialog} {onclose} aria-labelledby="picker-title">
  <header>
    <h2 id="picker-title">Choose a log folder</h2>
    <button type="button" class="close" onclick={() => dialog.close()} aria-label="Close">×</button>
  </header>

  {#if listing !== null}
    <nav class="crumbs" aria-label="Path">
      {#each crumbs as crumb, index (crumb.path)}
        {#if index > 0}<span class="separator">›</span>{/if}
        <button type="button" class="crumb" class:current={index === crumbs.length - 1} onclick={() => go(crumb.path)}>{crumb.name}</button>
      {/each}
    </nav>

    <div class="tools">
      <button type="button" onclick={() => go(listing.parent)} disabled={listing.parent === null || loading}>↑ Up</button>
      {#if listing.home !== null}<button type="button" onclick={() => go(listing.home)} disabled={loading}>Home</button>{/if}
      <input type="search" placeholder="Filter folders" bind:value={filter} aria-label="Filter folders" />
    </div>

    <ul class:loading>
      {#each shown as folder (folder.path)}
        <li>
          <button type="button" class="folder" onclick={() => go(folder.path)} title="Open {folder.name}">
            <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M1.5 3.5h4l1.5 1.5h7.5v8h-13z" /></svg>
            <span class="name">{folder.name}</span>
            <span class="open">›</span>
          </button>
          {#if folder.laravel}
            <button type="button" class="use" onclick={() => choose(laravelLogs(folder))} title="Use {folder.name}/storage/logs">Use its storage/logs</button>
          {/if}
        </li>
      {:else}
        <li class="empty muted">{filter.trim() !== '' ? 'No folders match.' : 'No folders here.'}</li>
      {/each}
    </ul>
    {#if listing.more}<p class="muted note">Only the first 1000 folders are listed.</p>{/if}
  {:else if !error}
    <p class="muted note">Reading…</p>
  {/if}

  {#if error}<p class="error" role="alert">{error}</p>{/if}

  <footer>
    {#if listing !== null}
      <span class="muted">{listing.logs === 0 ? 'No .log files here' : `${listing.logs} .log file${listing.logs === 1 ? '' : 's'} here`}</span>
    {/if}
    <span class="spacer"></span>
    <button type="button" onclick={() => dialog.close()}>Cancel</button>
    {#if listing?.laravel_logs}
      <button type="button" class="primary" onclick={() => choose(listing.laravel_logs)}>Use storage/logs</button>
    {/if}
    <button type="button" class:primary={!listing?.laravel_logs} onclick={() => choose(listing.path)} disabled={listing === null}>Use this folder</button>
  </footer>
</dialog>

<style>
  dialog {
    width: min(560px, calc(100vw - 32px));
    max-height: calc(100vh - 48px);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 16px 20px;
    background: var(--surface);
    color: var(--text);
  }

  dialog[open] {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  dialog::backdrop {
    background: rgb(0 0 0 / 40%);
  }

  header {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  h2 {
    margin: 0;
    font-size: 17px;
  }

  .close {
    border: 0;
    font-size: 20px;
    line-height: 1;
  }

  .crumbs {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 2px;
    font-family: var(--mono);
    font-size: 12px;
  }

  .crumb {
    border: 0;
    background: none;
    padding: 1px 4px;
    color: var(--accent);
  }

  .crumb.current {
    color: var(--text);
    font-weight: 600;
  }

  .separator {
    color: var(--muted);
  }

  .tools {
    display: flex;
    gap: 6px;
  }

  .tools button {
    flex: none;
  }

  input {
    flex: 1;
    min-width: 0;
    font: inherit;
    color: inherit;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 3px 8px;
  }

  ul {
    list-style: none;
    margin: 0;
    padding: 4px;
    height: min(360px, calc(100vh - 280px));
    overflow: auto;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg);
  }

  ul.loading {
    opacity: 0.6;
  }

  li {
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .folder {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    border: 0;
    border-radius: 6px;
    background: none;
    padding: 5px 8px;
    text-align: left;
  }

  .folder:hover {
    background: var(--surface-2);
  }

  svg {
    flex: none;
    width: 15px;
    height: 15px;
    fill: color-mix(in srgb, var(--accent) 35%, transparent);
    stroke: var(--accent);
    stroke-width: 1;
  }

  .name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .open {
    color: var(--muted);
  }

  .use {
    flex: none;
    font-size: 12px;
    padding: 2px 8px;
    color: var(--accent);
    border-color: color-mix(in srgb, var(--accent) 45%, transparent);
  }

  .empty {
    padding: 16px;
    justify-content: center;
  }

  .note {
    margin: 0;
    font-size: 12px;
  }

  footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
  }

  .spacer {
    flex: 1;
  }

  .primary {
    background: var(--accent);
    border-color: var(--accent);
    color: white;
  }

  .muted {
    color: var(--muted);
    font-size: 12px;
  }

  .error {
    margin: 0;
    color: var(--danger);
  }
</style>
