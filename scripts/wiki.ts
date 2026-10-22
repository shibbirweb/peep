// Pure helpers behind scripts/build-wiki.ts, kept separate so they can be tested (bun test scripts).

export const WIKI_DIR = "docs/wiki";

/** Pages longer than this are split: a wiki page should read in a few minutes. */
export const MAX_WORDS = 1200;

export interface ManifestPage {
  page: string;
  summary: string;
}

export interface Manifest {
  usage: ManifestPage[];
  developer: ManifestPage[];
}

const MERMAID_TYPES = [
  "flowchart",
  "graph",
  "sequenceDiagram",
  "stateDiagram",
  "stateDiagram-v2",
  "classDiagram",
  "erDiagram",
  "gitGraph",
  "journey",
  "pie",
  "mindmap",
  "timeline",
];

/** The wiki page name of a source file: `usage/Getting-Started.md` becomes `Getting-Started`. */
export function pageName(sourcePath: string): string {
  return (sourcePath.split("/").pop() ?? sourcePath).replace(/\.md$/, "");
}

export interface Segment {
  code: boolean;
  text: string;
}

/** Splits markdown into prose and fenced code, so links and words inside code are left alone. */
export function segments(markdown: string): Segment[] {
  const result: Segment[] = [];
  let current: string[] = [];
  let inCode = false;

  for (const line of markdown.split("\n")) {
    const fence = /^\s*(```|~~~)/.test(line);
    if (fence && !inCode) {
      if (current.length > 0) {
        result.push({ code: false, text: current.join("\n") });
      }
      current = [line];
      inCode = true;
    } else if (fence && inCode) {
      current.push(line);
      result.push({ code: true, text: current.join("\n") });
      current = [];
      inCode = false;
    } else {
      current.push(line);
    }
  }

  if (current.length > 0) {
    result.push({ code: inCode, text: current.join("\n") });
  }

  return result;
}

export interface Link {
  image: boolean;
  target: string;
}

/** Markdown links and images in the prose (not in code blocks or inline code). */
export function findLinks(markdown: string): Link[] {
  const links: Link[] = [];

  for (const segment of segments(markdown)) {
    if (segment.code) {
      continue;
    }

    const prose = segment.text.replace(/`[^`\n]*`/g, "");
    for (const match of prose.matchAll(/(!?)\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g)) {
      links.push({ image: match[1] === "!", target: match[2] });
    }
  }

  return links;
}

export function isExternal(target: string): boolean {
  return /^(https?:|mailto:|#)/.test(target);
}

/** Resolves `target` (without its #anchor) against the folder of `from`; both relative to the repository root. */
export function resolvePath(from: string, target: string): string {
  const parts = from.split("/").slice(0, -1);

  for (const part of target.split("#")[0].split("/")) {
    if (part === "..") {
      parts.pop();
    } else if (part !== "." && part !== "") {
      parts.push(part);
    }
  }

  return parts.join("/");
}

export function wordCount(markdown: string): number {
  return segments(markdown)
    .filter((segment) => !segment.code)
    .map((segment) => segment.text.split(/\s+/).filter((word) => /[A-Za-z0-9]/.test(word)).length)
    .reduce((sum, count) => sum + count, 0);
}

/** Style problems of one page: title line, em-dashes, length and Mermaid blocks. */
export function pageProblems(page: string, markdown: string): string[] {
  const problems: string[] = [];

  if (!/^# \S/.test(markdown)) {
    problems.push(`${page}: the first line must be a "# Title"`);
  }

  if (markdown.includes("\u2014")) {
    problems.push(`${page}: uses an em-dash; use a colon, a comma or two sentences`);
  }

  const words = wordCount(markdown);
  if (words > MAX_WORDS) {
    problems.push(`${page}: ${words} words, over ${MAX_WORDS}; split it`);
  }

  for (const segment of segments(markdown)) {
    const lines = segment.text.split("\n");
    if (segment.code && /^\s*```mermaid\s*$/.test(lines[0])) {
      const first = lines.slice(1).find((line) => line.trim() !== "" && !line.trim().startsWith("%%"))?.trim() ?? "";
      const type = first.split(/\s+/)[0];
      if (!MERMAID_TYPES.includes(type)) {
        problems.push(`${page}: a mermaid block starts with "${type}", not a diagram type`);
      }
      if (!/^\s*```\s*$/.test(lines[lines.length - 1])) {
        problems.push(`${page}: a mermaid block is not closed`);
      }
    }
  }

  return problems;
}

/** Drops the `# Title` line: the wiki prints the page name as its title. */
export function stripTitle(markdown: string): string {
  return markdown.replace(/^# [^\n]*\n+/, "");
}

/**
 * Rewrites links for the wiki: pages become wiki page names, images go to images/, and
 * other repository files become GitHub links. Code is left alone.
 */
export function rewriteLinks(markdown: string, from: string, pages: Set<string>, blob: string): string {
  return segments(markdown)
    .map((segment) => {
      if (segment.code) {
        return segment.text;
      }

      return segment.text.replace(/(!?)\[([^\]]*)\]\(([^)\s]+)\)/g, (whole, bang: string, text: string, target: string) => {
        if (isExternal(target)) {
          return whole;
        }

        const anchor = target.includes("#") ? `#${target.split("#")[1]}` : "";
        const path = resolvePath(from, target);

        if (bang === "!") {
          return `![${text}](images/${path.split("/").pop()})`;
        }

        if (pages.has(path)) {
          return `[${text}](${pageName(path)}${anchor})`;
        }

        return `[${text}](${blob}/${path}${anchor})`;
      });
    })
    .join("\n");
}

/** Problems with the manifest itself: duplicate pages, missing summaries. */
export function manifestProblems(manifest: Manifest): string[] {
  const problems: string[] = [];
  const seen = new Set<string>();

  for (const entry of [...manifest.usage, ...manifest.developer]) {
    if (seen.has(entry.page)) {
      problems.push(`pages.json lists ${entry.page} twice`);
    }
    seen.add(entry.page);

    if (!entry.summary || entry.summary.trim() === "") {
      problems.push(`pages.json: ${entry.page} has no summary`);
    }
  }

  const names = [...seen].map(pageName);
  const duplicates = names.filter((name, index) => names.indexOf(name) !== index);
  for (const name of new Set(duplicates)) {
    problems.push(`two pages are named ${name}; wiki page names must be unique`);
  }

  return problems;
}
