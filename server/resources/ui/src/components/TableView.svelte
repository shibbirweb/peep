<script>
  import { tableOf } from '../lib/format.js';
  import ValueTree from './ValueTree.svelte';

  /** @type {{ rows: object, label?: string|null }} */
  let { rows, label = null } = $props();

  const table = $derived(tableOf(rows));
</script>

{#if label}<div class="label">{label}</div>{/if}
<div class="scroll">
  <table>
    <thead>
      <tr>
        {#each table.columns as column (column)}<th>{column}</th>{/each}
      </tr>
    </thead>
    <tbody>
      {#each table.rows as row (row.key)}
        <tr>
          <th>{row.key}</th>
          {#each row.cells as cell, index (index)}
            <td>{#if cell}<ValueTree node={cell} depth={2} />{/if}</td>
          {/each}
        </tr>
      {/each}
    </tbody>
  </table>
</div>

<style>
  .label {
    font-weight: 600;
    margin-bottom: 4px;
  }

  .scroll {
    overflow-x: auto;
  }

  table {
    border-collapse: collapse;
    font-size: 12.5px;
  }

  th,
  td {
    border: 1px solid var(--border);
    padding: 3px 8px;
    text-align: left;
    vertical-align: top;
  }

  thead th {
    background: var(--surface-2);
  }

  tbody th {
    font-family: var(--mono);
    font-weight: normal;
    color: var(--muted);
  }
</style>
