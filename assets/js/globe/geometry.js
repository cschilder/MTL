/**
 * Geometry generation for the globe: the sphere itself, the coastline and
 * border line strips, the great-circle routes between stops, and the marker
 * quads.
 *
 * Everything is built once into typed arrays and uploaded; the render loop
 * only ever changes uniforms, apart from the marker buffer, which is rebuilt
 * when the time scrubber or the data layer changes what is visible.
 */

import { latLonToVector, greatCirclePoint, angularDistance } from '../lib/mat4.js';

/**
 * A UV sphere.
 *
 * Segment counts are deliberately generous: at the default zoom the silhouette
 * is the most visible thing on the page, and a coarse sphere shows a polygonal
 * edge that reads as a rendering bug.
 *
 * @returns {{positions: Float32Array, uvs: Float32Array, indices: Uint16Array}}
 */
export function createSphere(segmentsX = 128, segmentsY = 64) {
  const vertexCount = (segmentsX + 1) * (segmentsY + 1);

  const positions = new Float32Array(vertexCount * 3);
  const uvs = new Float32Array(vertexCount * 2);

  let p = 0;
  let t = 0;

  for (let y = 0; y <= segmentsY; y += 1) {
    // v runs from 0 at the north pole to 1 at the south, matching the row
    // order of an equirectangular image.
    const v = y / segmentsY;
    const phi = (0.5 - v) * Math.PI;

    const cosPhi = Math.cos(phi);
    const sinPhi = Math.sin(phi);

    for (let x = 0; x <= segmentsX; x += 1) {
      const u = x / segmentsX;

      // u = 0 is 180°W, so the texture's left edge lands on the antimeridian,
      // exactly as the mask was rasterised.
      const theta = (u - 0.5) * Math.PI * 2;

      positions[p] = cosPhi * Math.sin(theta);
      positions[p + 1] = sinPhi;
      positions[p + 2] = cosPhi * Math.cos(theta);
      p += 3;

      uvs[t] = u;
      uvs[t + 1] = v;
      t += 2;
    }
  }

  const indices = new Uint16Array(segmentsX * segmentsY * 6);
  let i = 0;

  for (let y = 0; y < segmentsY; y += 1) {
    for (let x = 0; x < segmentsX; x += 1) {
      const a = y * (segmentsX + 1) + x;
      const b = a + segmentsX + 1;

      indices[i] = a; indices[i + 1] = b; indices[i + 2] = a + 1;
      indices[i + 3] = a + 1; indices[i + 4] = b; indices[i + 5] = b + 1;
      i += 6;
    }
  }

  return { positions, uvs, indices };
}

/**
 * Parses the line file produced by tools/build/build-globe-geometry.mjs.
 *
 * Returns the two sections as flat position arrays ready for gl.LINES: each
 * strip of n points becomes n-1 segments, which is two vertices per segment.
 * Drawing as LINES rather than LINE_STRIP costs some memory and avoids a draw
 * call per strip — there are several thousand strips, and the call overhead
 * would dominate everything else.
 *
 * @returns {{coast: Float32Array, borders: Float32Array}}
 */
export function parseLineGeometry(buffer, radius = 1.0005) {
  const view = new DataView(buffer);

  // The builder writes the constant 0x4d544c47 ("MTLG" read as big-endian)
  // with writeUInt32LE, so the bytes on disk are "GLTM" and reading them back
  // little-endian gives the constant again.
  const magic = view.getUint32(0, true);

  if (magic !== 0x4d544c47) {
    throw new Error(`Not a globe geometry file (magic 0x${magic.toString(16)})`);
  }

  const version = view.getUint16(4, true);

  if (version !== 1) {
    throw new Error(`Unsupported globe geometry version ${version}`);
  }

  const sectionCount = view.getUint16(6, true);

  let offset = 8;
  const sections = [];

  for (let i = 0; i < sectionCount; i += 1) {
    sections.push({
      strips: view.getUint32(offset, true),
      points: view.getUint32(offset + 4, true),
    });
    offset += 8;
  }

  const output = [];

  for (const section of sections) {
    // Segments = points - strips, since each strip loses one.
    const segments = section.points - section.strips;
    const positions = new Float32Array(Math.max(0, segments) * 6);

    let write = 0;
    const point = [0, 0, 0];
    let previous = null;

    for (let s = 0; s < section.strips; s += 1) {
      const count = view.getUint32(offset, true);
      offset += 4;

      previous = null;

      for (let i = 0; i < count; i += 1) {
        const longitude = (view.getInt16(offset, true) / 32767) * 180;
        const latitude = (view.getInt16(offset + 2, true) / 32767) * 90;
        offset += 4;

        latLonToVector(latitude, longitude, radius, point);

        if (previous !== null) {
          positions[write] = previous[0];
          positions[write + 1] = previous[1];
          positions[write + 2] = previous[2];
          positions[write + 3] = point[0];
          positions[write + 4] = point[1];
          positions[write + 5] = point[2];
          write += 6;
        }

        previous = [point[0], point[1], point[2]];
      }
    }

    output.push(positions.subarray(0, write));
  }

  return { coast: output[0] ?? new Float32Array(0), borders: output[1] ?? new Float32Array(0) };
}

