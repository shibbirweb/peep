// Pure functions that apply viewer events to the card list (newest first).

export const MAX_CARDS = 10000;

/**
 * @param {Array<object>} cards
 * @param {string} name Event name sent by the viewer.
 * @param {object} data Event data.
 * @param {number} max Cards kept in the page (the viewer's max_cards setting).
 * @returns {Array<object>}
 */
export function applyEvent(cards, name, data, max = MAX_CARDS) {
  switch (name) {
    case 'snapshot':
      return limitPerProject([...data.cards].reverse(), max);

    case 'card.created':
      return trimProject([data, ...cards.filter((card) => !sameCard(card, data))], data.project, max);

    case 'card.updated': {
      const index = cards.findIndex((card) => sameCard(card, data));

      if (index === -1) {
        return trimProject([data, ...cards], data.project, max);
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
 * Keeps the newest cards of each project, as the viewer does: a busy project never
 * pushes a quiet one out of the page.
 *
 * @param {Array<object>} cards Newest first.
 * @param {number} max
 * @returns {Array<object>}
 */
export function limitPerProject(cards, max) {
  const seen = {};

  return cards.filter((card) => {
    seen[card.project] = (seen[card.project] ?? 0) + 1;

    return seen[card.project] <= max;
  });
}

function trimProject(cards, project, max) {
  let count = 0;
  let last = -1;

  for (let index = 0; index < cards.length; index++) {
    if (cards[index].project === project) {
      count++;
      last = index;
    }
  }

  return count > max ? cards.filter((_, index) => index !== last) : cards;
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
