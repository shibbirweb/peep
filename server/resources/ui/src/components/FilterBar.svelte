<script>
  import { COLORS, KINDS, isFiltering, toggle } from '../lib/filters.js';

  /** @type {{ filters: {kinds: Array<string>, colors: Array<string>, search: string}, shown: number, total: number }} */
  let { filters = $bindable(), shown, total } = $props();
</script>

<div class="filters">
  <input class="search" type="search" placeholder="Search values, SQL, files…" bind:value={filters.search} aria-label="Search" />

  <div class="chips" role="group" aria-label="Filter by kind">
    {#each KINDS as kind (kind.id)}
      <button type="button" class="chip" class:on={filters.kinds.includes(kind.id)} aria-pressed={filters.kinds.includes(kind.id)} onclick={() => (filters.kinds = toggle(filters.kinds, kind.id))}>{kind.name}</button>
    {/each}
  </div>

  <div class="colors" role="group" aria-label="Filter by color">
    {#each [...COLORS, 'none'] as color (color)}
      <button
        type="button"
        class="dot"
        class:on={filters.colors.includes(color)}
        class:none={color === 'none'}
        style:--dot={color === 'none' ? 'transparent' : `var(--${color})`}
        title={color === 'none' ? 'No color' : color}
        aria-label={color === 'none' ? 'No color' : color}
        aria-pressed={filters.colors.includes(color)}
        onclick={() => (filters.colors = toggle(filters.colors, color))}
      ></button>
    {/each}
  </div>

  {#if isFiltering(filters)}
    <span class="count">{shown} of {total}</span>
    <button type="button" class="reset" onclick={() => (filters = { kinds: [], colors: [], search: '' })}>Reset</button>
  {/if}
</div>

<style>
  .filters {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
  }

  .search {
    font: inherit;
    color: inherit;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 4px 10px;
    min-width: 220px;
    flex: 1 1 220px;
    max-width: 360px;
  }

  .chips {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
  }

  .chip {
    padding: 1px 8px;
    font-size: 12px;
    border-radius: 999px;
  }

  .chip.on {
    background: var(--accent);
    border-color: var(--accent);
    color: white;
  }

  .colors {
    display: flex;
    gap: 4px;
  }

  .dot {
    width: 16px;
    height: 16px;
    padding: 0;
    border-radius: 50%;
    background: var(--dot);
    border: 2px solid transparent;
  }

  .dot.none {
    border: 1px dashed var(--muted);
  }

  .dot.on {
    outline: 2px solid var(--text);
    outline-offset: 1px;
  }

  .count {
    font-size: 12px;
    color: var(--muted);
  }

  .reset {
    font-size: 12px;
    padding: 1px 8px;
  }
</style>
