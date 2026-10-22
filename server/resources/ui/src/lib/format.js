// Display helpers for value tree nodes and cards.

/**
 * One-line summary of a node, shown next to its toggle or as the whole value for scalars.
 *
 * @param {object} node
 * @returns {string}
 */
export function summary(node) {
  switch (node.type) {
    case 'null':
      return 'null';
    case 'bool':
      return node.value ? 'true' : 'false';
    case 'int':
    case 'float':
      return String(node.value);
    case 'string':
      return JSON.stringify(node.value) + (node.truncated ? ` … +${node.truncated} chars` : '');
    case 'binary':
      return `binary(${node.length} bytes)`;
    case 'array':
      return `array(${node.length})`;
    case 'object':
      if (node.lazy) {
        return `${node.class} #${node.id} (lazy, not initialized)`;
      }

      return node.summary ? `${node.class} #${node.id} · ${node.summary}` : `${node.class} #${node.id}`;
    case 'ref':
      return `→ #${node.id}`;
    case 'resource':
      return `resource(${node.value})`;
    case 'max_depth':
      return node.class ? `${node.class} …` : `array(${node.length}) …`;
    default:
      return node.type;
  }
}

/**
 * @param {object} node
 * @returns {boolean}
 */
export function hasChildren(node) {
  return (node.type === 'array' || node.type === 'object') && Array.isArray(node.children) && node.children.length > 0;
}

/**
 * Visibility marker, like PHP's var_dump: + public, # protected, - private.
 *
 * @param {string|undefined} visibility
 * @returns {string}
 */
export function visibilityMarker(visibility) {
  return { public: '+', protected: '#', private: '-' }[visibility] ?? '';
}

/**
 * @param {number} seconds Unix time.
 * @returns {string}
 */
export function clock(seconds) {
  return new Date(seconds * 1000).toLocaleTimeString([], {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  });
}

/**
 * @param {string} json
 * @returns {{ ok: boolean, text: string }}
 */
export function prettyJson(json) {
  try {
    return { ok: true, text: JSON.stringify(JSON.parse(json), null, 2) };
  } catch {
    return { ok: false, text: json };
  }
}

/**
 * Turns an array node into table rows: a list of objects/arrays becomes columns,
 * anything else becomes key/value rows.
 *
 * @param {object} node
 * @returns {{ columns: Array<string>, rows: Array<{ key: string, cells: Array<object|null> }> }}
 */
export function tableOf(node) {
  const children = node.children ?? [];
  const nested = children.length > 0 && children.every((child) => hasChildren(child.node));

  if (!nested) {
    return {
      columns: ['Key', 'Value'],
      rows: children.map((child) => ({ key: String(child.key), cells: [child.node] })),
    };
  }

  const columns = [];
  for (const child of children) {
    for (const cell of child.node.children) {
      if (!columns.includes(String(cell.key))) {
        columns.push(String(cell.key));
      }
    }
  }

  return {
    columns: ['#', ...columns],
    rows: children.map((child) => ({
      key: String(child.key),
      cells: columns.map((column) => child.node.children.find((cell) => String(cell.key) === column)?.node ?? null),
    })),
  };
}

/**
 * Whether a value tree node starts open, given the card's expand setting.
 *
 * @param {number} depth
 * @param {string} path Dot-separated keys from the root, '' for the root.
 * @param {{ level?: number|null, keys?: Array<string>, all?: boolean }|null} expand
 * @returns {boolean}
 */
export function startsOpen(depth, path, expand) {
  if (expand?.all) {
    return true;
  }

  if (depth < (expand?.level ?? 2)) {
    return true;
  }

  return (expand?.keys ?? []).some((key) => key === path || key.startsWith(`${path}.`));
}

/**
 * @param {number} bytes
 * @returns {string}
 */
export function formatBytes(bytes) {
  if (bytes < 1024) {
    return `${bytes} B`;
  }

  const units = ['KB', 'MB', 'GB'];
  let value = bytes / 1024;
  let unit = 0;

  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }

  return `${value.toFixed(1)} ${units[unit]}`;
}

/**
 * Indents XML for display. Not a validator: malformed XML still comes out readable.
 *
 * @param {string} xml
 * @returns {string}
 */
export function formatXml(xml) {
  const tokens = xml.replace(/>\s+</g, '><').match(/<[^>]+>|[^<]+/g) ?? [];
  const lines = [];
  let depth = 0;

  for (const raw of tokens) {
    const token = raw.trim();

    if (token === '') {
      continue;
    }

    const isTag = token.startsWith('<');
    const closing = token.startsWith('</');
    const standalone = token.endsWith('/>') || token.startsWith('<?') || token.startsWith('<!');

    if (!isTag) {
      // Text joins the line of its opening tag: <name>text
      lines[lines.length - 1] = (lines[lines.length - 1] ?? '') + token;
      continue;
    }

    if (closing) {
      depth = Math.max(0, depth - 1);
      const previous = lines[lines.length - 1];

      // Keep <name>text</name> on one line.
      if (previous !== undefined && /<[^/!?][^>]*>[^<]+$/.test(previous)) {
        lines[lines.length - 1] = previous + token;
        continue;
      }
    }

    lines.push('  '.repeat(depth) + token);

    if (!closing && !standalone) {
      depth += 1;
    }
  }

  return lines.join('\n');
}

/**
 * Links in cards may only use safe schemes; anything else (e.g. javascript:) is shown as text.
 *
 * @param {string} url
 * @returns {boolean}
 */
export function isSafeUrl(url) {
  return /^(https?:|mailto:)/i.test(url);
}

/**
 * Wraps HTML sent by an app for a sandboxed frame: a light document with readable
 * defaults, so it does not inherit the viewer's dark colour scheme (white text on white).
 *
 * @param {string} html
 * @returns {string}
 */
export function framedHtml(html) {
  return '<!doctype html><html><head><meta charset="utf-8"><meta name="color-scheme" content="light">'
    + '<style>html{color-scheme:light;background:#fff;color:#111;font:14px/1.5 system-ui,sans-serif}body{margin:8px}pre{white-space:pre-wrap}</style>'
    + `</head><body>${html}</body></html>`;
}
