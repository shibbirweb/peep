// The Logs tab: levels, turning log context into value trees, and keeping the loaded entries.

export const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

// Entries kept in the browser; older ones are dropped and can be loaded again.
export const MAX_ENTRIES = 5000;

/**
 * A JSON value as a value tree node, so ValueTree can show it as a collapsible array.
 *
 * @param {*} value
 * @returns {object}
 */
export function jsonNode(value) {
  if (value === null || value === undefined) {
    return { type: 'null' };
  }

  if (typeof value === 'boolean') {
    return { type: 'bool', value };
  }

  if (typeof value === 'number') {
    return { type: Number.isInteger(value) ? 'int' : 'float', value };
  }

  if (typeof value === 'string') {
    return { type: 'string', value, length: value.length };
  }

  const entries = Array.isArray(value) ? value.map((item, index) => [index, item]) : Object.entries(value);

  return {
    type: 'array',
    length: entries.length,
    children: entries.map(([key, item]) => ({ key, node: jsonNode(item) })),
  };
}

/**
 * The context without the exception string, which the entry shows on its own.
 *
 * @param {object} entry
 * @returns {*}
 */
export function contextOf(entry) {
  const context = entry.context;

  if (entry.exception && context && typeof context === 'object' && !Array.isArray(context)) {
    const { exception, ...rest } = context;

    return Object.keys(rest).length > 0 ? rest : null;
  }

  if (Array.isArray(context) && context.length === 0) {
    return null;
  }

  return context ?? null;
}

/**
 * New entries from watching, added after the ones there are. The oldest are dropped past the limit.
 *
 * @param {Array<object>} entries Oldest first.
 * @param {Array<object>} added Oldest first.
 * @param {number} limit
 * @returns {{ entries: Array<object>, dropped: boolean }}
 */
export function appendEntries(entries, added, limit = MAX_ENTRIES) {
  const known = new Set(entries.map((entry) => entry.offset));
  const merged = [...entries, ...added.filter((entry) => !known.has(entry.offset))];

  return merged.length > limit ? { entries: merged.slice(-limit), dropped: true } : { entries: merged, dropped: false };
}

/**
 * Older entries, put before the ones there are.
 *
 * @param {Array<object>} entries Oldest first.
 * @param {Array<object>} older Oldest first.
 * @returns {Array<object>}
 */
export function prependEntries(entries, older) {
  const known = new Set(entries.map((entry) => entry.offset));

  return [...older.filter((entry) => !known.has(entry.offset)), ...entries];
}

/**
 * @param {Array<object>} entries Oldest first.
 * @param {{ levels: Set<string>, search: string, order: 'newest'|'oldest' }} view
 * @returns {Array<object>}
 */
export function visibleEntries(entries, { levels, search, order }) {
  const needle = search.trim().toLowerCase();
  const shown = entries.filter((entry) => levels.has(entry.level) && (needle === '' || entry.raw.toLowerCase().includes(needle)));

  return order === 'newest' ? shown.reverse() : shown;
}

/**
 * @param {Array<object>} entries
 * @returns {Record<string, number>}
 */
export function levelCounts(entries) {
  const counts = Object.fromEntries(LEVELS.map((level) => [level, 0]));

  for (const entry of entries) {
    counts[entry.level] = (counts[entry.level] ?? 0) + 1;
  }

  return counts;
}

/**
 * The first line of a message, for the collapsed row.
 *
 * @param {string} message
 * @returns {string}
 */
export function firstLine(message) {
  const end = message.indexOf('\n');

  return end === -1 ? message : `${message.slice(0, end)} …`;
}
