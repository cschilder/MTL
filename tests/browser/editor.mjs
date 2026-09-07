/**
 * The editor as a person actually uses it: typing, formatting, the markdown
 * shorthands, switching to the source view, saving, and coming back.
 *
 * The round-trip test covers what the serialiser understands. This covers what
 * happens between a keystroke and the markdown field — which is where a command
 * bound twice turned italic on and straight back off, and where typing "## " on
 * a fresh line reformatted the paragraph *above* it and then swallowed the words
 * that followed.
 */

import { launch, signIn, BASE, watchForErrors, results } from './helpers.mjs';

const browser = await launch();
const context = await browser.newContext({
  storageState: await signIn(browser),
  viewport: { width: 1440, height: 900 },
});
const page = await context.newPage();

// The StackEdit scenario opens the real stackedit.io iframe; on a machine
// without a route to it the load fails, which is not what this suite tests.
const errors = watchForErrors(page, { ignore: ['stackedit.io'] });

// A confirm() that is auto-dismissed turns a click into a no-op, which is
// indistinguishable from a handler that does nothing.
page.on('dialog', async (dialog) => {
  console.log(`   dialog: ${dialog.type()} ${JSON.stringify(dialog.message())}`);
  await dialog.accept();
});

const { check, summarise } = results();

await page.goto(`${BASE}/admin/steps/1`, { waitUntil: 'load' });
await page.waitForTimeout(1000);

const surface = page.locator('[data-editor-surface]');
const field = page.locator('[data-editor-field]');
const markdown = () => field.inputValue();

check(
  'the editor mounts',
  await page.evaluate(() => Boolean(document.querySelector('[data-editor]')?.mtlEditor)),
);

check(
  'the toolbar is populated',
  (await page.locator('[data-command]').count()) > 8,
  `${await page.locator('[data-command]').count()} commands`,
);

/** Resets the surface so one case cannot contaminate the next. */
async function reset(html = '<p>Start.</p>') {
  await page.evaluate((initial) => {
    const editor = document.querySelector('[data-editor]');

    editor.querySelector('[data-editor-surface]').innerHTML = initial;
    editor.mtlEditor.syncToField();
  }, html);

  await surface.click();
  await page.keyboard.press('Control+End');
}

const html = () => page.evaluate(() => document.querySelector('[data-editor-surface]').innerHTML);

// --- formatting --------------------------------------------------------------

for (const [command, pattern, label] of [
  ['bold', /\*\*Start\.\*\*/, '**'],
  ['italic', /(?<!\*)\*Start\.\*(?!\*)/, '*'],
  ['strikeThrough', /~~Start\.~~/, '~~'],
]) {
  await reset();
  for (let i = 0; i < 6; i++) await page.keyboard.press('Shift+ArrowLeft');
  await page.click(`[data-command="${command}"]`);
  await page.waitForTimeout(250);

  // Applied exactly once. A toolbar bound twice cancels a toggle out, which is
  // why this asserts the marker rather than "something changed".
  check(`toolbar ${command} emits ${label} once`, pattern.test(await markdown()), await markdown());
}

await reset();
for (let i = 0; i < 6; i++) await page.keyboard.press('Shift+ArrowLeft');
await page.keyboard.press('Control+b');
await page.waitForTimeout(250);
check('Ctrl+B emits **', /\*\*Start\.\*\*/.test(await markdown()), await markdown());

// --- markdown shorthands -----------------------------------------------------

await reset();
await page.keyboard.press('Enter');
await page.keyboard.type('## Een kop');
await page.waitForTimeout(250);
check(
  'the heading shorthand formats the new line, not the one above it',
  /^Start\.\n\n## Een kop$/.test((await markdown()).trim()),
  JSON.stringify(await markdown()),
);

await reset();
await page.keyboard.press('Enter');
await page.keyboard.type('- eerste');
await page.keyboard.press('Enter');
await page.keyboard.type('tweede');
await page.waitForTimeout(250);
check(
  'the list shorthand starts a list and Enter continues it',
  /- eerste\n- tweede/.test(await markdown()),
  JSON.stringify(await markdown()),
);

await reset();
await page.keyboard.press('Enter');
await page.keyboard.type('> een citaat');
await page.waitForTimeout(250);
check('the quote shorthand', /^> een citaat$/m.test(await markdown()), JSON.stringify(await markdown()));

check(
  'no list ends up nested inside a paragraph',
  !/<p>\s*<[uo]l/.test(await html()),
  await html(),
);

// --- modes -------------------------------------------------------------------

await page.locator('[data-editor-mode-button="source"]').click();
await page.waitForTimeout(300);
check(
  'source mode swaps the textarea in',
  await page.evaluate(() => {
    const editor = document.querySelector('[data-editor]');

    return (
      editor.mtlEditor?.mode === 'source' &&
      editor.querySelector('[data-editor-source]')?.hidden === false &&
      editor.querySelector('[data-editor-surface]')?.hidden === true
    );
  }),
);

