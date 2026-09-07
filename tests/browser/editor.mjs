/**
 * The editor as a person actually uses it: the form shows the report, one
 * button opens StackEdit full-screen, what is typed there lands in the form,
 * a photo goes in at the caret, closing brings the preview up to date, and
 * saving keeps all of it.
 *
 * StackEdit here is the site's own build (assets/stackedit), so the real
 * thing runs inside the iframe and the real postMessage protocol is what is
 * tested — not a stand-in. What once went wrong, and is pinned here: a
 * heading added in StackEdit vanished on Save, and a photo landed above a
 * heading instead of at the caret.
 */

import { launch, signIn, BASE, watchForErrors, results } from './helpers.mjs';

const browser = await launch();
const context = await browser.newContext({
  storageState: await signIn(browser),
  viewport: { width: 1440, height: 900 },
});
const page = await context.newPage();

// StackEdit's stylesheet lists every font in woff2 and woff; once the woff2
// arrives the browser abandons the woff request, which Playwright reports as
// a failed request. Nothing failed.
const errors = watchForErrors(page, { ignore: ['/assets/stackedit/static/fonts/'] });

page.on('dialog', async (dialog) => {
  console.log(`   dialog: ${dialog.type()} ${JSON.stringify(dialog.message())}`);
  await dialog.accept();
});

const { check, summarise } = results();

const field = page.locator('[data-editor-field]');
const markdown = () => field.inputValue();

await page.goto(`${BASE}/admin/steps/1`, { waitUntil: 'load' });
await page.waitForTimeout(800);

check(
  'the editor mounts',
  await page.evaluate(() => Boolean(document.querySelector('[data-editor]')?.mtlEditor)),
);

check(
  'the form shows the rendered report and one button, not a textarea',
  (await page.locator('[data-editor-preview]').isVisible())
    && (await page.locator('[data-editor-open]').isVisible())
    && !(await field.isVisible()),
);

// A known starting point.
await page.evaluate(() => {
  const editor = document.querySelector('[data-editor]').mtlEditor;
  editor.field.value = 'Eerste alinea.\n\nTweede alinea.';
});
const before = await markdown();

// ---------------------------------------------------------------------------
// Open StackEdit
// ---------------------------------------------------------------------------

await page.locator('[data-editor-open]').click();

const frame = page.frameLocator('.stackedit-iframe');
const stackeditEditor = frame.locator('.editor__inner');

await stackeditEditor.waitFor({ state: 'visible', timeout: 20000 });
await page.waitForTimeout(1200);

check(
  'StackEdit opens full-screen with the report loaded',
  (await page.locator('.stackedit-container').count()) === 1
    && (await stackeditEditor.textContent()).includes('Tweede alinea.'),
);

check(
  'StackEdit reported in (its own ✓ replaces the fallback close button)',
  (await page.locator('.stackedit-close-button').count()) === 0
    && (await frame.locator('button[aria-label^="Close StackEdit"]').count()) === 1,
);

// The side bar: the full menu, minus what needs a cloud account.
await frame.locator('button[aria-label^="Toggle side bar"]').click();
await page.waitForTimeout(500);
const menuText = await frame.locator('.side-bar').textContent();

check(
  'the full menu is there (settings, table of contents, import/export)',
  /Settings/.test(menuText) && /Table of contents/.test(menuText) && /Import\/export/.test(menuText),
);

check(
  'cloud-account entries are hidden',
  !/Synchronize/.test(menuText) && !/Publish/.test(menuText) && !/Workspaces/.test(menuText),
);

await frame.locator('button[aria-label^="Toggle side bar"]').click();
await page.waitForTimeout(300);

// ---------------------------------------------------------------------------
// Type in StackEdit; it must arrive in the form field as-is
// ---------------------------------------------------------------------------

await stackeditEditor.click();
await page.keyboard.press('Control+End');
await page.keyboard.press('Enter');
await page.keyboard.press('Enter');
await page.keyboard.type('## Kop uit StackEdit');
await page.keyboard.press('Enter');
await page.keyboard.type('Regel eronder.');
await page.waitForTimeout(1000);

check(
  'what is typed in StackEdit lands in the form field',
  (await markdown()).includes('## Kop uit StackEdit\nRegel eronder.'),
  JSON.stringify((await markdown()).slice(-60)),
);

// ---------------------------------------------------------------------------
// A photo goes in at the caret, inside StackEdit
// ---------------------------------------------------------------------------

// Put the caret in the middle of the report: end of the heading line.
await page.keyboard.press('Control+End');
await page.keyboard.press('ArrowUp');
await page.keyboard.press('End');

await frame.locator('button[aria-label^="Image"]').click();
await page.waitForTimeout(500);

check(
  "StackEdit's image button opens the site's photo library",
  await page.locator('[data-media-picker]').evaluate((dialog) => dialog.open),
);

// The library is empty on a fresh test database; answer the way the picker
// would, through the same insertMedia() path. Any real image will do for the
// URL — the site's own icon exists on every installation.
await page.keyboard.press('Escape');
await page.waitForTimeout(200);
await page.evaluate(() => {
  document.querySelector('[data-editor]').mtlEditor.insertMedia({
    uuid: 'icon',
    url: '/assets/icons/icon-192.png',
    alt: 'Uitzicht',
  });
});
await page.waitForTimeout(1000);

const withPhoto = await markdown();

check(
  'the photo lands at the caret, after the heading and before the line below',
  /## Kop uit StackEdit\n!\[Uitzicht\]\(\/assets\/icons\/icon-192\.png\)\nRegel eronder\./.test(withPhoto),
  JSON.stringify(withPhoto.slice(-120)),
);

// ---------------------------------------------------------------------------
// Close with StackEdit's ✓; the preview follows; Save keeps everything
// ---------------------------------------------------------------------------

await frame.locator('button[aria-label^="Close StackEdit"]').click();
await page.waitForTimeout(800);

check(
  'the ✓ closes the sheet',
  (await page.locator('.stackedit-container').count()) === 0,
);

check(
  'the preview shows the new heading',
  (await page.locator('[data-editor-preview]').textContent()).includes('Kop uit StackEdit'),
);

check(
  'the field still holds the original paragraphs',
  (await markdown()).startsWith(before),
);

await Promise.all([
  page.waitForNavigation({ waitUntil: 'load' }).catch(() => {}),
  page.locator('button[type=submit].p-button--positive').click(),
]);
await page.waitForTimeout(600);

await page.goto(`${BASE}/admin/steps/1`, { waitUntil: 'load' });
await page.waitForTimeout(800);

const after = await markdown();

check(
  'the report survives a save and a reload, byte for byte',
  after === withPhoto,
  `${withPhoto.length} chars -> ${after.length}`,
);

// Tapping the rendered report opens the editor too.
await page.locator('[data-editor-preview]').click();
await page.waitForTimeout(500);

check(
  'tapping the report opens StackEdit',
  (await page.locator('.stackedit-container').count()) === 1,
);

await page.evaluate(() => document.querySelector('[data-editor]').mtlEditor.stackedit.close());

console.log(`\nconsole errors: ${errors.length > 0 ? errors.slice(0, 5).join('; ') : 'none'}`);

const failed = summarise() + (errors.length > 0 ? 1 : 0);

await browser.close();
process.exit(failed > 0 ? 1 : 0);
