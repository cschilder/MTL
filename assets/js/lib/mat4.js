/**
 * The 4×4 matrix and 3-vector maths the globe needs.
 *
 * Column-major, the layout WebGL expects, so a matrix can be handed straight
 * to uniformMatrix4fv without transposing. Every function writes into an
 * output array rather than allocating: the render loop runs sixty times a
 * second (or ninety in a headset) and the garbage would show up as stutter.
 */

export function create() {
  return new Float32Array([1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1]);
}

export function identity(out) {
  out[0] = 1; out[1] = 0; out[2] = 0; out[3] = 0;
  out[4] = 0; out[5] = 1; out[6] = 0; out[7] = 0;
  out[8] = 0; out[9] = 0; out[10] = 1; out[11] = 0;
  out[12] = 0; out[13] = 0; out[14] = 0; out[15] = 1;
  return out;
}

export function copy(out, a) {
  out.set(a);
  return out;
}

/**
 * A right-handed perspective projection with the far plane at infinity.
 *
 * The infinite far plane costs nothing and removes the usual trade-off between
 * clipping the globe when zoomed out and losing depth precision when zoomed
 * in — which matters here, because coastlines sit a fraction of a percent
 * above the sphere they are drawn on.
 */
export function perspective(out, fovY, aspect, near) {
  const f = 1 / Math.tan(fovY / 2);

  out[0] = f / aspect; out[1] = 0; out[2] = 0; out[3] = 0;
  out[4] = 0; out[5] = f; out[6] = 0; out[7] = 0;
  out[8] = 0; out[9] = 0; out[10] = -1; out[11] = -1;
  out[12] = 0; out[13] = 0; out[14] = -2 * near; out[15] = 0;

  return out;
}

export function lookAt(out, eye, target, up) {
  let z0 = eye[0] - target[0];
  let z1 = eye[1] - target[1];
  let z2 = eye[2] - target[2];

  let length = Math.hypot(z0, z1, z2);

  if (length === 0) {
    z2 = 1;
    length = 1;
  }

  z0 /= length; z1 /= length; z2 /= length;

  let x0 = up[1] * z2 - up[2] * z1;
  let x1 = up[2] * z0 - up[0] * z2;
  let x2 = up[0] * z1 - up[1] * z0;

  length = Math.hypot(x0, x1, x2);

  if (length === 0) {
    // The view direction is parallel to `up`; nudge it so the cross product
    // has a defined result instead of collapsing the basis.
    x0 = 1; x1 = 0; x2 = 0;
  } else {
    x0 /= length; x1 /= length; x2 /= length;
  }

  const y0 = z1 * x2 - z2 * x1;
  const y1 = z2 * x0 - z0 * x2;
  const y2 = z0 * x1 - z1 * x0;

  out[0] = x0; out[1] = y0; out[2] = z0; out[3] = 0;
  out[4] = x1; out[5] = y1; out[6] = z1; out[7] = 0;
  out[8] = x2; out[9] = y2; out[10] = z2; out[11] = 0;
  out[12] = -(x0 * eye[0] + x1 * eye[1] + x2 * eye[2]);
  out[13] = -(y0 * eye[0] + y1 * eye[1] + y2 * eye[2]);
  out[14] = -(z0 * eye[0] + z1 * eye[1] + z2 * eye[2]);
  out[15] = 1;

  return out;
}

export function multiply(out, a, b) {
  const a00 = a[0], a01 = a[1], a02 = a[2], a03 = a[3];
  const a10 = a[4], a11 = a[5], a12 = a[6], a13 = a[7];
  const a20 = a[8], a21 = a[9], a22 = a[10], a23 = a[11];
  const a30 = a[12], a31 = a[13], a32 = a[14], a33 = a[15];

  for (let i = 0; i < 4; i += 1) {
    const b0 = b[i * 4], b1 = b[i * 4 + 1], b2 = b[i * 4 + 2], b3 = b[i * 4 + 3];

    out[i * 4] = b0 * a00 + b1 * a10 + b2 * a20 + b3 * a30;
    out[i * 4 + 1] = b0 * a01 + b1 * a11 + b2 * a21 + b3 * a31;
    out[i * 4 + 2] = b0 * a02 + b1 * a12 + b2 * a22 + b3 * a32;
    out[i * 4 + 3] = b0 * a03 + b1 * a13 + b2 * a23 + b3 * a33;
  }

  return out;
}