await page.locator('[data-editor-mode-button="rich"]').click();
await page.waitForTimeout(500);
check(
  'and back to the rich surface',
  (await page.evaluate(() => document.querySelector('[data-editor]').mtlEditor?.mode)) === 'rich',
);

// --- layout ------------------------------------------------------------------

check(
  'the sticky toolbar does not cover the first line',
  await page.evaluate(() => {
    const toolbar = document.querySelector('.mtl-editor__toolbar').getBoundingClientRect();
    const first = document.querySelector('[data-editor-surface]').firstElementChild;

    if (!first) return true;

    return first.getBoundingClientRect().top >= toolbar.bottom - 1;
  }),
);

// --- saving ------------------------------------------------------------------

await reset('<p>Voor het opslaan.</p>');
await page.keyboard.press('Control+End');
await page.keyboard.press('Enter');
await page.keyboard.type('## Bewaard');
await page.waitForTimeout(300);

const before = await markdown();

// The save button, not "the first submit on the page": this page also carries a
// delete form and a detach form per attachment.
await Promise.all([
  page.waitForNavigation({ waitUntil: 'load' }).catch(() => {}),
  page.locator('button[type=submit].p-button--positive').click(),
]);
await page.waitForTimeout(600);

await page.goto(`${BASE}/admin/steps/1`, { waitUntil: 'load' });
await page.waitForTimeout(800);

const after = await page.locator('[data-editor-field]').inputValue();

check(
  'the report survives a save and a reload',
  after.trim() === before.trim(),
  `${before.length} chars -> ${after.length}`,
);

check(
  'no permalink anchors leak into the source',
  !/\]\(#/.test(after),
  JSON.stringify((after.match(/\[[^\]]*\]\(#[^)]*\)/g) ?? []).slice(0, 3)),
);

// ---------------------------------------------------------------------------
// StackEdit: what comes back over postMessage must survive everything that
// follows — a mode switch, the preview, a save. The iframe cannot load here
// (no route to stackedit.io), so the protocol is driven from this side: the
// same 'fileChange' payload StackEdit posts, handed to the same listener.
// A heading added in StackEdit vanished on Save once, because the hidden
// rich surface was serialised over it.
// ---------------------------------------------------------------------------

await page.goto(`${BASE}/admin/steps/1`, { waitUntil: 'load' });
await page.waitForTimeout(800);

await page.locator('[data-editor-mode-button="stackedit"]').click();
await page.waitForTimeout(300);

check(
  'StackEdit mode opens the overlay and keeps the textarea underneath',
  (await page.locator('.stackedit-container').count()) === 1
    && !(await page.locator('[data-editor-source]').evaluate((el) => el.hidden)),
);

const fromStackedit = `${after.trim()}\n\n## Kop uit StackEdit\n\nEen regel eronder.`;

await page.evaluate((text) => {
  const editor = document.querySelector('[data-editor]').mtlEditor;

  editor.stackedit.$trigger('fileChange', { content: { text } });
}, fromStackedit);
await page.waitForTimeout(100);

// StackEdit's own ✓ posts 'close'; the library's close() is what handles it.
await page.evaluate(() => document.querySelector('[data-editor]').mtlEditor.stackedit.close());
await page.waitForTimeout(100);

check(
  'a StackEdit edit lands in the field',
  (await markdown()).includes('## Kop uit StackEdit'),
);

await page.locator('[data-editor-mode-button="preview"]').click();
await page.waitForTimeout(600);

check(
  'the edit survives switching to the preview',
  (await markdown()).includes('## Kop uit StackEdit'),
);

await page.locator('[data-editor-mode-button="rich"]').click();
await page.waitForTimeout(800);

check(
  'the edit survives switching to the rich surface',
  (await markdown()).includes('## Kop uit StackEdit')
    && (await surface.innerHTML()).includes('Kop uit StackEdit'),
);

await page.locator('[data-editor-mode-button="stackedit"]').click();
await page.waitForTimeout(300);
await page.evaluate(() => document.querySelector('[data-editor]').mtlEditor.stackedit.close());

await Promise.all([
  page.waitForNavigation({ waitUntil: 'load' }).catch(() => {}),
  page.locator('button[type=submit].p-button--positive').click(),
]);
await page.waitForTimeout(600);

await page.goto(`${BASE}/admin/steps/1`, { waitUntil: 'load' });
await page.waitForTimeout(800);

check(
  'the edit survives a save from StackEdit mode',
  (await page.locator('[data-editor-field]').inputValue()).includes('## Kop uit StackEdit'),
);

// Leave the account on the plain editor again, so the other suites are not
// greeted by an overlay.
await page.locator('[data-editor-mode-button="rich"]').click();
await page.waitForTimeout(400);

console.log(`\nconsole errors: ${errors.length > 0 ? errors.slice(0, 5).join('; ') : 'none'}`);

const failed = summarise() + (errors.length > 0 ? 1 : 0);

await browser.close();
process.exit(failed > 0 ? 1 : 0);
