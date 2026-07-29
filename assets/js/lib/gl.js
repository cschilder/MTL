/**
 * A small WebGL layer.
 *
 * MTL draws one sphere, a few thousand line segments and a handful of markers.
 * A general-purpose 3D library would be about six hundred kilobytes to do
 * that, would have to be vendored into the repository, and would still need
 * custom shaders for the day/night terminator and the data layers. This file
 * is the part of such a library that is actually used.
 *
 * Shaders are written against GLSL ES 1.00 so the same source compiles on a
 * WebGL 1 context (an older phone) and a WebGL 2 one (everything current,
 * including the browsers in Quest and Vision Pro headsets).
 */

export function createContext(canvas, options = {}) {
  const attributes = {
    alpha: false,
    antialias: true,
    depth: true,
    stencil: false,
    // The globe is re-rendered continuously while it moves; preserving the
    // buffer between frames would cost bandwidth for nothing.
    preserveDrawingBuffer: false,
    powerPreference: 'high-performance',
    // Required for a context that may later be handed to an XR session.
    xrCompatible: true,
    ...options,
  };

  const gl =
    canvas.getContext('webgl2', attributes) ||
    canvas.getContext('webgl', attributes) ||
    canvas.getContext('experimental-webgl', attributes);

  if (!gl) return null;

  return gl;
}

/**
 * Compiles and links a program, throwing with the driver's own message when
 * something is wrong — that message names the line, which nothing else can.
 */
export function createProgram(gl, vertexSource, fragmentSource, name = 'program') {
  const vertex = compileShader(gl, gl.VERTEX_SHADER, vertexSource, `${name} vertex`);
  const fragment = compileShader(gl, gl.FRAGMENT_SHADER, fragmentSource, `${name} fragment`);

  const program = gl.createProgram();
  gl.attachShader(program, vertex);
  gl.attachShader(program, fragment);
  gl.linkProgram(program);

  // The shaders are reference-counted by the program, so they can be released
  // as soon as it is linked.
  gl.deleteShader(vertex);
  gl.deleteShader(fragment);

  if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
    const log = gl.getProgramInfoLog(program);
    gl.deleteProgram(program);
    throw new Error(`Could not link ${name}: ${log}`);
  }

  return {
    program,
    uniforms: collectUniforms(gl, program),
    attributes: collectAttributes(gl, program),
  };
}

function compileShader(gl, type, source, label) {
  const shader = gl.createShader(type);
  gl.shaderSource(shader, source);
  gl.compileShader(shader);

  if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
    const log = gl.getShaderInfoLog(shader);
    gl.deleteShader(shader);
    throw new Error(`Could not compile ${label}: ${log}`);
  }

  return shader;
}

function collectUniforms(gl, program) {
  const uniforms = {};
  const count = gl.getProgramParameter(program, gl.ACTIVE_UNIFORMS);

  for (let i = 0; i < count; i += 1) {
    const info = gl.getActiveUniform(program, i);
    if (!info) continue;

    // Array uniforms are reported as "name[0]"; store them under the bare name.
    const name = info.name.replace(/\[0\]$/, '');
    uniforms[name] = gl.getUniformLocation(program, info.name);
  }

  return uniforms;
}

function collectAttributes(gl, program) {
  const attributes = {};
  const count = gl.getProgramParameter(program, gl.ACTIVE_ATTRIBUTES);

  for (let i = 0; i < count; i += 1) {
    const info = gl.getActiveAttrib(program, i);
    if (!info) continue;

    attributes[info.name] = gl.getAttribLocation(program, info.name);
  }

  return attributes;
}

export function createBuffer(gl, data, target = gl.ARRAY_BUFFER, usage = gl.STATIC_DRAW) {
  const buffer = gl.createBuffer();
  gl.bindBuffer(target, buffer);
  gl.bufferData(target, data, usage);

  return buffer;
}

export function updateBuffer(gl, buffer, data, target = gl.ARRAY_BUFFER, usage = gl.DYNAMIC_DRAW) {
  gl.bindBuffer(target, buffer);
  gl.bufferData(target, data, usage);
}