export function invert(out, a) {
  const a00 = a[0], a01 = a[1], a02 = a[2], a03 = a[3];
  const a10 = a[4], a11 = a[5], a12 = a[6], a13 = a[7];
  const a20 = a[8], a21 = a[9], a22 = a[10], a23 = a[11];
  const a30 = a[12], a31 = a[13], a32 = a[14], a33 = a[15];

  const b00 = a00 * a11 - a01 * a10;
  const b01 = a00 * a12 - a02 * a10;
  const b02 = a00 * a13 - a03 * a10;
  const b03 = a01 * a12 - a02 * a11;
  const b04 = a01 * a13 - a03 * a11;
  const b05 = a02 * a13 - a03 * a12;
  const b06 = a20 * a31 - a21 * a30;
  const b07 = a20 * a32 - a22 * a30;
  const b08 = a20 * a33 - a23 * a30;
  const b09 = a21 * a32 - a22 * a31;
  const b10 = a21 * a33 - a23 * a31;
  const b11 = a22 * a33 - a23 * a32;

  let determinant = b00 * b11 - b01 * b10 + b02 * b09 + b03 * b08 - b04 * b07 + b05 * b06;

  if (!determinant) return null;

  determinant = 1 / determinant;

  out[0] = (a11 * b11 - a12 * b10 + a13 * b09) * determinant;
  out[1] = (a02 * b10 - a01 * b11 - a03 * b09) * determinant;
  out[2] = (a31 * b05 - a32 * b04 + a33 * b03) * determinant;
  out[3] = (a22 * b04 - a21 * b05 - a23 * b03) * determinant;
  out[4] = (a12 * b08 - a10 * b11 - a13 * b07) * determinant;
  out[5] = (a00 * b11 - a02 * b08 + a03 * b07) * determinant;
  out[6] = (a32 * b02 - a30 * b05 - a33 * b01) * determinant;
  out[7] = (a20 * b05 - a22 * b02 + a23 * b01) * determinant;
  out[8] = (a10 * b10 - a11 * b08 + a13 * b06) * determinant;
  out[9] = (a01 * b08 - a00 * b10 - a03 * b06) * determinant;
  out[10] = (a30 * b04 - a31 * b02 + a33 * b00) * determinant;
  out[11] = (a21 * b02 - a20 * b04 - a23 * b00) * determinant;
  out[12] = (a11 * b07 - a10 * b09 - a12 * b06) * determinant;
  out[13] = (a00 * b09 - a01 * b07 + a02 * b06) * determinant;
  out[14] = (a31 * b01 - a30 * b03 - a32 * b00) * determinant;
  out[15] = (a20 * b03 - a21 * b01 + a22 * b00) * determinant;

  return out;
}

export function rotationY(out, radians) {
  const s = Math.sin(radians);
  const c = Math.cos(radians);

  identity(out);
  out[0] = c; out[2] = -s;
  out[8] = s; out[10] = c;

  return out;
}

export function rotationX(out, radians) {
  const s = Math.sin(radians);
  const c = Math.cos(radians);

  identity(out);
  out[5] = c; out[6] = s;
  out[9] = -s; out[10] = c;

  return out;
}

/** Transforms a point (w = 1) and divides by w, giving clip-space coordinates. */
export function transformPoint(out, matrix, point) {
  const x = point[0], y = point[1], z = point[2];

  const w = matrix[3] * x + matrix[7] * y + matrix[11] * z + matrix[15] || 1;

  out[0] = (matrix[0] * x + matrix[4] * y + matrix[8] * z + matrix[12]) / w;
  out[1] = (matrix[1] * x + matrix[5] * y + matrix[9] * z + matrix[13]) / w;
  out[2] = (matrix[2] * x + matrix[6] * y + matrix[10] * z + matrix[14]) / w;

  return out;
}

// ---------------------------------------------------------------------------
// Spherical helpers
// ---------------------------------------------------------------------------

