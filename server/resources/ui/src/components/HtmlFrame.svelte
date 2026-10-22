<script>
  import { framedHtml } from '../lib/format.js';

  /** @type {{ html: string, title?: string, document?: boolean, height?: string, width?: string }} */
  let { html, title = 'HTML', document = false, height = undefined, width = '100%' } = $props();

  // A whole document (a mail with its own <html>) is shown as it is; fragments get a light page.
  const source = $derived(document && /<html[\s>]/i.test(html) ? html : framedHtml(html));
</script>

<!-- No allow-scripts and no allow-same-origin: app HTML can neither run code nor reach the viewer. -->
<iframe {title} sandbox="" srcdoc={source} style:height style:width></iframe>

<style>
  iframe {
    display: block;
    width: 100%;
    min-height: 160px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: white;
    resize: vertical;
    margin: 0 auto;
  }
</style>
