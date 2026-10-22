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
      return node.lazy ? `${node.class} #${node.id} (lazy, not initialized)` : `${node.class} #${node.id}`;
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
