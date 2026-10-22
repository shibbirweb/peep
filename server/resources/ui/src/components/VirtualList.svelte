<script>
  // Renders only the rows near the viewport, so 10,000+ cards stay smooth. Rows have
  // different heights: each is measured once rendered, and an estimate is used until then.
  import { onMount } from 'svelte';

  /** @type {{ items: Array<object>, key: (item: object) => string, row: import('svelte').Snippet<[object]>, estimate?: number, gap?: number }} */
  let { items, key, row, estimate = 120, gap = 8 } = $props();

  const OVERSCAN = 1200;

  const heights = new Map();
  let version = $state(0);
  let element;
  let top = $state(0);
  let scrollY = $state(0);
  let viewport = $state(800);

  let pendingScroll = 0;
  let frame = 0;
  let previousFirstKey = null;

  const offsets = $derived.by(() => {
    version;

    const result = new Float64Array(items.length + 1);
    for (let index = 0; index < items.length; index++) {
      result[index + 1] = result[index] + (heights.get(key(items[index])) ?? estimate) + gap;
    }

    return result;
  });

  const indexOf = $derived(new Map(items.map((item, index) => [key(item), index])));

  const visible = $derived.by(() => {
    const start = scrollY - top - OVERSCAN;
    const end = scrollY - top + viewport + OVERSCAN;
    const indexes = [];

    for (let index = firstEndingAfter(offsets, start); index < items.length && offsets[index] < end; index++) {
      indexes.push(index);
    }

    return indexes;
  });

  // New cards arrive on top. When the user has scrolled down to read, keep the same
  // cards under their eyes instead of pushing them down.
  $effect(() => {
    const firstKey = items.length > 0 ? key(items[0]) : null;
    const previousIndex = previousFirstKey === null ? -1 : (indexOf.get(previousFirstKey) ?? -1);

    if (previousIndex > 0 && window.scrollY > top) {
      window.scrollBy(0, offsets[previousIndex]);
    }

    previousFirstKey = firstKey;
  });

  onMount(() => {
    const update = () => {
      scrollY = window.scrollY;
      viewport = window.innerHeight;
      top = element.getBoundingClientRect().top + window.scrollY;
    };

    update();
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);

    return () => {
      window.removeEventListener('scroll', update);
      window.removeEventListener('resize', update);
      cancelAnimationFrame(frame);
    };
  });

  function measure(node, itemKey) {
    let current = itemKey;
    const observer = new ResizeObserver(() => record(current, node.offsetHeight));
    observer.observe(node);

    return {
      update(next) {
        current = next;
        record(current, node.offsetHeight);
      },
      destroy() {
        observer.disconnect();
      },
    };
  }

  function record(itemKey, height) {
    const previous = heights.get(itemKey) ?? estimate;

    if (heights.get(itemKey) === height) {
      return;
    }

    heights.set(itemKey, height);

    // A row above the viewport that grows or shrinks would move what the user reads.
    const index = indexOf.get(itemKey);
    if (index !== undefined && offsets[index] + previous <= window.scrollY - top) {
      pendingScroll += height - previous;
    }

    if (frame === 0) {
      frame = requestAnimationFrame(() => {
        frame = 0;
        version++;

        if (pendingScroll !== 0) {
          window.scrollBy(0, pendingScroll);
          pendingScroll = 0;
        }
      });
    }
  }

  function firstEndingAfter(list, position) {
    let low = 0;
    let high = list.length - 1;

    while (low < high) {
      const middle = (low + high) >> 1;

      if (list[middle + 1] > position) {
        high = middle;
      } else {
        low = middle + 1;
      }
    }

    return low;
  }
</script>

<div class="virtual" bind:this={element} style:height="{Math.max(0, offsets[items.length] - gap)}px">
  {#each visible as index (key(items[index]))}
    <div class="row" style:transform="translateY({offsets[index]}px)" use:measure={key(items[index])}>
      {@render row(items[index])}
    </div>
  {/each}
</div>

<style>
  .virtual {
    position: relative;
  }

  .row {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
  }
</style>
