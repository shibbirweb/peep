import { describe, expect, test } from 'bun:test';
import { MAX_CARDS, applyEvent, projectsOf } from './cards.js';

const card = (id, project = 'shop', extra = {}) => ({ id, project, payloads: [], ...extra });

describe('applyEvent', () => {
  test('snapshot shows newest first', () => {
    const cards = applyEvent([], 'snapshot', { cards: [card('old'), card('new')] });

    expect(cards.map((c) => c.id)).toEqual(['new', 'old']);
  });

  test('created cards go on top, updates replace in place', () => {
    let cards = applyEvent([], 'card.created', card('a'));
    cards = applyEvent(cards, 'card.created', card('b'));
    cards = applyEvent(cards, 'card.updated', card('a', 'shop', { color: 'red' }));

    expect(cards.map((c) => c.id)).toEqual(['b', 'a']);
    expect(cards[1].color).toBe('red');
  });

  test('an update for an unknown card adds it', () => {
    expect(applyEvent([], 'card.updated', card('a'))).toHaveLength(1);
  });

  test('cards with the same id in different projects are different cards', () => {
    let cards = applyEvent([], 'card.created', card('a', 'shop'));
    cards = applyEvent(cards, 'card.created', card('a', 'blog'));
    cards = applyEvent(cards, 'card.removed', { id: 'a', project: 'shop' });

    expect(cards).toEqual([card('a', 'blog')]);
  });

  test('clearing one project or all', () => {
    const cards = [card('a', 'shop'), card('b', 'blog')];

    expect(applyEvent(cards, 'screen.cleared', { project: 'shop' })).toEqual([card('b', 'blog')]);
    expect(applyEvent(cards, 'screen.cleared', { project: null })).toEqual([]);
  });

  test('keeps at most MAX_CARDS', () => {
    let cards = Array.from({ length: MAX_CARDS }, (_, index) => card(`c${index}`));
    cards = applyEvent(cards, 'card.created', card('newest'));

    expect(cards).toHaveLength(MAX_CARDS);
    expect(cards[0].id).toBe('newest');
  });

  test('ignores unknown events', () => {
    const cards = [card('a')];

    expect(applyEvent(cards, 'future.event', {})).toBe(cards);
  });
});

test('projectsOf lists each project once, sorted', () => {
  expect(projectsOf([card('a', 'shop'), card('b', 'blog'), card('c', 'shop')])).toEqual(['blog', 'shop']);
});

test('the limit is per project, so a busy project keeps a quiet one on the page', () => {
  let cards = applyEvent([], 'card.created', card('quiet', 'blog'), 3);

  for (let index = 0; index < 5; index++) {
    cards = applyEvent(cards, 'card.created', card(`busy-${index}`, 'shop'), 3);
  }

  expect(cards.map((c) => c.id)).toEqual(['busy-4', 'busy-3', 'busy-2', 'quiet']);
  expect(applyEvent([], 'snapshot', { cards: [card('a', 'blog'), card('b'), card('c'), card('d')] }, 2).map((c) => c.id)).toEqual(['d', 'c', 'a']);
});