/**
 * Binds a vertex attribute, tolerating a shader in which it was optimised
 * away (location -1).
 */
export function bindAttribute(gl, location, buffer, size, stride = 0, offset = 0, type = gl.FLOAT, normalized = false) {
  if (location === undefined || location < 0) return;

  gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
  gl.enableVertexAttribArray(location);
  gl.vertexAttribPointer(location, size, type, normalized, stride, offset);
}

export function disableAttribute(gl, location) {
  if (location === undefined || location < 0) return;
  gl.disableVertexAttribArray(location);
}

/**
 * Uploads an image as a texture.
 *
 * The land mask is sampled with linear filtering and mipmaps: at a shallow
 * viewing angle near the horizon, one screen pixel covers many texels, and
 * without mipmaps that turns into shimmering noise as the globe turns.
 */
export function createTexture(gl, image, { wrapS, wrapT, mipmap = true } = {}) {
  const texture = gl.createTexture();

  gl.bindTexture(gl.TEXTURE_2D, texture);
  gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
  gl.texImage2D(gl.TEXTURE_2D, 0, gl.LUMINANCE, gl.LUMINANCE, gl.UNSIGNED_BYTE, image);

  // Longitude wraps around the globe, latitude does not: repeating the mask
  // vertically would mirror Antarctica onto the north pole.
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, wrapS ?? gl.REPEAT);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, wrapT ?? gl.CLAMP_TO_EDGE);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);

  const isPowerOfTwo = (value) => (value & (value - 1)) === 0;
  const canMipmap = mipmap && isPowerOfTwo(image.width) && isPowerOfTwo(image.height);

  if (canMipmap) {
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR_MIPMAP_LINEAR);
    gl.generateMipmap(gl.TEXTURE_2D);
  } else {
    // A non-power-of-two texture cannot have mipmaps or repeat in WebGL 1.
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
  }

  // Anisotropic filtering, where available, is what keeps the coastline near
  // the horizon from turning to mush.
  const anisotropic =
    gl.getExtension('EXT_texture_filter_anisotropic') ||
    gl.getExtension('WEBKIT_EXT_texture_filter_anisotropic');

  if (anisotropic && canMipmap) {
    const maximum = gl.getParameter(anisotropic.MAX_TEXTURE_MAX_ANISOTROPY_EXT);
    gl.texParameterf(gl.TEXTURE_2D, anisotropic.TEXTURE_MAX_ANISOTROPY_EXT, Math.min(8, maximum));
  }

  return texture;
}

export function loadImage(url) {
  return new Promise((resolve, reject) => {
    const image = new Image();
    image.decoding = 'async';
    image.onload = () => resolve(image);
    image.onerror = () => reject(new Error(`Could not load ${url}`));
    image.src = url;
  });
}

/**
 * Sizes the drawing buffer to the element's box and the device pixel ratio.
 *
 * The ratio is capped: a phone at 3× would be rendering nine times the pixels
 * of a 1× display for a difference nobody can see, and it is the single
 * biggest cause of a globe that runs hot and drains the battery.
 */
export function resizeCanvas(canvas, maximumRatio = 2) {
  const ratio = Math.min(window.devicePixelRatio || 1, maximumRatio);

  const width = Math.max(1, Math.round(canvas.clientWidth * ratio));
  const height = Math.max(1, Math.round(canvas.clientHeight * ratio));

  if (canvas.width !== width || canvas.height !== height) {
    canvas.width = width;
    canvas.height = height;
    return true;
  }

  return false;
}

/** Converts #rrggbb to the 0..1 triple a uniform wants. */
export function hexToRgb(hex, fallback = [0.18, 0.76, 0.5]) {
  const match = /^#?([0-9a-f]{6})$/i.exec((hex || '').trim());

  if (!match) return fallback;

  const value = parseInt(match[1], 16);

  return [((value >> 16) & 255) / 255, ((value >> 8) & 255) / 255, (value & 255) / 255];
}
