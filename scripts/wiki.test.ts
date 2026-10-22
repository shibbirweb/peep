import { describe, expect, test } from "bun:test";
import { findLinks, manifestProblems, pageName, pageProblems, resolvePath, rewriteLinks, segments, stripTitle, wordCount } from "./wiki";

describe("reading pages", () => {
  test("code blocks are kept apart from prose", () => {
    const parts = segments("Text\n```php\n[not](a-link.md)\n```\nMore");

    expect(parts.map((part) => part.code)).toEqual([false, true, false]);
  });

  test("links and images in prose only", () => {
    const links = findLinks("See [Laravel](Laravel.md) and `[code](x.md)`.\n![Shot](../images/a.png)\n```\n[no](b.md)\n```");

    expect(links).toEqual([
      { image: false, target: "Laravel.md" },
      { image: true, target: "../images/a.png" },
    ]);
  });

  test("paths and names", () => {
    expect(resolvePath("docs/wiki/usage/Laravel.md", "../developer/Releasing.md#a-beta")).toBe("docs/wiki/developer/Releasing.md");
    expect(resolvePath("docs/wiki/usage/Laravel.md", "../../protocol.md")).toBe("docs/protocol.md");
    expect(pageName("usage/Getting-Started.md")).toBe("Getting-Started");
    expect(wordCount("# Title\n\nTwo words\n```\nnot counted at all\n```")).toBe(3);
  });
});

describe("style", () => {
  test("a good page has no problems", () => {
    expect(pageProblems("A.md", "# A\n\nText.\n\n```mermaid\nflowchart LR\n  a --> b\n```\n")).toEqual([]);
  });

  test("title, em-dash and mermaid problems are found", () => {
    const problems = pageProblems("B.md", "No title \u2014 here\n\n```mermaid\nflowchat LR\n```\n");

    expect(problems).toHaveLength(3);
  });

  test("the manifest needs unique pages with summaries", () => {
    expect(manifestProblems({ usage: [{ page: "usage/A.md", summary: "x" }], developer: [{ page: "developer/A.md", summary: "" }] })).toEqual([
      "pages.json: developer/A.md has no summary",
      "two pages are named A; wiki page names must be unique",
    ]);
  });
});

test("links are rewritten for the wiki", () => {
  const pages = new Set(["docs/wiki/usage/Laravel.md", "docs/wiki/developer/Releasing.md"]);
  const markdown = "[Laravel](Laravel.md#blade), [release](../developer/Releasing.md), [spec](../../protocol.md), ![x](../images/a.png), [web](https://php.net)\n```\n[kept](Laravel.md)\n```";

  expect(rewriteLinks(markdown, "docs/wiki/usage/Mail.md", pages, "https://github.com/o/r/blob/main")).toBe(
    "[Laravel](Laravel#blade), [release](Releasing), [spec](https://github.com/o/r/blob/main/docs/protocol.md), ![x](images/a.png), [web](https://php.net)\n```\n[kept](Laravel.md)\n```",
  );
  expect(stripTitle("# Title\n\nBody")).toBe("Body");
});
