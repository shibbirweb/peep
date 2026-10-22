<script>
  import { untrack } from 'svelte';
  import { hasChildren, startsOpen, summary, visibilityMarker } from '../lib/format.js';
  import ValueTree from './ValueTree.svelte';

  /** @type {{ node: object, depth?: number, path?: string, expand?: object|null }} */
  let { node, depth = 0, path = '', expand = null } = $props();

  // Whether a node starts open follows the card's expand setting (default: two levels).
  // Only the starting state matters; later prop changes must not reopen or close the node.
  let open = $state(untrack(() => startsOpen(depth, path, expand)));
  const expandable = $derived(hasChildren(node));
</script>

<span class="value type-{node.type}" class:redacted={node.redacted}>
  {#if expandable}
    <button class="toggle" type="button" onclick={() => (open = !open)} aria-expanded={open}>
      <span class="caret">{open ? '▾' : '▸'}</span>{summary(node)}
    </button>
    {#if node.truncated}<span class="more">+{node.truncated} more</span>{/if}
    {#if open}
      <ul>
        {#each node.children as child, index (index)}
          <li>
            {#if child.visibility}<span class="visibility" title={child.visibility}>{visibilityMarker(child.visibility)}</span>{/if}
            <span class="key">{typeof child.key === 'string' && node.type === 'array' ? JSON.stringify(child.key) : child.key}</span>
            <span class="arrow">{node.type === 'array' ? '=>' : ':'}</span>
            <ValueTree node={child.node} depth={depth + 1} path={path === '' ? String(child.key) : `${path}.${child.key}`} {expand} />
          </li>
        {/each}
      </ul>
    {/if}
  {:else if node.type === 'binary'}
    <span title={node.value}>{summary(node)}</span>
  {:else}
    {summary(node)}
  {/if}
</span>

<style>
  .value {
    font-family: var(--mono);
    font-size: 12.5px;
    white-space: pre-wrap;
    word-break: break-word;
  }

  .toggle {
    border: 0;
    background: none;
    padding: 0;
    font: inherit;
    color: var(--muted);
  }

  .caret {
    display: inline-block;
    width: 1em;
  }

  ul {
    list-style: none;
    margin: 0;
    padding-left: 1.25em;
    border-left: 1px dashed var(--border);
  }

  .key {
    color: var(--key);
  }

  .arrow,
  .visibility,
  .more {
    color: var(--muted);
  }

  .visibility {
    display: inline-block;
    width: 1em;
  }

  .more {
    margin-left: 0.5em;
    font-size: 11px;
  }

  .type-string {
    color: var(--string);
  }

  .type-int,
  .type-float {
    color: var(--number);
  }

  .type-bool,
  .type-null {
    color: var(--keyword);
  }

  .type-ref,
  .type-max_depth,
  .type-resource,
  .type-binary {
    color: var(--muted);
  }

  .redacted {
    color: var(--danger);
  }
</style>