/**
 * Converts geographic coordinates to a point on a sphere of the given radius.
 *
 * The convention: +Y is north, and longitude 0 faces +Z, so the prime meridian
 * is towards the viewer at the default camera position.
 */
export function latLonToVector(latitude, longitude, radius = 1, out = [0, 0, 0]) {
  const phi = (latitude * Math.PI) / 180;
  const theta = (longitude * Math.PI) / 180;

  const cosPhi = Math.cos(phi);

  out[0] = radius * cosPhi * Math.sin(theta);
  out[1] = radius * Math.sin(phi);
  out[2] = radius * cosPhi * Math.cos(theta);

  return out;
}

/** The inverse: a point on the unit sphere back to latitude and longitude. */
export function vectorToLatLon(vector) {
  const length = Math.hypot(vector[0], vector[1], vector[2]) || 1;

  const latitude = (Math.asin(vector[1] / length) * 180) / Math.PI;
  const longitude = (Math.atan2(vector[0] / length, vector[2] / length) * 180) / Math.PI;

  return [latitude, longitude];
}

/**
 * Interpolates along the great circle between two geographic points.
 *
 * Spherical linear interpolation rather than interpolating latitude and
 * longitude separately: the latter draws a curve that bulges away from the
 * shortest path and looks wrong on any route longer than a few hundred
 * kilometres.
 */
export function greatCirclePoint(from, to, t, radius = 1, out = [0, 0, 0]) {
  const a = latLonToVector(from[0], from[1], 1);
  const b = latLonToVector(to[0], to[1], 1);

  let dot = a[0] * b[0] + a[1] * b[1] + a[2] * b[2];
  dot = Math.max(-1, Math.min(1, dot));

  const omega = Math.acos(dot);

  if (omega < 1e-6) {
    // The points coincide; interpolating would divide by zero.
    out[0] = a[0] * radius; out[1] = a[1] * radius; out[2] = a[2] * radius;
    return out;
  }

  const sinOmega = Math.sin(omega);
  const scaleA = Math.sin((1 - t) * omega) / sinOmega;
  const scaleB = Math.sin(t * omega) / sinOmega;

  out[0] = (a[0] * scaleA + b[0] * scaleB) * radius;
  out[1] = (a[1] * scaleA + b[1] * scaleB) * radius;
  out[2] = (a[2] * scaleA + b[2] * scaleB) * radius;

  return out;
}

/** Angular distance between two geographic points, in radians. */
export function angularDistance(from, to) {
  const a = latLonToVector(from[0], from[1], 1);
  const b = latLonToVector(to[0], to[1], 1);

  return Math.acos(Math.max(-1, Math.min(1, a[0] * b[0] + a[1] * b[1] + a[2] * b[2])));
}

/**
 * The direction of the sun at a given moment, as a unit vector in the same
 * frame as latLonToVector.
 *
 * A low-precision solar position: good to a fraction of a degree, which is
 * far more than a day/night shading needs.
 */
export function sunDirection(date) {
  const millisecondsPerDay = 86400000;

  // Days since the J2000.0 epoch.
  const days = date.getTime() / millisecondsPerDay - 10957.5;

  const meanLongitude = (280.46 + 0.9856474 * days) * (Math.PI / 180);
  const meanAnomaly = (357.528 + 0.9856003 * days) * (Math.PI / 180);

  const eclipticLongitude =
    meanLongitude + (1.915 * Math.sin(meanAnomaly) + 0.02 * Math.sin(2 * meanAnomaly)) * (Math.PI / 180);

  const obliquity = 23.439 * (Math.PI / 180);

  const declination = Math.asin(Math.sin(obliquity) * Math.sin(eclipticLongitude));

  let rightAscension = Math.atan2(
    Math.cos(obliquity) * Math.sin(eclipticLongitude),
    Math.cos(eclipticLongitude),
  );

  // Greenwich mean sidereal time, in radians.
  const gmst = ((18.697374558 + 24.06570982441908 * days) % 24) * (Math.PI / 12);

  // Subtracting sidereal time turns the sun's celestial position into a
  // longitude on the rotating Earth.
  const longitude = rightAscension - gmst;

  return latLonToVector((declination * 180) / Math.PI, (longitude * 180) / Math.PI, 1);
}
