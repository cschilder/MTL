/**
 * Builds the globe's static geometry from Natural Earth data.
 *
 *   cd tools && npm run globe
 *
 * Produces, for each resolution:
 *
 *   assets/data/globe-lines-{res}.bin   coastlines and country borders as
 *                                       quantised line strips
 *   assets/data/globe-land-{res}.png    an equirectangular land mask
 *
 * Two representations because they answer different questions. The mask fills
 * the continents in one texture lookup and costs nothing per frame. The line
 * strips draw coastlines and borders as actual geometry, so they stay a
 * hairline wide however far the viewer zooms in — a texture would be a blurry
 * smear at that point.
 *
 * The outputs are committed. Strato runs no build step, so whatever is in the
 * repository is what gets served.
 */

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { deflateSync } from 'node:zlib';

import * as topojson from 'topojson-client';

const here = dirname(fileURLToPath(import.meta.url));
const outputDirectory = resolve(here, '../../assets/data');

/**
 * Natural Earth ships at three generalisation levels. 110m is right for a
 * phone; 50m has roughly five times the vertices and is worth it on a desktop
 * or in a headset, where the globe fills the view.
 */
const RESOLUTIONS = [
  { name: '110m', source: 'countries-110m.json', maskWidth: 2048 },
  { name: '50m', source: 'countries-50m.json', maskWidth: 4096 },
];

const MAGIC = 0x4d544c47; // "MTLG"
const FORMAT_VERSION = 1;

function main() {
  mkdirSync(outputDirectory, { recursive: true });

  for (const resolution of RESOLUTIONS) {
    let topology;

    try {
      topology = JSON.parse(
        readFileSync(resolve(here, '../node_modules/world-atlas', resolution.source), 'utf8'),
      );
    } catch (error) {
      console.warn(`  skipping ${resolution.name}: ${resolution.source} is not installed`);
      continue;
    }

    const countries = topology.objects.countries;

    // Arcs shared by two countries are borders; arcs belonging to one are
    // coastline. topojson works this out from the shared-arc topology, which
    // is the whole reason the format exists.
    const coast = topojson.mesh(topology, countries, (a, b) => a === b);
    const borders = topojson.mesh(topology, countries, (a, b) => a !== b);
    const land = topojson.merge(topology, countries.geometries);

    const lineFile = resolve(outputDirectory, `globe-lines-${resolution.name}.bin`);
    const maskFile = resolve(outputDirectory, `globe-land-${resolution.name}.png`);

    writeFileSync(lineFile, encodeLines(coast, borders));
    writeFileSync(maskFile, encodeMask(land, resolution.maskWidth, resolution.maskWidth / 2));

    report(lineFile, coast, borders);
    report(maskFile);
  }
}

// ---------------------------------------------------------------------------
// Line strips
// ---------------------------------------------------------------------------

/**
 * Layout, all little-endian:
 *
 *   uint32  magic "MTLG"
 *   uint16  version
 *   uint16  section count (2: coast, borders)
 *   per section:
 *     uint32  strip count
 *     uint32  total point count
 *   per section, per strip:
 *     uint32  point count
 *     int16[] longitude, latitude interleaved
 *
 * Coordinates are quantised to signed 16-bit: longitude over ±180° and
 * latitude over ±90°. That is a resolution of about 550 metres at the equator,
 * far finer than the source data's own generalisation, and it halves the file
 * against Float32.
 */
function encodeLines(coast, borders) {
  const sections = [collectStrips(coast), collectStrips(borders)];

  let bytes = 4 + 2 + 2;
  for (const strips of sections) {
    bytes += 8;
    for (const strip of strips) {
      bytes += 4 + strip.length * 2 * 2;
    }
  }

  const buffer = Buffer.alloc(bytes);
  let offset = 0;

  buffer.writeUInt32LE(MAGIC, offset); offset += 4;
  buffer.writeUInt16LE(FORMAT_VERSION, offset); offset += 2;
  buffer.writeUInt16LE(sections.length, offset); offset += 2;

  for (const strips of sections) {
    buffer.writeUInt32LE(strips.length, offset); offset += 4;
    buffer.writeUInt32LE(
      strips.reduce((total, strip) => total + strip.length, 0),
      offset,
    );
    offset += 4;
  }

  for (const strips of sections) {
    for (const strip of strips) {
      buffer.writeUInt32LE(strip.length, offset); offset += 4;

      for (const [longitude, latitude] of strip) {
        buffer.writeInt16LE(quantise(longitude, 180), offset); offset += 2;
        buffer.writeInt16LE(quantise(latitude, 90), offset); offset += 2;
      }
    }
  }

  return buffer;
}

