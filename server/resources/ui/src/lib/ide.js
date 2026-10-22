// Links that open a file at a line in the developer's editor.

export const IDES = [
  { id: 'phpstorm', name: 'PhpStorm' },
  { id: 'vscode', name: 'VS Code' },
  { id: 'cursor', name: 'Cursor' },
  { id: 'zed', name: 'Zed' },
  { id: 'sublime', name: 'Sublime Text' },
  { id: 'none', name: 'No editor links' },
];

const STORAGE_KEY = 'peep.ide';

/**
 * @param {string} ide
 * @param {string} file Absolute path, already mapped to the developer's machine by the client.
 * @param {number} line
 * @returns {string|null}
 */
export function ideUrl(ide, file, line) {
  if (!file || (!file.startsWith('/') && !/^[A-Za-z]:[\\/]/.test(file))) {
    return null;
  }

  const path = file.replaceAll('\\', '/');
  const encoded = encodeURI(path);

  switch (ide) {
    case 'phpstorm':
      return `phpstorm://open?file=${encodeURIComponent(path)}&line=${line}`;
    case 'vscode':
      return `vscode://file${encoded.startsWith('/') ? '' : '/'}${encoded}:${line}`;
    case 'cursor':
      return `cursor://file${encoded.startsWith('/') ? '' : '/'}${encoded}:${line}`;
    case 'zed':
      return `zed://file${encoded.startsWith('/') ? '' : '/'}${encoded}:${line}`;
    case 'sublime':
      return `subl://open?url=${encodeURIComponent(`file://${path}`)}&line=${line}`;
    default:
      return null;
  }
}

// The editor choice is a per-browser convenience until the settings page (VF-17) stores it in the viewer.
export function loadIde() {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);

    return IDES.some((ide) => ide.id === stored) ? stored : 'phpstorm';
  } catch {
    return 'phpstorm';
  }
}

export function saveIde(ide) {
  try {
    localStorage.setItem(STORAGE_KEY, ide);
  } catch {
    // Storage can be blocked; the choice then lasts for this page only.
  }
}

/**
 * Short form of a path for display: the last two segments.
 *
 * @param {string} file
 * @returns {string}
 */
export function shortPath(file) {
  const parts = file.replaceAll('\\', '/').split('/').filter(Boolean);

  return parts.slice(-2).join('/') || file;
}
