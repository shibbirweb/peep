// Links that open a file at a line in the developer's editor.

export const IDES = [
  { id: 'phpstorm', name: 'PhpStorm' },
  { id: 'vscode', name: 'VS Code' },
  { id: 'cursor', name: 'Cursor' },
  { id: 'zed', name: 'Zed' },
  { id: 'sublime', name: 'Sublime Text' },
  { id: 'none', name: 'No editor links' },
];

// Path mappings from the settings page: prefixes replaced before building a link.
let pathMappings = [];

/**
 * @param {Array<{from: string, to: string}>} mappings
 */
export function setPathMappings(mappings) {
  pathMappings = Array.isArray(mappings) ? mappings : [];
}

/**
 * @param {string} file
 * @returns {string}
 */
export function mapPath(file) {
  const normalized = file.replaceAll('\\', '/');

  for (const { from, to } of pathMappings) {
    const prefix = from.replaceAll('\\', '/').replace(/\/+$/, '');

    if (normalized === prefix || normalized.startsWith(`${prefix}/`)) {
      return to.replaceAll('\\', '/').replace(/\/+$/, '') + normalized.slice(prefix.length);
    }
  }

  return normalized;
}

/**
 * @param {string} ide
 * @param {string} file Absolute path, mapped by the client and then by the viewer's path mappings.
 * @param {number} line
 * @returns {string|null}
 */
export function ideUrl(ide, file, line) {
  const path = file ? mapPath(file) : '';

  if (!path || (!path.startsWith('/') && !/^[A-Za-z]:\//.test(path))) {
    return null;
  }

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
