/**
 * Markdown -> HTML -> markdown, through the real renderer and the real
 * serialiser.
 *
 * This is the most valuable test in the repository, because the editor's save
 * path is where content gets quietly destroyed. Everything a reader writes goes
 * markdown -> server render -> contenteditable -> serialise -> markdown on every
 * save, and anything the serialiser does not understand is simply gone. That is
 * how tables were flattened to their cell text, how footnote definitions were
 * dropped and left dangling references, how `***vet***` lost its bold, and how a
 * heading permalink was written back into the source as `[#](#kop)` — one more on
 * every save.
 *
 * Each case has to come back byte for byte.
 */

import { launch, signIn, BASE, results } from './helpers.mjs';

const CASES = [
  // Emphasis, including a run that pairs twice.
  '*cursief*',
  '**vet**',
  '***vet en cursief***',
  '**vet met *cursief* erin**',
  '~~doorgehaald~~',
  '~~*beide*~~',
  '**vet**, *cursief* en `code` in een regel',

  // Code.
  '`code`',
  'Tekst met `code met een * erin`',
  '```js\nconst x = 1;\n```',
  '```\nzonder taal\n```',
  '```\nconst a = `x`;\n```',

  // Headings, all six levels: the offset used to make level six unrepresentable.
  '# Kop een',
  '## Kop twee',
  '### Kop drie',
  '#### Kop vier',
  '##### Kop vijf',
  '###### Kop zes',
  '## Kop met **vet** erin',

  // Blocks.
  '> Een citaat',
  '> Eerste alinea.\n>\n> Tweede alinea.',
  '> Citaat met **vet** en een [link](https://example.com)',
  '---',
  'Een alinea.\n\nEen tweede.\n\nEen derde.',

  // Lists.
  '- een\n- twee',
  '1. een\n2. twee',
  '1000. hoog nummer',
  '- een\n  - genest\n- twee',
  '1. een\n   1. genest\n2. twee',
  '- [ ] open\n- [x] klaar',
  '- [ ] taak met **vet**\n- [x] afgerond',

  // Links and media.
  '[label](https://example.com)',
  '[label](https://example.com "met titel")',
  '![alt](https://example.com/a.png)',
  '![alt](https://example.com/a.png "met titel")',
  'https://example.com/bare',

  // Tables, which the serialiser used to lose entirely.
  '| a | b |\n| --- | --- |\n| 1 | 2 |',
  '| links | midden | rechts |\n| :--- | :---: | ---: |\n| 1 | 2 | 3 |',
  '| a |\n| --- |\n| **vet** |',

  // Footnotes, which used to vanish and leave the reference behind.
  'Een voetnoot[^1]\n\n[^1]: De noot.',
  'Een noot[^bron]\n\n[^bron]: Met een naam in plaats van een nummer.',
  'Twee noten[^a] en[^b]\n\n[^a]: Eerste.\n\n[^b]: Tweede.',

  // Whitespace and escaping.
  'Regel een  \nRegel twee',
  'Regel een  \nRegel twee  \nRegel drie',
  'Tekst met een < en een & erin',
  'Een \\* letterlijke asterisk',
  'Een \\_ letterlijke underscore',
  'Þingvellir en Jökulsárlón',
];

const browser = await launch();
const context = await browser.newContext({ storageState: await signIn(browser) });
const page = await context.newPage();

page.on('pageerror', (error) => console.log('PAGEERROR', error.message));

// Any page with an editor on it will do; the round trip is all in the browser
// and the server's /preview endpoint.
await page.goto(`${BASE}/admin/steps/1`, { waitUntil: 'load' });
await page.waitForTimeout(800);

if ((await page.locator('[data-editor]').count()) === 0) {
  throw new Error(`No editor on ${page.url()} — seed a trip first with seed:demo.`);
}

const { check, summarise } = results();

for (const markdown of CASES) {
  const out = await page.evaluate(async (source) => {
    const editor = document.querySelector('[data-editor]').mtlEditor;

    editor.field.value = source;
    await editor.refreshSurfaceFromMarkdown();

    const html = editor.surface.innerHTML;

    editor.syncToField();

    return { html, back: editor.field.value };
  }, markdown);

  const same = out.back.trim() === markdown.trim();

  check(JSON.stringify(markdown), same);

  if (!same) {
    console.log(`      html: ${out.html}`);
    console.log(`      back: ${JSON.stringify(out.back)}`);
  }
}

const failed = summarise();

await browser.close();
process.exit(failed > 0 ? 1 : 0);
