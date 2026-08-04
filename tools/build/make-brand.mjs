/**
 * Generates the MTL logo set in the visual grammar of janschilder.eu:
 * hard-edged flats, nested rotated pentagons, voronoi paving stones and
 * LED-dot grids, on deep navy. Every shape is computed, not eyeballed.
 */

import { writeFileSync, mkdirSync } from 'node:fs';

const C = {
  navy: '#0b1023',
  magenta: '#e5007d',
  red: '#e30613',
  orange: '#f39200',
  yellow: '#ffe500',
  green: '#3aaa35',
  teal: '#00a19a',
  cyan: '#0096d6',
  blue: '#1455a3',
  purple: '#7d2181',
  white: '#ffffff',
};

const PALETTE = [C.magenta, C.orange, C.yellow, C.green, C.teal, C.cyan, C.blue, C.purple, C.red];

const r2 = (n) => Math.round(n * 100) / 100;

/** A regular pentagon as an SVG points string. */
function pentagon(cx, cy, r, rotationDeg = 0) {
  const points = [];
  for (let i = 0; i < 5; i += 1) {
    const a = ((-90 + rotationDeg + i * 72) * Math.PI) / 180;
    points.push(`${r2(cx + r * Math.cos(a))},${r2(cy + r * Math.sin(a))}`);
  }
  return points.join(' ');
}

/** Jan Schilder's nested pentagons: each level rotated against the last. */
function nestedPentagons(cx, cy, r, colors, baseRotation = 0) {
  const steps = [0, 14, -10, 8];
  return colors.map((color, i) =>
    `<polygon points="${pentagon(cx, cy, r * [1, 0.72, 0.47, 0.26][i], baseRotation + steps[i])}" fill="${color}"/>`
  ).join('\n  ');
}

/** MTL letterforms as geometric paths (no font dependency). Height 30. */
function glyphs(x, y, fill) {
  const M = `M0 30V0h7.4l7.6 10.6L22.6 0H30v30h-7V12.6L15 23.4 7 12.6V30Z`;
  const T = `M0 0h22v7h-7.4v23h-7.2V7H0Z`;
  const L = `M0 0h7v23h13v7H0Z`;
  return `<g transform="translate(${x} ${y})" fill="${fill}">
    <path d="${M}"/>
    <path transform="translate(37 0)" d="${T}"/>
    <path transform="translate(66 0)" d="${L}"/>
  </g>`; // total width ≈ 86
}

/** Voronoi cells inside a circle, shrunk towards their seeds for grout. */
function pavingStones(cx, cy, radius, seedCount = 13, shrink = 0.86) {
  // Deterministic seeds (mulberry32) so the logo is the same logo every run.
  let s = 20260804;
  const rnd = () => {
    s = (s + 0x6d2b79f5) | 0;
    let t = Math.imul(s ^ (s >>> 15), 1 | s);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };

  const seeds = [];
  while (seeds.length < seedCount) {
    const a = rnd() * Math.PI * 2;
    const d = Math.sqrt(rnd()) * radius * 0.92;
    seeds.push([cx + Math.cos(a) * d, cy + Math.sin(a) * d]);
  }

  // Start each cell as a 48-gon of the circle, clip by the perpendicular
  // bisector against every other seed (Sutherland–Hodgman).
  const circle = [];
  for (let i = 0; i < 48; i += 1) {
    const a = (i / 48) * Math.PI * 2;
    circle.push([cx + Math.cos(a) * radius, cy + Math.sin(a) * radius]);
  }

  const clip = (poly, [ax, ay], [bx, by]) => {
    // Keep the side of the bisector closer to a than to b.
    const keep = ([px, py]) =>
      (px - ax) ** 2 + (py - ay) ** 2 <= (px - bx) ** 2 + (py - by) ** 2;
    const out = [];
    for (let i = 0; i < poly.length; i += 1) {
      const current = poly[i];
      const next = poly[(i + 1) % poly.length];
      const cIn = keep(current);
      const nIn = keep(next);
      if (cIn) out.push(current);
      if (cIn !== nIn) {
        // Intersection with the bisector, via the line where distances equal.
        const [x1, y1] = current;
        const [x2, y2] = next;
        const f = (px, py) =>
          (px - ax) ** 2 + (py - ay) ** 2 - ((px - bx) ** 2 + (py - by) ** 2);
        const f1 = f(x1, y1);
        const f2 = f(x2, y2);
        const t = f1 / (f1 - f2);
        out.push([x1 + (x2 - x1) * t, y1 + (y2 - y1) * t]);
      }
    }
    return out;
  };

  return seeds.map((seed, i) => {
    let cell = circle;
    for (let j = 0; j < seeds.length; j += 1) {
      if (j !== i) cell = clip(cell, seed, seeds[j]);
    }
    const shrunk = cell.map(([px, py]) => [
      seed[0] + (px - seed[0]) * shrink,
      seed[1] + (py - seed[1]) * shrink,
    ]);
    const points = shrunk.map(([px, py]) => `${r2(px)},${r2(py)}`).join(' ');
    return `<polygon points="${points}" fill="${PALETTE[i % PALETTE.length]}"/>`;
  }).join('\n  ');
}

