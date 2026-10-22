// Filtering cards by kind, color and search text.

import { searchText } from './text.js';

export const KINDS = [
  { id: 'value', name: 'Values' },
  { id: 'dump', name: 'Dumps' },
  { id: 'log', name: 'Logs' },
  { id: 'query', name: 'Queries' },
  { id: 'exception', name: 'Exceptions' },
  { id: 'job', name: 'Jobs' },
  { id: 'event', name: 'Events' },
  { id: 'http', name: 'HTTP' },
  { id: 'cache', name: 'Cache' },
  { id: 'view', name: 'Views' },
  { id: 'mail', name: 'Mail' },
];

export const COLORS = ['red', 'orange', 'yellow', 'green', 'blue', 'purple', 'gray'];

const KIND_OF_TYPE = {
  query: 'query',
  query_count: 'query',
  exception: 'exception',
  job: 'job',
  event: 'event',
  http: 'http',
  request: 'http',
  cache: 'cache',
  view: 'view',
  mail: 'mail',
  log_message: 'log',
};

const NOT_CONTENT = new Set(['color', 'label', 'size', 'lock', 'new_screen']);

// Per card object; an update brings a new object.
const kindCache = new WeakMap();

export function emptyFilters() {
  return { kinds: [], colors: [], search: '' };
}

/**
 * The kinds of content a card holds, e.g. ["query"] or ["value"].
 *
 * @param {object} card
 * @returns {Array<string>}
 */
export function kindsOf(card) {
  let kinds = kindCache.get(card);

  if (kinds === undefined) {
    kinds = card.label === 'dump()' ? ['dump'] : [...new Set(card.payloads.filter((payload) => !NOT_CONTENT.has(payload.type)).map((payload) => KIND_OF_TYPE[payload.type] ?? 'value'))];
    kindCache.set(card, kinds);
  }

  return kinds;
}

/**
 * @param {object} card
 * @param {{kinds: Array<string>, colors: Array<string>, search: string}} filters Empty lists match everything.
 * @returns {boolean}
 */
export function matches(card, filters) {
  if (filters.kinds.length > 0 && !kindsOf(card).some((kind) => filters.kinds.includes(kind))) {
    return false;
  }

  if (filters.colors.length > 0 && !filters.colors.includes(card.color ?? 'none')) {
    return false;
  }

  const search = filters.search.trim().toLowerCase();

  return search === '' || searchText(card).includes(search);
}

/**
 * @param {{kinds: Array<string>, colors: Array<string>, search: string}} filters
 * @returns {boolean}
 */
export function isFiltering(filters) {
  return filters.kinds.length > 0 || filters.colors.length > 0 || filters.search.trim() !== '';
}

/**
 * @param {Array<string>} list
 * @param {string} value
 * @returns {Array<string>}
 */
export function toggle(list, value) {
  return list.includes(value) ? list.filter((item) => item !== value) : [...list, value];
}
