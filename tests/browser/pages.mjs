/**
 * Every page, at four widths, checked for the two faults that are invisible in
 * markup and obvious to a reader: something scrolling sideways, and a script
 * throwing.
 *
 * The widths are a phone, a tablet, a laptop and a large desktop. The headset
 * browser sits inside that range and is covered by the widest one.
 */

import { launch, signIn, BASE, watchForErrors, results } from './helpers.mjs';

const PHONE = { width: 390, height: 844 };
const TABLET = { width: 834, height: 1112 };
const LAPTOP = { width: 1440, height: 900 };
const DESKTOP = { width: 1920, height: 1080 };

const browser = await launch();
const signedIn = await signIn(browser);
const { check, summarise } = results();

async function visit(name, viewport, path, { auth = false, settle = 1500 } = {}) {
  const context = await browser.newContext({
    viewport,
    deviceScaleFactor: 1,
    ...(auth ? { storageState: signedIn } : {}),
  });
  const page = await context.newPage();
  const errors = watchForErrors(page, { ignore: ['favicon'] });

  const url = `${BASE}${path}`;

  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(settle);

  const label = `${name.padEnd(22)} ${viewport.width}x${viewport.height}`;

  // A page that quietly bounced to the sign-in form has no overflow and no
  // console errors either, so without this every admin check could be passing
  // against the login page.
  if (page.url() !== url) {
    check(label, false, `redirected to ${page.url()}`);
    await context.close();

    return;
  }

  const overflow = await page.evaluate(() => {
    const root = document.documentElement;
    let widest = null;
    let max = 0;

    for (const element of document.querySelectorAll('body *')) {
      const rect = element.getBoundingClientRect();

      if (rect.width > 0 && rect.right > max) {
        max = rect.right;
        widest = element.tagName + (element.className ? `.${String(element.className).split(' ')[0]}` : '');
      }
    }

    return { scrollWidth: root.scrollWidth, clientWidth: root.clientWidth, widest, max: Math.round(max) };
  });

  const scrolls = overflow.scrollWidth > overflow.clientWidth + 1;

  check(
    label,
    !scrolls && errors.length === 0,
    [
      scrolls ? `overflows by ${overflow.scrollWidth - overflow.clientWidth}px (${overflow.widest})` : '',
      errors.length > 0 ? `${errors.length} error(s): ${errors.slice(0, 2).join('; ')}` : '',
    ]
      .filter(Boolean)
      .join('  '),
  );

  await context.close();
}

// The globe is the one page with a long asynchronous start, so it gets longer to
// settle and is checked at every width.
for (const viewport of [PHONE, TABLET, LAPTOP, DESKTOP]) {
  await visit('globe', viewport, '/', { settle: 3000 });
}

await visit('trips', PHONE, '/trips');
await visit('trips', LAPTOP, '/trips');
await visit('albums', TABLET, '/albums');
await visit('search', PHONE, '/search?q=ijsland');
await visit('sign in', PHONE, '/login');
await visit('offline', PHONE, '/offline');

await visit('admin dashboard', LAPTOP, '/admin', { auth: true });
await visit('admin dashboard', PHONE, '/admin', { auth: true });
await visit('admin trips', LAPTOP, '/admin/trips', { auth: true });
await visit('admin media', LAPTOP, '/admin/media', { auth: true });
await visit('admin albums', TABLET, '/admin/albums', { auth: true });
await visit('admin users', PHONE, '/admin/users', { auth: true });
await visit('admin settings', LAPTOP, '/admin/settings', { auth: true });
await visit('step editor', LAPTOP, '/admin/steps/1', { auth: true });
await visit('step editor', PHONE, '/admin/steps/1', { auth: true });

const failed = summarise();

await browser.close();
process.exit(failed > 0 ? 1 : 0);
