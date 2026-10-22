<script>
  // WH-08: every webhook call with its request and response; resend.
  import { clearDeliveries, loadDelivery, resendDelivery } from '../lib/api.js';
  import { clock } from '../lib/format.js';

  /** @type {{ deliveries: Array<object> }} */
  let { deliveries } = $props();

  let openId = $state(null);
  let detail = $state(null);

  async function toggle(delivery) {
    if (openId === delivery.id) {
      openId = null;

      return;
    }

    openId = delivery.id;
    detail = null;
    detail = await loadDelivery(delivery.id).catch(() => null);
  }

  const headerText = (headers) => Object.entries(headers ?? {}).map(([name, value]) => `${name}: ${value}`).join('\n');
</script>

<section class="log">
  <header>
    <h3>Delivery log</h3>
    <span class="spacer"></span>
    <button type="button" onclick={clearDeliveries} disabled={deliveries.length === 0}>Clear</button>
  </header>

  {#each deliveries as delivery (delivery.id)}
    <div class="delivery" class:failed={!delivery.ok}>
      <button type="button" class="line" onclick={() => toggle(delivery)} aria-expanded={openId === delivery.id}>
        <span class="status">{delivery.status ?? 'error'}</span>
        <span class="event">{delivery.event}</span>
        <span class="url">{delivery.method} {delivery.url}</span>
        <span class="spacer"></span>
        <span class="muted">{delivery.rule_name}{delivery.attempt > 1 ? ` · attempt ${delivery.attempt}` : ''}</span>
        <span class="muted">{delivery.duration_ms} ms</span>
        <time>{clock(delivery.at)}</time>
      </button>
      {#if delivery.error}<p class="error">{delivery.error}</p>{/if}
      {#if delivery.warnings.length}<p class="warning">{delivery.warnings.join(' · ')}</p>{/if}

      {#if openId === delivery.id}
        {#if detail === null}
          <p class="muted">Loading…</p>
        {:else}
          <div class="detail">
            <div>
              <h4>Request</h4>
              <pre>{detail.request.method} {detail.request.url}
{headerText(detail.request.headers)}

{detail.request.body}</pre>
            </div>
            <div>
              <h4>Response</h4>
              {#if detail.response}
                <pre>{detail.response.status}
{headerText(detail.response.headers)}

{detail.response.body}{detail.response.truncated ? '\n… cut at 64 KB' : ''}</pre>
              {:else}
                <pre>No response.</pre>
              {/if}
            </div>
          </div>
          <button type="button" onclick={() => resendDelivery(delivery.id)} disabled={detail.request.headers && Object.keys(detail.request.headers).length === 0}>Resend</button>
        {/if}
      {/if}
    </div>
  {:else}
    <p class="muted empty">No webhook calls yet.</p>
  {/each}
</section>

<style>
  .log header {
    display: flex;
    align-items: center;
  }

  h3 {
    margin: 0;
    font-size: 15px;
  }

  h4 {
    margin: 6px 0 4px;
    font-size: 12px;
    color: var(--muted);
  }

  .spacer {
    flex: 1;
  }

  .delivery {
    border-bottom: 1px solid var(--border);
    padding: 4px 0;
  }

  .line {
    display: flex;
    gap: 10px;
    align-items: baseline;
    width: 100%;
    text-align: left;
    border: 0;
    background: none;
    padding: 4px 2px;
    font-size: 12.5px;
  }

  .status {
    font-weight: 700;
    color: var(--green);
    min-width: 36px;
  }

  .failed .status {
    color: var(--danger);
  }

  .event {
    font-weight: 600;
  }

  .url {
    font-family: var(--mono);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
  }

  .muted,
  time {
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
  }

  .error,
  .warning {
    margin: 0 0 4px 48px;
    font-size: 12px;
  }

  .error {
    color: var(--danger);
  }

  .warning {
    color: var(--orange);
  }

  .detail {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
  }

  pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    background: var(--surface-2);
    border-radius: 6px;
    padding: 8px;
    max-height: 320px;
    overflow: auto;
    margin: 0;
  }

  .empty {
    padding: 12px 0;
  }

  @media (max-width: 720px) {
    .detail {
      grid-template-columns: 1fr;
    }
  }
</style>