function quantise(value, range) {
  const clamped = Math.max(-range, Math.min(range, value));
  return Math.round((clamped / range) * 32767);
}

/**
 * Flattens a MultiLineString into strips, splitting any segment that wraps the
 * antimeridian.
 *
 * Without the split, a line from 179°E to 179°W is drawn straight across the
 * whole globe instead of over the short way — the classic seam artefact.
 */
function collectStrips(geometry) {
  const input =
    geometry.type === 'MultiLineString'
      ? geometry.coordinates
      : geometry.type === 'LineString'
        ? [geometry.coordinates]
        : [];

  const strips = [];

  for (const line of input) {
    let current = [];

    for (let i = 0; i < line.length; i += 1) {
      const point = line[i];

      if (i > 0 && Math.abs(point[0] - line[i - 1][0]) > 180) {
        if (current.length > 1) strips.push(current);
        current = [];
      }

      current.push(point);
    }

    if (current.length > 1) strips.push(current);
  }

  return strips;
}

// ---------------------------------------------------------------------------
// Land mask
// ---------------------------------------------------------------------------

/**
 * Rasterises the merged land polygons into an equirectangular 8-bit mask.
 *
 * Scanline fill with the even-odd rule, sampled four times per pixel row so
 * coastlines are anti-aliased rather than stair-stepped. The shader smooths it
 * further, but starting from a hard 1-bit mask leaves visible jaggies on the
 * horizon where the sphere is nearly edge-on.
 */
function encodeMask(land, width, height) {
  const samplesPerPixel = 4;
  const accumulator = new Uint16Array(width * height);

  const polygons =
    land.type === 'MultiPolygon' ? land.coordinates : land.type === 'Polygon' ? [land.coordinates] : [];

  // Every ring of every polygon, as edges in pixel space. Holes (inner rings)
  // are included: the even-odd rule turns them into gaps automatically, which
  // is what makes the Caspian Sea a hole rather than land.
  const edges = [];

  for (const polygon of polygons) {
    for (const ring of polygon) {
      for (let i = 0; i < ring.length - 1; i += 1) {
        const [lon1, lat1] = ring[i];
        const [lon2, lat2] = ring[i + 1];

        // Skip the wrapping segment; the ring closes the other way round.
        if (Math.abs(lon2 - lon1) > 180) continue;

        const x1 = ((lon1 + 180) / 360) * width;
        const x2 = ((lon2 + 180) / 360) * width;
        const y1 = ((90 - lat1) / 180) * height;
        const y2 = ((90 - lat2) / 180) * height;

        if (y1 === y2) continue; // horizontal edges contribute no crossings

        edges.push({ x1, y1, x2, y2, yMin: Math.min(y1, y2), yMax: Math.max(y1, y2) });
      }
    }
  }

  // Bucket the edges by pixel row so each scanline only tests the edges that
  // can cross it. Without this the fill is O(rows × edges) and takes minutes
  // at 4096 wide.
  const buckets = Array.from({ length: height }, () => []);

  for (const edge of edges) {
    const first = Math.max(0, Math.floor(edge.yMin));
    const last = Math.min(height - 1, Math.ceil(edge.yMax));

    for (let row = first; row <= last; row += 1) {
      buckets[row].push(edge);
    }
  }

  const crossings = [];

  for (let row = 0; row < height; row += 1) {
    const candidates = buckets[row];
    if (candidates.length === 0) continue;

    for (let sample = 0; sample < samplesPerPixel; sample += 1) {
      const y = row + (sample + 0.5) / samplesPerPixel;

      crossings.length = 0;

      for (const edge of candidates) {
        if (y < edge.yMin || y >= edge.yMax) continue;

        const t = (y - edge.y1) / (edge.y2 - edge.y1);
        crossings.push(edge.x1 + t * (edge.x2 - edge.x1));
      }

      if (crossings.length < 2) continue;

      crossings.sort((a, b) => a - b);

      for (let i = 0; i + 1 < crossings.length; i += 2) {
        fillSpan(accumulator, width, row, crossings[i], crossings[i + 1]);
      }
    }
  }

  // Convert coverage counts to bytes.
  const maximum = samplesPerPixel * 256;
  const pixels = new Uint8Array(width * height);

  for (let i = 0; i < pixels.length; i += 1) {
    pixels[i] = Math.min(255, Math.round((accumulator[i] / maximum) * 255));
  }

  return encodeGrayscalePng(pixels, width, height);
}

