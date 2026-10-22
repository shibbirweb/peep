<script>
  import { framedHtml } from '../lib/format.js';

  /** @type {{ html: string, title?: string }} */
  let { html, title = 'HTML' } = $props();
</script>

<!-- No allow-scripts and no allow-same-origin: app HTML can neither run code nor reach the viewer. -->
<iframe {title} sandbox="" srcdoc={framedHtml(html)}></iframe>

<style>
  iframe {
    display: block;
    width: 100%;
    min-height: 160px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: white;
    resize: vertical;
  }
</style>
