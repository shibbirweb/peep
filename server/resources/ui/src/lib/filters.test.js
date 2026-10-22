import { describe, expect, test } from 'bun:test';
import { emptyFilters, isFiltering, kindsOf, matches, toggle } from './filters.js';

const card = (types, extra = {}) => ({ payloads: types.map((type) => ({ type, content: { text: `${type} content` } })), ...extra });

describe('filters', () => {
  test('kinds come from payload types', () => {
    expect(kindsOf(card(['log', 'color']))).toEqual(['value']);
    expect(kindsOf(card(['query']))).toEqual(['query']);
    expect(kindsOf(card(['log_message']))).toEqual(['log']);
    expect(kindsOf(card(['log'], { label: 'dump()' }))).toEqual(['dump']);
  });

  test('empty filters match every card', () => {
    expect(matches(card(['log']), emptyFilters())).toBe(true);
    expect(isFiltering(emptyFilters())).toBe(false);
  });

  test('kinds, colors and search all have to match', () => {
    const query = card(['query'], { color: 'red' });

    expect(matches(query, { kinds: ['query', 'job'], colors: [], search: '' })).toBe(true);
    expect(matches(query, { kinds: ['job'], colors: [], search: '' })).toBe(false);
    expect(matches(query, { kinds: [], colors: ['red'], search: '' })).toBe(true);
    expect(matches(query, { kinds: [], colors: ['none'], search: '' })).toBe(false);
    expect(matches(card(['log']), { kinds: [], colors: ['none'], search: '' })).toBe(true);
    expect(matches(card(['text']), { kinds: [], colors: [], search: 'TEXT cont' })).toBe(true);
    expect(matches(card(['text']), { kinds: [], colors: [], search: 'missing' })).toBe(false);
  });

  test('toggle adds and removes', () => {
    expect(toggle(['a'], 'b')).toEqual(['a', 'b']);
    expect(toggle(['a', 'b'], 'a')).toEqual(['b']);
  });
});
