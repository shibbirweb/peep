// Plain-text forms of cards: what "Copy" puts on the clipboard and what search looks in.

import { summary } from './format.js';

const NODE_TYPES = new Set(['null', 'bool', 'int', 'float', 'string', 'binary', 'array', 'object', 'ref', 'resource', 'max_depth']);

const searchCache = new WeakMap();

/**
 * @param {object} node Value tree node.
 * @param {string} indent
 * @returns {string}
 */
export function nodeText(node, indent = '') {
  if (node.type === 'string') {
    return node.value;
  }

  const head = summary(node);
  const children = Array.isArray(node.children) ? node.children : [];

  if (children.length === 0) {
    return head;
  }

  const inner = indent + '  ';
  const lines = children.map((child) => {
    const value = child.node?.type === 'string' ? JSON.stringify(child.node.value) : nodeText(child.node ?? { type: 'null' }, inner);

    return `${inner}${child.key}: ${value}`;
  });

  return [head, ...lines].join('\n');
}

/**
 * @param {object} payload
 * @returns {string}
 */
export function payloadText(payload) {
  const content = payload.content ?? {};

  switch (payload.type) {
    case 'log':
      return (content.values ?? []).map((node) => nodeText(node)).join('\n');
    case 'text':
      return String(content.text ?? '');
    case 'html':
      return String(content.html ?? '');
    case 'json':
      return String(content.json ?? '');
    case 'xml':
      return String(content.xml ?? '');
    case 'query':
      return String(content.sql ?? '');
    case 'exception':
      return [`${content.class}: ${content.message}`, `${content.file}:${content.line}`, ...(content.frames ?? []).map(frameText)].join('\n');
    case 'trace':
    case 'caller':
      return (content.frames ?? []).map(frameText).join('\n');
    case 'color':
    case 'label':
    case 'size':
    case 'lock':
      return '';
    default:
      return collect(content).join('\n');
  }
}

/**
 * @param {object} card
 * @returns {string}
 */
export function cardText(card) {
  return [card.label, ...card.payloads.map(payloadText)].filter((part) => part !== null && part !== undefined && part !== '').join('\n');
}

/**
 * Lower-case text of a card for search, cached per card object (an update brings a new object).
 *
 * @param {object} card
 * @returns {string}
 */
export function searchText(card) {
  let text = searchCache.get(card);

  if (text === undefined) {
    text = [cardText(card), card.origin?.file ?? '', card.request?.label ?? ''].join('\n').toLowerCase();
    searchCache.set(card, text);
  }

  return text;
}

function frameText(frame) {
  const where = frame.file ? `${frame.file}:${frame.line ?? ''}` : '[internal]';
  const call = frame.function ? `${frame.class ? `${frame.class}::` : ''}${frame.function}()` : '';

  return `  at ${call} ${where}`.trimEnd();
}

function collect(value) {
  if (typeof value === 'string') {
    return [value];
  }

  if (typeof value === 'number' || typeof value === 'boolean') {
    return [String(value)];
  }

  if (value === null || typeof value !== 'object') {
    return [];
  }

  if (NODE_TYPES.has(value.type) && !Array.isArray(value)) {
    return [nodeText(value)];
  }

  return Object.values(value).flatMap(collect);
}
