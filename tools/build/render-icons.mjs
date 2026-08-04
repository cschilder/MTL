/**
 * Renders the SVG brand into every PNG the platforms demand: the PWA icons
 * under assets/icons and the Android launcher mipmaps. Run after
 * make-brand.mjs, from tools/: `node build/render-icons.mjs`.
 *
 * Needs the dev environment (Playwright + Chromium); the results are
 * committed, so the server never runs this.
 */

import { mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve, dirname } from 'node:path';

const ROOT = resolve(import.meta.dirname, '../..');

// Playwright lives with the browser tests; borrow it from there.
const { chromium } = createRequire(`${ROOT}/tests/browser/`)('playwright-core');

const JOBS = [
  // PWA / favicon PNGs.
  ['assets/branding/mtl-zetstenen-tile.svg', 'assets/icons/icon-180.png', 180],
  ['assets/branding/mtl-zetstenen-tile.svg', 'assets/icons/icon-192.png', 192],
  ['assets/branding/mtl-zetstenen-tile.svg', 'assets/icons/icon-512.png', 512],
  ['assets/branding/mtl-zetstenen-maskable.svg', 'assets/icons/icon-maskable-512.png', 512],

  // Android launcher, one entry per density bucket.
  ...[
    ['mdpi', 48, 108], ['hdpi', 72, 162], ['xhdpi', 96, 216],
    ['xxhdpi', 144, 324], ['xxxhdpi', 192, 432],
  ].flatMap(([bucket, launcher, foreground]) => [
    ['assets/branding/mtl-zetstenen-tile.svg', `android/app/src/main/res/mipmap-${bucket}/ic_launcher.png`, launcher],
    ['assets/branding/mtl-zetstenen-round.svg', `android/app/src/main/res/mipmap-${bucket}/ic_launcher_round.png`, launcher],
    ['assets/branding/mtl-zetstenen-foreground.svg', `android/app/src/main/res/mipmap-${bucket}/ic_launcher_foreground.png`, foreground],
  ]),
];

const executablePath = process.env.MTL_CHROMIUM || undefined;
const browser = await chromium.launch({ executablePath });
const page = await (await browser.newContext()).newPage();

for (const [source, target, size] of JOBS) {
  await page.setViewportSize({ width: size, height: size });
  await page.goto(
    'data:text/html,<style>*{margin:0}</style>'
    + `<img src="file://${ROOT}/${source}" width="${size}" height="${size}">`,
  );
  await page.waitForTimeout(120);

  mkdirSync(dirname(`${ROOT}/${target}`), { recursive: true });
  await page.screenshot({ path: `${ROOT}/${target}`, omitBackground: true });
  console.log(`${target}  ${size}×${size}`);
}

await browser.close();