/**
 * A latitude/longitude grid.
 *
 * Drawn as geometry rather than computed in the shader because a shader
 * graticule has no way to stay a constant width on screen: it either
 * disappears at the poles or turns into a solid cap.
 */
export function createGraticule(stepDegrees = 15, radius = 1.0004) {
  const positions = [];
  const point = [0, 0, 0];

  const push = (latitude, longitude) => {
    latLonToVector(latitude, longitude, radius, point);
    positions.push(point[0], point[1], point[2]);
  };

  // Parallels.
  for (let latitude = -90 + stepDegrees; latitude < 90; latitude += stepDegrees) {
    for (let longitude = -180; longitude < 180; longitude += 3) {
      push(latitude, longitude);
      push(latitude, longitude + 3);
    }
  }

  // Meridians, drawn pole to pole.
  for (let longitude = -180; longitude < 180; longitude += stepDegrees) {
    for (let latitude = -90; latitude < 90; latitude += 3) {
      push(latitude, longitude);
      push(latitude + 3, longitude);
    }
  }

  return new Float32Array(positions);
}

/**
 * A star field on a far shell around the scene.
 *
 * Deterministic (a seeded generator, no Math.random) so the sky is the same
 * sky on every visit — a subliminal cue of craft, and it keeps screenshots
 * reproducible. Stars cluster mildly towards a band, a nod to the Milky Way
 * without pretending to be a star catalogue.
 *
 * @returns {{positions: Float32Array, sizes: Float32Array, phases: Float32Array, count: number}}
 */
