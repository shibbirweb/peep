<script>
  // The Logs tab: a project's log files on the left, the open file's entries on the right,
  // watched live (or paused), newest or oldest first, filtered by level and text.
  import { tick } from 'svelte';
  import { loadLogEntries, loadLogFiles, setLogFolder } from '../lib/api.js';
  import { clock, formatBytes } from '../lib/format.js';
  import { LEVELS, appendEntries, levelCounts, prependEntries, visibleEntries } from '../lib/logs.js';
  import { viewer } from '../lib/stream.svelte.js';
  import LogEntry from './LogEntry.svelte';
  import VirtualList from './VirtualList.svelte';

  /** @type {{ project: string, ide: string }} */
  let { project, ide } = $props();

  const POLL_MS = 1000;
  const PAGE = 500;

  let picked = $state('');
  let files = $state([]);
  let filesError = $state('');
  let file = $state('');
  let entries = $state([]);
  let start = $state(0);
  let end = $state(0);
  let size = $state(0);
  let moreBefore = $state(false);
  let loading = $state(false);
  let error = $state('');
  let live = $state(true);
  let order = $state('newest');
  let levels = $state(new Set(LEVELS));
  let search = $state('');
  let view = $state('tree');
  let opened = $state(new Set());
  let editing = $state(false);
  let folderInput = $state('');
  let folderError = $state('');

  const folders = $derived(viewer.logFolders);
  const current = $derived(project !== '' ? project : folders.length === 1 ? folders[0].project : picked);
  const folder = $derived(folders.find((candidate) => candidate.project === current) ?? null);
  // A primitive, so a refreshed folder list with the same path does not reopen the file.
  const folderPath = $derived(folder?.path ?? null);
  const counts = $derived(levelCounts(entries));
  const shown = $derived(visibleEntries(entries, { levels, search, order }));

  // A new project or folder: list its files and open the newest.
  $effect(() => {
    const name = current;
    const path = folderPath;

    file = '';
    entries = [];
    files = [];
    filesError = '';
    editing = false;

    if (name && path) {
      refreshFiles(name, true);
    }
  });

  // Watching: ask for what was added every second; the file list follows every few seconds.
  $effect(() => {
    if (!live || file === '' || !current) {
      return;
    }

    const name = current;
    const watched = file;
    let ticks = 0;
    const timer = setInterval(() => {
      poll(name, watched);
      ticks += 1;

      if (ticks % 5 === 0) {
        refreshFiles(name, false);
      }
    }, POLL_MS);

    return () => clearInterval(timer);
  });

  async function refreshFiles(name, openNewest) {
    try {
      const data = await loadLogFiles(name);

      if (name !== current) {
        return;
      }

      files = data.files;
      filesError = data.error ?? '';

      if (openNewest && files.length > 0) {
        open(files[0].name);
      }
    } catch (exception) {
      filesError = exception.message;
    }
  }

  async function open(name) {
    file = name;
    entries = [];
    opened = new Set();
    error = '';
    loading = true;

    try {
      const data = await loadLogEntries(current, name, { limit: PAGE });

      if (file !== name) {
        return;
      }

      entries = data.entries;
      start = data.start;
      end = data.end;
      size = data.size;
      moreBefore = data.more_before;
    } catch (exception) {
      error = exception.message;
    } finally {
      loading = false;
    }
  }

  async function poll(name, watched) {
    if (loading) {
      return;
    }

    try {
      const data = await loadLogEntries(name, watched, { after: end });

      if (file !== watched || current !== name) {
        return;
      }

      size = data.size;

      if (data.reset) {
        entries = data.entries;
        start = data.start;
        end = data.end;
        moreBefore = data.more_before;

        return;
      }

      if (data.entries.length === 0) {
        end = data.end;

        return;
      }

      const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 80;
      const result = appendEntries(entries, data.entries);
      entries = result.entries;
      end = data.end;

      if (result.dropped) {
        start = entries[0].offset;
        moreBefore = true;
      }

      // Oldest first: follow the end of the file while the reader is there.
      if (order === 'oldest' && atBottom) {
        await tick();
        window.scrollTo(0, document.documentElement.scrollHeight);
      }
    } catch (exception) {
      error = exception.message;
      live = false;
    }
  }

  async function older() {
    loading = true;

    try {
      const data = await loadLogEntries(current, file, { before: start, limit: PAGE });
      entries = prependEntries(entries, data.entries);
      start = data.start;
      moreBefore = data.more_before;
    } catch (exception) {
      error = exception.message;
    } finally {
      loading = false;
    }
  }

  function toggleLevel(level) {
    const next = new Set(levels);

    if (next.has(level)) {
      next.delete(level);
    } else {
      next.add(level);
    }

    levels = next;
  }

  function onlyFrom(level) {
    levels = new Set(LEVELS.slice(LEVELS.indexOf(level)));
  }

  function toggleEntry(offset) {
    const next = new Set(opened);

    if (next.has(offset)) {
      next.delete(offset);
    } else {
      next.add(offset);
    }

    opened = next;
  }

  async function saveFolder(path) {
    folderError = '';

    try {
      await setLogFolder(current, path);
      editing = false;
    } catch (exception) {
      folderError = exception.message;
    }
  }
