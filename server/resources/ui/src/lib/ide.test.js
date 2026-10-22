import { describe, expect, test } from 'bun:test';
import { ideUrl, shortPath } from './ide.js';

describe('ideUrl', () => {
  test('builds a link for each editor', () => {
    expect(ideUrl('phpstorm', '/app/User Service.php', 12)).toBe('phpstorm://open?file=%2Fapp%2FUser%20Service.php&line=12');
    expect(ideUrl('vscode', '/app/a.php', 3)).toBe('vscode://file/app/a.php:3');
    expect(ideUrl('cursor', '/app/a.php', 3)).toBe('cursor://file/app/a.php:3');
    expect(ideUrl('zed', '/app/a.php', 3)).toBe('zed://file/app/a.php:3');
    expect(ideUrl('sublime', '/app/a.php', 3)).toBe('subl://open?url=file%3A%2F%2F%2Fapp%2Fa.php&line=3');
  });

  test('handles Windows paths', () => {
    expect(ideUrl('vscode', 'C:\\www\\app\\a.php', 7)).toBe('vscode://file/C:/www/app/a.php:7');
  });

  test('gives no link for relative paths, unknown files or no editor', () => {
    expect(ideUrl('vscode', 'Command line code', 1)).toBeNull();
    expect(ideUrl('vscode', '', 1)).toBeNull();
    expect(ideUrl('none', '/app/a.php', 1)).toBeNull();
  });
});

test('shortPath keeps the last two segments', () => {
  expect(shortPath('/var/www/app/Http/Controller.php')).toBe('Http/Controller.php');
  expect(shortPath('Command line code')).toBe('Command line code');
});