/** Dots along a polyline, LED-wall style. */
function dottedPath(points, spacing, radius, colorOffset = 0) {
  const dots = [];
  let leftover = 0;
  let dotIndex = 0;
  for (let i = 0; i < points.length - 1; i += 1) {
    const [x1, y1] = points[i];
    const [x2, y2] = points[i + 1];
    const length = Math.hypot(x2 - x1, y2 - y1);
    for (let d = leftover; d <= length; d += spacing) {
      const t = d / length;
      const color = PALETTE[(dotIndex + colorOffset) % PALETTE.length];
      dots.push(`<circle cx="${r2(x1 + (x2 - x1) * t)}" cy="${r2(y1 + (y2 - y1) * t)}" r="${radius}" fill="${color}"/>`);
      dotIndex += 1;
    }
    leftover = (leftover - length) % spacing + spacing;
    if (leftover === spacing) leftover = 0;
  }
  return dots.join('\n  ');
}

const svg = (w, h, body) =>
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} ${h}" width="${w}" height="${h}">\n${body}\n</svg>\n`;

mkdirSync('assets/branding', { recursive: true });

// ---------------------------------------------------------------------------
// 1 · Pentagon-planeet: nested pentagons as a planet, dashed orbit, moon-dot.
// ---------------------------------------------------------------------------

const pentagonPlanet = (cx, cy, r) => `
  ${nestedPentagons(cx, cy, r, [C.blue, C.cyan, C.yellow, C.magenta], -6)}
  <ellipse cx="${cx}" cy="${cy}" rx="${r * 1.42}" ry="${r * 0.52}"
           fill="none" stroke="${C.white}" stroke-width="2.6"
           stroke-dasharray="0.5 7" stroke-linecap="round"
           transform="rotate(-20 ${cx} ${cy})"/>
  <circle cx="${r2(cx + r * 1.34)}" cy="${r2(cy - r * 0.44)}" r="4.6" fill="${C.orange}"/>`;

writeFileSync('assets/branding/mtl-pentagon.svg', svg(96, 96, `  <rect width="96" height="96" rx="20" fill="${C.navy}"/>
  <circle cx="22" cy="18" r="1.6" fill="${C.white}" opacity=".7"/>
  <circle cx="79" cy="70" r="1.3" fill="${C.white}" opacity=".55"/>
  <circle cx="70" cy="14" r="1.1" fill="${C.white}" opacity=".45"/>
${pentagonPlanet(48, 50, 26)}`));

writeFileSync('assets/branding/mtl-pentagon-wordmark.svg', svg(240, 96, `  <rect width="96" height="96" rx="20" fill="${C.navy}"/>
  <circle cx="22" cy="18" r="1.6" fill="${C.white}" opacity=".7"/>
  <circle cx="79" cy="70" r="1.3" fill="${C.white}" opacity=".55"/>
${pentagonPlanet(48, 50, 26)}
${glyphs(120, 33, C.navy)}`));

// ---------------------------------------------------------------------------
// 2 · Zetstenen-wereld: a planet of paving stones.
// ---------------------------------------------------------------------------

const stonesGlobe = `  <circle cx="48" cy="48" r="41" fill="#0f5f93"/>
  ${pavingStones(48, 48, 41)}
  <circle cx="48" cy="48" r="41" fill="none" stroke="${C.navy}" stroke-width="2"/>`;

writeFileSync('assets/branding/mtl-zetstenen.svg', svg(96, 96, stonesGlobe));

writeFileSync('assets/branding/mtl-zetstenen-wordmark.svg', svg(240, 96, `${stonesGlobe}
${glyphs(120, 33, C.navy)}`));

// ---------------------------------------------------------------------------
// 3 · M-route: the M as an LED-dot journey ending in a pentagon pin.
// ---------------------------------------------------------------------------

const mPath = [[14, 74], [14, 26], [40, 58], [66, 26], [66, 64]];

const mRoute = `  ${dottedPath(mPath, 11.5, 3.6)}
  <path d="M66 88C66 88 52 72 52 61a14 14 0 1 1 28 0c0 11-14 27-14 27Z" fill="${C.magenta}"/>
  <polygon points="${pentagon(66, 60, 7.6, 8)}" fill="${C.yellow}"/>`;

writeFileSync('assets/branding/mtl-route.svg', svg(96, 96, mRoute));

writeFileSync('assets/branding/mtl-route-wordmark.svg', svg(250, 96, `${mRoute}
${glyphs(122, 33, C.navy)}`));

// ---------------------------------------------------------------------------
// 4 · App-icoon: pentagon planet + dotted route to a pin, on the navy tile.
// ---------------------------------------------------------------------------

writeFileSync('assets/branding/mtl-app-icon.svg', svg(96, 96, `  <rect width="96" height="96" rx="21" fill="${C.navy}"/>
  <circle cx="20" cy="16" r="1.5" fill="${C.white}" opacity=".65"/>
  <circle cx="82" cy="24" r="1.2" fill="${C.white}" opacity=".5"/>
  <circle cx="30" cy="82" r="1.2" fill="${C.white}" opacity=".5"/>
  <circle cx="86" cy="66" r="1" fill="${C.white}" opacity=".4"/>
  ${nestedPentagons(36, 40, 24, [C.blue, C.cyan, C.yellow, C.magenta], -6)}
  <path d="M44 62C56 74 66 76 74 70" fill="none" stroke="${C.yellow}" stroke-width="3.4"
        stroke-dasharray="0.5 8" stroke-linecap="round"/>
  <circle cx="76" cy="69" r="6.2" fill="${C.red}"/>
  <circle cx="76" cy="69" r="2.6" fill="${C.white}"/>`));

console.log('written: assets/branding/*.svg');
