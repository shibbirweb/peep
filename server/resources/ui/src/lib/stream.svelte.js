import { applyEvent } from './cards.js';

const EVENTS = ['snapshot', 'card.created', 'card.updated', 'card.removed', 'screen.cleared'];

const UI_HEADERS = {
  'Content-Type': 'application/json',
  'X-Peep-UI': '1',
};

/**
 * Live state fed by the viewer's Server-Sent Events. EventSource reconnects on its own
 * and sends Last-Event-ID, so the viewer replays what was missed.
 */
export const viewer = $state({
  cards: [],
  connected: false,
  // Pause locks by name: 'active', 'continued' or 'stopped'.
  locks: {},
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
      const data = JSON.parse(event.data);
      viewer.cards = applyEvent(viewer.cards, name, data);
      trackLocks(name, data);
    });
  }

  source.addEventListener('lock.released', (event) => {
    const data = JSON.parse(event.data);
    viewer.locks[data.name] = data.stop ? 'stopped' : 'continued';
  });

  return () => source.close();
}

/**
 * @param {string|null} project Null clears every project.
 */
export async function clearCards(project) {
  await fetch('/_peep/ui/clear', {
    method: 'POST',
    headers: UI_HEADERS,
    body: JSON.stringify({ project }),
  });
}

/**
 * @param {string} name
 * @param {'continue'|'stop'} action
 */
export async function releaseLock(name, action) {
  await fetch(`/_peep/ui/locks/${encodeURIComponent(name)}/${action}`, {
    method: 'POST',
    headers: UI_HEADERS,
  });
}

function trackLocks(name, data) {
  if (name === 'snapshot') {
    viewer.locks = Object.fromEntries((data.active_locks ?? []).map((lock) => [lock, 'active']));

    return;
  }

  if (name !== 'card.created' && name !== 'card.updated') {
    return;
  }

  for (const payload of data.payloads ?? []) {
    const lock = payload.type === 'lock' ? payload.content?.name : null;

    if (lock && viewer.locks[lock] === undefined) {
      viewer.locks[lock] = 'active';
    }
  }
}
