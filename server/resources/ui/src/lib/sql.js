// Puts query bindings into the SQL for display (never for running).

/**
 * @param {*} value
 * @returns {string}
 */
export function quoteBinding(value) {
  if (value === null || value === undefined) {
    return 'NULL';
  }

  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(Number(value));
  }

  return `'${String(value).replaceAll("'", "''")}'`;
}

/**
 * Replaces each ? outside quotes with the next binding.
 *
 * @param {string} sql
 * @param {Array<*>} bindings
 * @returns {string}
 */
export function inlineBindings(sql, bindings) {
  if (!Array.isArray(bindings) || bindings.length === 0) {
    return sql;
  }

  let result = '';
  let index = 0;
  let quote = null;

  for (const char of sql) {
    if (quote !== null) {
      if (char === quote) {
        quote = null;
      }
      result += char;
    } else if (char === "'" || char === '"' || char === '`') {
      quote = char;
      result += char;
    } else if (char === '?' && index < bindings.length) {
      result += quoteBinding(bindings[index]);
      index += 1;
    } else {
      result += char;
    }
  }

  return result;
}