</script>

<div class="logs">
  <aside>
    {#if project === '' && folders.length > 1}
      <div class="section">
        <div class="heading">Project</div>
        {#each folders as candidate (candidate.project)}
          <button type="button" class="item" class:selected={candidate.project === current} onclick={() => (picked = candidate.project)}>{candidate.project}</button>
        {/each}
      </div>
    {/if}

    {#if current}
      <div class="section folder">
        <div class="heading">Log folder</div>
        {#if editing || folder === null}
          <form
            onsubmit={(event) => {
              event.preventDefault();
              saveFolder(folderInput);
            }}
          >
            <input type="text" bind:value={folderInput} placeholder="/path/to/app/storage/logs" aria-label="Log folder" />
            <div class="row">
              <button type="submit" disabled={folderInput.trim() === ''}>Use this folder</button>
              {#if folder !== null}<button type="button" onclick={() => (editing = false)}>Cancel</button>{/if}
            </div>
          </form>
          {#if folder === null}<p class="hint">{current} has not reported a log folder. Laravel apps report <code>storage/logs</code> with their next card; or type a folder.</p>{/if}
        {:else}
          <code class="path" title={folder.path}>{folder.path}</code>
          <div class="row">
            <span class="muted">{folder.manual ? 'chosen here' : 'from the app'} · {folder.timezone}</span>
            <span class="spacer"></span>
            <button type="button" onclick={() => ((folderInput = folder.path), (editing = true))}>Change</button>
            {#if folder.manual}<button type="button" onclick={() => saveFolder(null)} title="Use the folder the app reports">Use app's</button>{/if}
          </div>
        {/if}
        {#if folderError}<p class="error">{folderError}</p>{/if}
      </div>

      {#if folder !== null}
        <div class="section">
          <div class="heading">Files</div>
          {#if filesError}<p class="error">{filesError}</p>{/if}
          <ul>
            {#each files as candidate (candidate.name)}
              <li>
                <button type="button" class="item file" class:selected={candidate.name === file} onclick={() => open(candidate.name)}>
                  <span class="name">{candidate.name}</span>
                  <span class="muted">{formatBytes(candidate.size)} · {clock(candidate.modified)}</span>
                </button>
              </li>
            {:else}
              {#if !filesError}<li class="hint">No .log files here yet.</li>{/if}
            {/each}
          </ul>
        </div>
      {/if}
    {:else}
      <p class="hint pad">
        {#if folders.length === 0}
          No project has a log folder yet. Laravel apps report <code>storage/logs</code> with their first card; select a project to choose a folder by hand.
        {:else}
          Select a project.
        {/if}
      </p>
    {/if}
  </aside>

  <section class="entries">
    {#if file === ''}
      <p class="placeholder">{folder === null ? 'Choose a log folder.' : 'Select a log file.'}</p>
    {:else}
      <div class="tools">
        <button type="button" class="live" class:on={live} onclick={() => (live = !live)} title={live ? 'Pause: stop showing new entries' : 'Watch: show new entries as they are written'}>
          <span class="dot"></span>{live ? 'Live' : 'Paused'}
        </button>
        <button type="button" onclick={() => (order = order === 'newest' ? 'oldest' : 'newest')} title="Change the order">
          {order === 'newest' ? '↓ Newest first' : '↑ Oldest first'}
        </button>
        <div class="levels" role="group" aria-label="Levels">
          {#each LEVELS as level (level)}
            <button
              type="button"
              class="level level-{level}"
              class:off={!levels.has(level)}
              onclick={() => toggleLevel(level)}
              ondblclick={() => onlyFrom(level)}
              title="Show or hide {level}. Double-click: {level} and above only"
            >
              {level} <span class="count">{counts[level]}</span>
            </button>
          {/each}
        </div>
        <input type="search" placeholder="Search entries" bind:value={search} aria-label="Search log entries" />
        <div class="views" role="group" aria-label="Context view">
          {#each [['tree', 'Tree'], ['json', 'JSON'], ['raw', 'Raw']] as [id, name] (id)}
            <button type="button" class:active={view === id} onclick={() => (view = id)}>{name}</button>
          {/each}
        </div>
      </div>

      <div class="status">
        <strong>{file}</strong> · {formatBytes(size)} · {shown.length} of {entries.length} entries shown
        {#if moreBefore && order === 'newest'}· <button type="button" class="link" onclick={older} disabled={loading}>Load older</button>{/if}
      </div>

      {#if error}<p class="error">{error}</p>{/if}

      {#if moreBefore && order === 'oldest'}
        <div class="more"><button type="button" onclick={older} disabled={loading}>Load older entries</button></div>
      {/if}

      {#if loading && entries.length === 0}
        <p class="placeholder">Reading…</p>
      {:else if shown.length === 0}
        <p class="placeholder">{entries.length === 0 ? (size > 0 ? 'No entries in the Laravel (Monolog line) format.' : 'This file is empty.') : 'No entries match.'}</p>
      {:else}
        <VirtualList items={shown} key={(entry) => `${file}:${entry.offset}`} estimate={34} gap={4}>
          {#snippet row(entry)}
            <LogEntry {entry} {view} {ide} open={opened.has(entry.offset)} ontoggle={() => toggleEntry(entry.offset)} />
          {/snippet}
        </VirtualList>
      {/if}

      {#if moreBefore && order === 'newest'}
        <div class="more"><button type="button" onclick={older} disabled={loading}>Load older entries</button></div>
      {/if}
    {/if}
  </section>
</div>

<style>
  .logs {
    display: grid;
    grid-template-columns: minmax(220px, 300px) 1fr;
    gap: 12px;
    align-items: start;
  }

  aside {
    position: sticky;
    top: 60px;
    max-height: calc(100vh - 130px);
    overflow: auto;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
  }

  .section {
    padding: 8px;
    border-bottom: 1px solid var(--border);
  }

  .heading {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--muted);
    margin-bottom: 6px;
  }

  ul {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .item {
    display: flex;
    flex-direction: column;
    gap: 1px;
    width: 100%;
    text-align: left;
    border: 0;
    border-radius: 6px;
    background: none;
    padding: 5px 8px;
  }

  .item.selected {
    background: var(--surface-2);
    font-weight: 600;
  }

  .file .name {
    font-family: var(--mono);
    font-size: 12.5px;
    overflow-wrap: anywhere;
  }

  .path {
    display: block;
    overflow-wrap: anywhere;
    font-size: 12px;
    margin-bottom: 6px;
  }

  .row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    margin-top: 6px;
  }

  .row button {
    font-size: 12px;
    padding: 2px 8px;
  }

  input {
    width: 100%;
    font: inherit;
    color: inherit;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 3px 8px;
  }

  .tools input {
    width: auto;
    min-width: 160px;
    flex: 1;
  }

  .tools {
    position: sticky;
    top: 52px;
    z-index: 1;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding: 8px 0;
    background: var(--bg);
  }

  .live {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .live .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--gray);
  }

  .live.on {
    border-color: var(--green);
  }

  .live.on .dot {
    background: var(--green);
    animation: pulse 1.6s ease-in-out infinite;
  }

  @keyframes pulse {
    50% {
      opacity: 0.35;
    }
  }

  .levels,
  .views {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
  }

  .level {
    --level: var(--gray);
    font-size: 11px;
    text-transform: uppercase;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 999px;
    color: color-mix(in srgb, var(--level) 65%, var(--text));
    border-color: color-mix(in srgb, var(--level) 45%, transparent);
    background: color-mix(in srgb, var(--level) 14%, transparent);
  }

  .level.off {
    opacity: 0.35;
    background: none;
  }

  .level .count {
    font-weight: normal;
    color: var(--muted);
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

  .views button.active {
    background: var(--surface-2);
    font-weight: 600;
  }

  .status {
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 8px;
  }

  .status strong {
    color: var(--text);
    font-family: var(--mono);
  }

  .link {
    border: 0;
    background: none;
    padding: 0;
    color: var(--accent);
    font-size: 12px;
  }

  .more {
    display: flex;
    justify-content: center;
    margin: 10px 0;
  }

  .placeholder,
  .pad {
    padding: 24px 16px;
    color: var(--muted);
    text-align: center;
  }

  .hint {
    font-size: 12px;
    color: var(--muted);
  }

  .muted {
    color: var(--muted);
    font-size: 12px;
  }

  .spacer {
    flex: 1;
  }

  .error {
    color: var(--danger);
    font-size: 13px;
  }

  button:disabled {
    opacity: 0.45;
    cursor: default;
  }

  @media (max-width: 720px) {
    .logs {
      grid-template-columns: 1fr;
    }

    aside {
      position: static;
      max-height: 40vh;
    }
  }
</style>
