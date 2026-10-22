// Pure functions that apply viewer events to the card list (newest first).

export const MAX_CARDS = 2000;

/**
 * @param {Array<object>} cards
 * @param {string} name Event name sent by the viewer.
 * @param {object} data Event data.
 * @returns {Array<object>}
 */
export function applyEvent(cards, name, data) {
  switch (name) {
    case 'snapshot':
      return [...data.cards].reverse().slice(0, MAX_CARDS);

    case 'card.created':
      return [data, ...cards.filter((card) => !sameCard(card, data))].slice(0, MAX_CARDS);

    case 'card.updated': {
      const index = cards.findIndex((card) => sameCard(card, data));

      if (index === -1) {
        return [data, ...cards].slice(0, MAX_CARDS);
      }

      const next = [...cards];
      next[index] = data;

      return next;
    }

    case 'card.removed':
      return cards.filter((card) => !sameCard(card, data));

    case 'screen.cleared':
      return data.project === null ? [] : cards.filter((card) => card.project !== data.project);

    default:
      return cards;
  }
}

/**
 * @param {Array<object>} cards
 * @returns {Array<string>}
 */
export function projectsOf(cards) {
  return [...new Set(cards.map((card) => card.project))].sort();
}

function sameCard(a, b) {
  return a.id === b.id && a.project === b.project;
}