/**
 * Adds coverage for one horizontal span, with partial coverage at both ends so
 * the edge is smooth rather than snapped to a pixel boundary.
 */
function fillSpan(accumulator, width, row, xStart, xEnd) {
  const start = Math.max(0, xStart);
  const end = Math.min(width, xEnd);

  if (end <= start) return;

  const first = Math.floor(start);
  const last = Math.min(width - 1, Math.ceil(end) - 1);
  const base = row * width;

  for (let x = first; x <= last; x += 1) {
    const overlap = Math.min(x + 1, end) - Math.max(x, start);

    if (overlap > 0) {
      accumulator[base + x] += Math.round(overlap * 256);
    }
  }
}

// ---------------------------------------------------------------------------
// A minimal PNG encoder
//
// Only 8-bit greyscale, filter type 0. Writing it here avoids a native
// dependency for what amounts to a zlib call and three CRCs.
// ---------------------------------------------------------------------------

function encodeGrayscalePng(pixels, width, height) {
  const header = Buffer.alloc(13);
  header.writeUInt32BE(width, 0);
  header.writeUInt32BE(height, 4);
  header.writeUInt8(8, 8);  // bit depth
  header.writeUInt8(0, 9);  // colour type: greyscale
  header.writeUInt8(0, 10); // compression: deflate
  header.writeUInt8(0, 11); // filter method
  header.writeUInt8(0, 12); // interlace: none

  // Each scanline is prefixed with its filter type. Type 2 (Up) predicts each
  // byte from the one above, which on a land mask — long runs of identical
  // rows — deflates far better than no filtering.
  const raw = Buffer.alloc((width + 1) * height);

  for (let row = 0; row < height; row += 1) {
    const target = row * (width + 1);
    raw[target] = 2;

    for (let x = 0; x < width; x += 1) {
      const current = pixels[row * width + x];
      const above = row === 0 ? 0 : pixels[(row - 1) * width + x];
      raw[target + 1 + x] = (current - above) & 0xff;
    }
  }

  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', header),
    chunk('IDAT', deflateSync(raw, { level: 9 })),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}

function chunk(type, data) {
  const length = Buffer.alloc(4);
  length.writeUInt32BE(data.length, 0);

  const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);

  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body), 0);

  return Buffer.concat([length, body, crc]);
}

const crcTable = (() => {
  const table = new Uint32Array(256);

  for (let n = 0; n < 256; n += 1) {
    let c = n;
    for (let k = 0; k < 8; k += 1) {
      c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    }
    table[n] = c >>> 0;
  }

  return table;
})();

function crc32(buffer) {
  let c = 0xffffffff;

  for (let i = 0; i < buffer.length; i += 1) {
    c = crcTable[(c ^ buffer[i]) & 0xff] ^ (c >>> 8);
  }

  return (c ^ 0xffffffff) >>> 0;
}

// ---------------------------------------------------------------------------

function report(file, coast, borders) {
  const { size } = statSafe(file);
  const kb = (size / 1024).toFixed(1);

  if (coast) {
    const coastPoints = collectStrips(coast).reduce((n, s) => n + s.length, 0);
    const borderPoints = collectStrips(borders).reduce((n, s) => n + s.length, 0);
    console.log(`  ${file.split('/').pop().padEnd(28)} ${kb.padStart(8)} KB  ${coastPoints} coast + ${borderPoints} border points`);
    return;
  }

  console.log(`  ${file.split('/').pop().padEnd(28)} ${kb.padStart(8)} KB`);
}

function statSafe(file) {
  try {
    return { size: readFileSync(file).length };
  } catch {
    return { size: 0 };
  }
}

// Invoked last: crcTable and the other module-level constants must be
// initialised before the first PNG is encoded.
main();
