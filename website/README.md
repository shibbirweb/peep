# Website

The Peep website, built with [VitePress](https://vitepress.dev) and published to GitHub Pages at https://shibbirweb.github.io/peep/ by the Website workflow (`.github/workflows/website.yml`).

The home page is `index.md` and its screenshot tabs are `.vitepress/theme/components/HomeShowcase.vue`. The docs are not written here: `scripts/sync-docs.ts` copies them from `docs/wiki` (with the images) on every `dev` and `build`, so change a page in `docs/wiki` and both the wiki and the website follow. The sidebar groups are in `.vitepress/config.mts`; a page listed in `docs/wiki/pages.json` but in no group shows up under "More".

## Versions

The published site has the docs of every version, with a version menu in the nav:

| Path | Docs from |
|------|-----------|
| `/peep/` | the latest stable minor version, from its newest tag (`v0.1.0` for 0.1) |
| `/peep/v0.1/` and so on | each older minor version, once a newer one is out |
| `/peep/next/` | `develop`, the beta line |

`scripts/versions.ts` works them out from the git tags and branches, and `scripts/build-versions.ts` builds each one from its own `docs/wiki` with the current website code, then puts them together in `.vitepress/dist`. Older and next pages show a banner that links to the latest. Nothing to do by hand: a stable release adds its version the next time the workflow runs.

## Running it

Needs [Bun](https://bun.sh):

```bash
cd website
bun install
bun run dev              # the working tree, http://localhost:5173/peep/ with hot reload
bun run build            # the working tree only, into .vitepress/dist
bun run build:versions   # every version, as published
bun run preview          # serve the build
```

`dev` and `build` show the working tree as the version "Local". `build:versions` needs the tags and `develop` (a full clone).
