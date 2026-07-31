/**
 * Shared setup for the browser tests.
 *
 * These exist because a whole class of defect in this application is invisible
 * to a PHP test: an editor command bound twice, a table that disappears when the
 * surface is serialised back to markdown, a sticky toolbar covering the first
 * line of the report. All of those were shipped and all of them were found here.
 *
 * They need a running server, which is deliberate — they check the application
 * as a browser sees it, not as its unit tests describe it.
 *
 *   php bin/console.php serve &
 *   node tests/browser/editor.mjs
 */

import { chromium } from 'playwright';

export const BASE = process.env.MTL_URL ?? 'http://127.0.0.1:8000';

export const EMAIL = process.env.MTL_EMAIL ?? 'demo@mtl.local';
export const PASSWORD = process.env.MTL_PASSWORD ?? 'reis-door-de-wereld-2026';

/**
 * Chromium, with software rendering so the globe draws on a machine with no GPU.
 * The path is the one the container image ships; elsewhere Playwright finds its
 * own, so it is only used when it exists.
 */
export async function launch() {
  const bundled = process.env.MTL_CHROMIUM;

  return chromium.launch({
    ...(bundled ? { executablePath: bundled } : {}),
    args: ['--use-gl=swiftshader', '--enable-unsafe-swiftshader', '--no-sandbox'],
  });
}

/**
 * Signs in once and returns the cookies, to be handed to every context that
 * needs them.
 *
 * Signing in per context trips the rate limiter — correctly, since a dozen POSTs
 * to /login in a minute is what it is there to stop. The failure mode is quiet
 * and misleading: every admin page then renders the sign-in form, which has no
 * console errors and no layout faults, so the checks all pass while testing
 * nothing.
 */
export async function signIn(browser) {
  const context = await browser.newContext();
  const page = await context.newPage();

  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#email', EMAIL);
  await page.fill('#password', PASSWORD);

  // The click and the navigation it causes are awaited together: on its own,
  // waitForLoadState can resolve against the page still on screen, and the next
  // goto then cancels the sign-in POST before the session cookie is set.
  //
  // The form is located by its password field. A bare `button[type=submit]`
  // matches the search form in the header, which comes first in the document.
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page
      .locator('form')
      .filter({ has: page.locator('#password') })
      .locator('button[type=submit]')
      .click(),
  ]);

  if (!page.url().includes('/admin')) {
    throw new Error(
      `Signing in did not reach the management area (landed on ${page.url()}). ` +
        'If it shows the sign-in form again, the rate limiter is probably still ' +
        'holding earlier attempts.',
    );
  }

  const state = await context.storageState();

  await context.close();

  return state;
}

/** Collects console errors, page errors and failed requests for one page. */
export function watchForErrors(page, { ignore = [] } = {}) {
  const errors = [];

  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text());
  });

  page.on('pageerror', (error) => errors.push(`PAGEERROR: ${error.message}`));

  page.on('requestfailed', (request) => {
    const url = request.url();

    if (ignore.some((pattern) => url.includes(pattern))) return;

    errors.push(`FAILED ${url} ${request.failure()?.errorText ?? ''}`);
  });

  return errors;
}

/** A tiny result collector, so each script ends with one honest summary line. */
export function results() {
  const rows = [];

  return {
    check(name, ok, detail = '') {
      rows.push({ name, ok });
      console.log(`${ok ? 'OK  ' : 'FAIL'} ${name}${detail ? `  ${detail}` : ''}`);
    },
    summarise() {
      const failed = rows.filter((row) => !row.ok).length;

      console.log(`\n${rows.length - failed}/${rows.length} checks passed`);

      return failed;
    },
  };
}
