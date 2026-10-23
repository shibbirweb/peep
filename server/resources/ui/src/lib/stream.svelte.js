import { applyEvent } from './cards.js';
import { appCommand } from './desktop.js';
import { setPathMappings } from './ide.js';
import { MAIL_EVENTS, SMS_EVENTS, applyInboxEvent } from './inbox.js';
import { maybeNotify } from './notify.js';
import { applyWebhookEvent } from './webhooks.js';

const CARD_EVENTS = ['snapshot', 'card.created', 'card.updated', 'card.removed', 'screen.cleared'];

export const DEFAULT_SETTINGS = {
  display_mode: 'browser',
  ide: 'phpstorm',
  path_mappings: [],
  host: '127.0.0.1',
  port: 23517,
  theme: 'system',
  smtp: true,
  smtp_port: 23525,
  max_cards: 10000,
  history: true,
  notifications: false,
  update_channel: 'stable',
  check_updates: true,
  token_set: false,
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
  // Query debugging switches per project.
  switches: {},
  receivingPaused: false,
  // Archived screens, newest first, without their cards.
  screens: [],
  settings: { ...DEFAULT_SETTINGS },
  // Caught mail (summaries) and SMS, newest first.
  mails: [],
  sms: [],
  // Webhook rules and the latest deliveries, newest first.
  webhooks: { rules: [], deliveries: [] },
  // Each project's log folder for the Logs tab.
  logFolders: [],
});

export function connect() {
  const source = new EventSource('/_peep/stream');

  source.onopen = () => {
    viewer.connected = true;
  };

  source.onerror = () => {
    viewer.connected = false;
  };

  for (const name of CARD_EVENTS) {
    source.addEventListener(name, (event) => {
      const data = JSON.parse(event.data);

      if (name === 'snapshot') {
        applySnapshot(data);
      }

      viewer.cards = applyEvent(viewer.cards, name, data, viewer.settings.max_cards);
      trackLocks(name, data);

      if (name === 'card.created' && viewer.settings.notifications) {
        maybeNotify(data);
      }
    });
  }

  // peep()->showApp() / hideApp(): only the desktop app can act on them.
  on(source, 'app.command', (data) => {
    appCommand(data.command);
  });

  on(source, 'lock.released', (data) => {
    viewer.locks[data.name] = data.stop ? 'stopped' : 'continued';
  });

  on(source, 'switches.changed', (data) => {
    viewer.switches[data.project] = data.switches;
  });

  on(source, 'receiving.changed', (data) => {
    viewer.receivingPaused = data.paused;
  });

  on(source, 'screen.archived', (data) => {
    viewer.screens = [data, ...viewer.screens.filter((screen) => screen.id !== data.id)];
  });

  on(source, 'settings.changed', (data) => {
    applySettings(data);
  });

  on(source, 'logs.folders', (data) => {
    viewer.logFolders = data.folders ?? [];
  });

  for (const name of MAIL_EVENTS) {
    on(source, name, (data) => {
      viewer.mails = applyInboxEvent(viewer.mails, name, data);
    });
  }

  for (const name of ['webhooks.changed', 'webhook.delivered', 'webhook.cleared']) {
    on(source, name, (data) => {
      viewer.webhooks = applyWebhookEvent(viewer.webhooks, name, data);
    });
  }

  for (const name of SMS_EVENTS) {
    on(source, name, (data) => {
      viewer.sms = applyInboxEvent(viewer.sms, name, data);
    });
  }

  return () => source.close();
}

/**
 * @param {object} settings Public settings as the viewer sends them.
 */
export function applySettings(settings) {
  viewer.settings = { ...DEFAULT_SETTINGS, ...settings };
  setPathMappings(viewer.settings.path_mappings);
}

function applySnapshot(data) {
  viewer.switches = data.switches ?? {};
  viewer.receivingPaused = data.receiving_paused === true;
  viewer.screens = data.screens ?? [];
  viewer.mails = data.mails ?? [];
  viewer.sms = data.sms ?? [];
  viewer.webhooks = data.webhooks ?? { rules: [], deliveries: [] };
  viewer.logFolders = data.log_folders ?? [];

  if (data.settings) {
    applySettings(data.settings);
  }
}

function on(source, name, handle) {
  source.addEventListener(name, (event) => handle(JSON.parse(event.data)));
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
