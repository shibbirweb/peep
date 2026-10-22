import { applyEvent } from './cards.js';

const EVENTS = ['snapshot', 'card.created', 'card.updated', 'card.removed', 'screen.cleared'];

/**
 * Live state fed by the viewer's Server-Sent Events. EventSource reconnects on its own
 * and sends Last-Event-ID, so the viewer replays what was missed.
 */
export const viewer = $state({
  cards: [],
  connected: false,
});

export function connect() {
  const source = new EventSource('/_peep/stream');

  source.onopen = () => {
    viewer.connected = true;
  };

  source.onerror = () => {
    viewer.connected = false;
  };

  for (const name of EVENTS) {
    source.addEventListener(name, (event) => {
      viewer.cards = applyEvent(viewer.cards, name, JSON.parse(event.data));
    });
  }

  return () => source.close();
}

/**
 * @param {string|null} project Null clears every project.
 */
export async function clearCards(project) {
  await fetch('/_peep/ui/clear', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Peep-UI': '1',
    },
    body: JSON.stringify({ project }),
  });
}
