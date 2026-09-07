# StackEdit, hosted by MTL

`assets/stackedit/` is a build of [StackEdit](https://github.com/benweet/stackedit)
5.15.4 (Apache-2.0) with a handful of source changes, kept in `mtl.patch`.
It is the travel-report editor: the form opens it full-screen in a
same-origin iframe (`assets/js/editor/index.js`) and talks to it over the
`postMessage` protocol of stackedit.js (`assets/js/editor/stackedit.js`).

## What the patch changes

- **Full menus in embedded mode.** Upstream hides the side bar entirely when
  embedded ("light" mode). Here it stays: table of contents, settings,
  templates, import/export, print, history, help. Entries that need a cloud
  account (sign-in, workspaces, synchronize, publish, accounts, badges) and
  the ones that wipe the local workspace (backups, reset) are hidden, since
  none of them can work — or should — inside another site.
- **The image button comes first in the toolbar, and the menu has an "Insert
  photo" entry.** On a phone the toolbar is wider than the screen and its last
  buttons drop off the edge — which is where the image button used to be.
- **The image button asks the host for a picture.** Instead of a URL dialog it
  posts `{type: 'pickImage'}` to the host page, which opens MTL's photo
  library and answers with `{type: 'insertText', payload: {text}}`; StackEdit
  inserts that at the caret. That is the one thing a host cannot do from the
  outside.
- **Built for `/assets/stackedit/`**, without source maps and without the
  offline service worker (MTL has its own).
- **No Google.** Upstream probes connectivity by loading a script from
  apis.google.com and fetches a server configuration from `/conf`. Both now
  go to `conf.json` next to `index.html` — a static file that says there are
  no cloud providers — so opening the editor tells nobody anything.
- **eslint-loader dropped** from the build: linting is not part of producing
  the bundle, and the pinned eslint crashes on template literals.
- Every direct dependency is pinned to the version of upstream's lockfile,
  since that lockfile itself no longer installs (see `abcjs` below).
- The `abcjs` music-notation extension is dropped: its `MIDI.js` git
  dependency no longer resolves.

Everything else — the editing, the preview, the markdown extensions (KaTeX,
mermaid, emoji, footnotes, …) — is upstream, untouched.

## Rebuilding

StackEdit's toolchain is from 2018 (webpack 2, node-sass 4, Babel 6) and
needs **Node 14**. The build machine needs `git`, and network access to
GitHub and npm.

```sh
git clone --depth 1 --branch v5.15.4 https://github.com/benweet/stackedit
cd stackedit
git apply /path/to/MTL/tools/stackedit/mtl.patch

# Node 14 — e.g. from https://nodejs.org/dist/v14.21.3/
npm install --legacy-peer-deps --ignore-scripts   # sqlite3 (server-only, via websql) does not build
(cd node_modules/node-sass && node scripts/install.js)   # fetches the prebuilt binding
npx gulp build-prism
NODE_OPTIONS=--max-old-space-size=4096 npm run build
```

Then copy the result over, keeping only what the editor needs:

```sh
rm -rf /path/to/MTL/assets/stackedit
mkdir -p /path/to/MTL/assets/stackedit/static
cp -r dist/static/{css,js,fonts} /path/to/MTL/assets/stackedit/static/
cp dist/index.html /path/to/MTL/assets/stackedit/
echo '{"allowSponsorship":false}' > /path/to/MTL/assets/stackedit/conf.json
```

and strip the PWA icon/manifest `<link>` and `<meta>` tags from `index.html`
(the page only ever lives inside the editor overlay). Finally run
`php bin/console.php optimize`: the asset version changes, so every browser
picks up the new build.