export function createStars(count = 2600, radius = 30) {
  const positions = new Float32Array(count * 3);
  const sizes = new Float32Array(count);
  const phases = new Float32Array(count);

  // Mulberry32: tiny, fast, and plenty random for scenery.
  let seed = 0x9e3779b9;
  const random = () => {
    seed = (seed + 0x6d2b79f5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };

  for (let i = 0; i < count; i += 1) {
    // Uniform on the sphere, then a third of the stars pulled towards an
    // inclined band.
    let y = random() * 2 - 1;
    const theta = random() * Math.PI * 2;

    if (i % 3 === 0) {
      y *= 0.35;
    }

    const r = Math.sqrt(Math.max(0, 1 - y * y));

    positions[i * 3] = Math.cos(theta) * r * radius;
    positions[i * 3 + 1] = y * radius;
    positions[i * 3 + 2] = Math.sin(theta) * r * radius;

    // Many faint stars, a few bright ones — the real distribution.
    const brightness = random();
    sizes[i] = 1 + brightness * brightness * 2.4;
    phases[i] = random();
  }

  return { positions, sizes, phases, count };
}

/**
 * Builds the route ribbons for one trip.
 *
 * A route is drawn as a strip of camera-facing triangles rather than as
 * gl.LINES, because `lineWidth` greater than 1 is ignored by every current
 * desktop driver — a route drawn with it would be a one-pixel thread.
 *
 * Each vertex carries its position, the direction to the next point, a side
 * (-1 or +1) that the vertex shader offsets along, and the cumulative fraction
 * of the route so the shader can reveal it progressively.
 *
 * @param {Array<{lat:number, lon:number, t:number|null}>} stops
 */
export function createRouteRibbon(stops, radius = 1.004) {
  if (stops.length < 2) {
    return { positions: new Float32Array(0), directions: new Float32Array(0), sides: new Float32Array(0), progress: new Float32Array(0), times: new Float32Array(0), arcs: new Float32Array(0), count: 0 };
  }

  const path = [];
  const times = [];

  // Cumulative angular distance at each path point, in radians. The fragment
  // shader cuts the ribbon into dashes along this axis; the screen-space
  // `progress` fraction cannot serve, because equal fractions of a route are
  // not equal lengths of it.
  const arcs = [];
  let travelled = 0;

  for (let i = 0; i < stops.length - 1; i += 1) {
    const from = [stops[i].lat, stops[i].lon];
    const to = [stops[i + 1].lat, stops[i + 1].lon];

    // Subdivide by angular length so a hop between neighbouring towns is not
    // given the same sixty segments as a transatlantic flight.
    const arc = angularDistance(from, to);
    const segments = Math.max(2, Math.min(96, Math.ceil((arc * 180) / Math.PI / 1.5)));

    for (let s = 0; s < segments; s += 1) {
      const t = s / segments;

      path.push(greatCirclePoint(from, to, t, radius, [0, 0, 0]));
      arcs.push(travelled + arc * t);

      // Interpolate the timestamps so the reveal animation moves smoothly
      // along the leg rather than jumping at each stop.
      const timeFrom = stops[i].t;
      const timeTo = stops[i + 1].t;

      times.push(timeFrom !== null && timeTo !== null ? timeFrom + (timeTo - timeFrom) * t : (timeFrom ?? timeTo ?? 0));
    }

    travelled += arc;
  }

  const last = stops[stops.length - 1];
  path.push(latLonToVector(last.lat, last.lon, radius, [0, 0, 0]));
  times.push(last.t ?? times[times.length - 1] ?? 0);
  arcs.push(travelled);

  const count = path.length;

  const positions = new Float32Array(count * 2 * 3);
  const directions = new Float32Array(count * 2 * 3);
  const sides = new Float32Array(count * 2);
  const progress = new Float32Array(count * 2);
  const timeAttribute = new Float32Array(count * 2);
  const arcAttribute = new Float32Array(count * 2);

  for (let i = 0; i < count; i += 1) {
    const current = path[i];
    const next = path[Math.min(count - 1, i + 1)];
    const previous = path[Math.max(0, i - 1)];

    // The tangent at this point, used by the shader to work out which way is
    // "across" the ribbon.
    const dx = next[0] - previous[0];
    const dy = next[1] - previous[1];
    const dz = next[2] - previous[2];
    const length = Math.hypot(dx, dy, dz) || 1;

    const fraction = i / (count - 1);

    for (let side = 0; side < 2; side += 1) {
      const v = i * 2 + side;

      positions[v * 3] = current[0];
      positions[v * 3 + 1] = current[1];
      positions[v * 3 + 2] = current[2];

      directions[v * 3] = dx / length;
      directions[v * 3 + 1] = dy / length;
      directions[v * 3 + 2] = dz / length;

      sides[v] = side === 0 ? -1 : 1;
      progress[v] = fraction;
      timeAttribute[v] = times[i];
      arcAttribute[v] = arcs[i];
    }
  }

  return {
    positions,
    directions,
    sides,
    progress,
    times: timeAttribute,
    arcs: arcAttribute,
    count: count * 2,
  };
}

/**
 * A plane sprite on the midpoint of every long leg of a route.
 *
 * Polarsteps' idiom: a hop between neighbouring towns is just a dotted line,
 * but a leg long enough to have been a flight gets a little plane at its
 * middle, nose along the direction of travel. "Long enough" defaults to two
 * degrees of arc — roughly Amsterdam–Paris.
 *
 * @param {Array<{lat:number, lon:number, t:number|null}>} stops
 * @returns {{centres: Float32Array, corners: Float32Array, directions: Float32Array, times: Float32Array, count: number}}
 */
export function createFlightSprites(stops, minimumArc = 0.035, radius = 1.012) {
  const quad = [
    [-1, -1], [1, -1], [1, 1],
    [-1, -1], [1, 1], [-1, 1],
  ];

  const centres = [];
  const corners = [];
  const directions = [];
  const times = [];

  for (let i = 0; i < stops.length - 1; i += 1) {
    const from = [stops[i].lat, stops[i].lon];
    const to = [stops[i + 1].lat, stops[i + 1].lon];

    if (angularDistance(from, to) < minimumArc) continue;

    const centre = greatCirclePoint(from, to, 0.5, radius, [0, 0, 0]);
    const ahead = greatCirclePoint(from, to, 0.52, radius, [0, 0, 0]);

    const dx = ahead[0] - centre[0];
    const dy = ahead[1] - centre[1];
    const dz = ahead[2] - centre[2];
    const length = Math.hypot(dx, dy, dz) || 1;

    // The plane appears once the leg has been travelled in the timeline.
    const time = stops[i + 1].t ?? stops[i].t ?? 0;

    for (const [cx, cy] of quad) {
      centres.push(centre[0], centre[1], centre[2]);
      corners.push(cx, cy);
      directions.push(dx / length, dy / length, dz / length);
      times.push(time);
    }
  }

  return {
    centres: new Float32Array(centres),
    corners: new Float32Array(corners),
    directions: new Float32Array(directions),
    times: new Float32Array(times),
    count: centres.length / 3,
  };
}

/**
 * Marker geometry: two triangles per stop, expanded into a screen-facing
 * square by the vertex shader.
 *
 * Rebuilt whenever the visible set changes. With a few hundred stops that is a
 * sub-millisecond operation, so there is no reason to reach for instancing and
 * the extension checks it would need.
 *
 * @param {Array<object>} markers each with lat, lon, colour, size and value
 */
export function createMarkerGeometry(markers, radius = 1.008) {
  const count = markers.length;

  const centres = new Float32Array(count * 6 * 3);
  const corners = new Float32Array(count * 6 * 2);
  const colours = new Float32Array(count * 6 * 3);
  const sizes = new Float32Array(count * 6);
  const indexes = new Float32Array(count * 6);

  // Where this marker's photo lives in the thumbnail atlas, as the UV of the
  // cell's top-left corner. (-1, -1) means "no photo": the shader draws the
  // plain disc instead.
  const uvOrigins = new Float32Array(count * 6 * 2);

  // Two triangles, as corner offsets in the marker's own square.
  const quad = [
    [-1, -1], [1, -1], [1, 1],
    [-1, -1], [1, 1], [-1, 1],
  ];

  const point = [0, 0, 0];

  for (let m = 0; m < count; m += 1) {
    const marker = markers[m];

    // Markers on a taller data value sit further out from the surface, which
    // is what makes the fifth dimension readable at a glance.
    latLonToVector(marker.lat, marker.lon, radius + (marker.elevation ?? 0), point);

    for (let v = 0; v < 6; v += 1) {
      const index = m * 6 + v;

      centres[index * 3] = point[0];
      centres[index * 3 + 1] = point[1];
      centres[index * 3 + 2] = point[2];

      corners[index * 2] = quad[v][0];
      corners[index * 2 + 1] = quad[v][1];

      colours[index * 3] = marker.colour[0];
      colours[index * 3 + 1] = marker.colour[1];
      colours[index * 3 + 2] = marker.colour[2];

      sizes[index] = marker.size;
      indexes[index] = m;

      uvOrigins[index * 2] = marker.uvOrigin ? marker.uvOrigin[0] : -1;
      uvOrigins[index * 2 + 1] = marker.uvOrigin ? marker.uvOrigin[1] : -1;
    }
  }

  return { centres, corners, colours, sizes, indexes, uvOrigins, count: count * 6 };
}

/**
 * The stems that connect an elevated marker back to the surface, so a raised
 * marker still reads as belonging to a place rather than floating over it.
 */
export function createMarkerStems(markers, baseRadius = 1.001) {
  const raised = markers.filter((marker) => (marker.elevation ?? 0) > 0.001);

  const positions = new Float32Array(raised.length * 6);
  const colours = new Float32Array(raised.length * 6);

  const bottom = [0, 0, 0];
  const top = [0, 0, 0];

  raised.forEach((marker, i) => {
    latLonToVector(marker.lat, marker.lon, baseRadius, bottom);
    latLonToVector(marker.lat, marker.lon, 1.008 + marker.elevation, top);

    positions.set([bottom[0], bottom[1], bottom[2], top[0], top[1], top[2]], i * 6);
    colours.set([...marker.colour, ...marker.colour], i * 6);
  });

  return { positions, colours, count: raised.length * 2 };
}
