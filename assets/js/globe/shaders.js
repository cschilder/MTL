/**
 * The globe's shaders, in GLSL ES 1.00 so one source compiles on both WebGL 1
 * and WebGL 2 contexts.
 */

/**
 * The sphere.
 *
 * One texture lookup gives the land coverage; everything else — the coastline
 * softening, the day/night terminator, the atmospheric rim — is computed from
 * the surface normal and the sun direction. That keeps the whole planet to a
 * single 75 KB asset.
 */
export const sphereVertex = `
precision highp float;

attribute vec3 aPosition;
attribute vec2 aUv;

uniform mat4 uViewProjection;
uniform mat4 uModel;

varying vec2 vUv;
varying vec3 vNormal;
varying vec3 vWorld;

void main() {
  vec4 world = uModel * vec4(aPosition, 1.0);

  vWorld = world.xyz;

  // The model matrix is a pure rotation, so it can transform the normal
  // directly; no inverse-transpose is needed.
  vNormal = normalize((uModel * vec4(aPosition, 0.0)).xyz);
  vUv = aUv;

  gl_Position = uViewProjection * world;
}
`;

export const sphereFragment = `
precision highp float;

uniform sampler2D uLandMask;
uniform vec3 uOceanColor;
uniform vec3 uLandColor;
uniform vec3 uCoastColor;
uniform vec3 uAtmosphereColor;
uniform vec3 uSunDirection;
uniform vec3 uCameraPosition;
uniform float uNightMix;      // 0 disables the terminator entirely
uniform float uTexelSize;     // one texel of the mask, in UV units

varying vec2 vUv;
varying vec3 vNormal;
varying vec3 vWorld;

void main() {
  // Four taps around the sample point. A single tap gives a hard, aliased
  // coastline at grazing angles; averaging the neighbourhood softens it into
  // something that reads as a shoreline.
  float land = texture2D(uLandMask, vUv).r * 0.6;
  land += texture2D(uLandMask, vUv + vec2(uTexelSize, 0.0)).r * 0.1;
  land += texture2D(uLandMask, vUv - vec2(uTexelSize, 0.0)).r * 0.1;
  land += texture2D(uLandMask, vUv + vec2(0.0, uTexelSize)).r * 0.1;
  land += texture2D(uLandMask, vUv - vec2(0.0, uTexelSize)).r * 0.1;

  float coverage = smoothstep(0.35, 0.65, land);

  // Land is tinted by latitude, the way the real planet is: a pale tundra
  // wash towards the poles, a warm arid band around the tropics, richer
  // green in between. Subtle on purpose — climate zones, not a paint job.
  float latitude = abs(vNormal.y);

  vec3 landColor = uLandColor;
  landColor = mix(landColor, vec3(0.42, 0.40, 0.28), smoothstep(0.25, 0.42, latitude) * (1.0 - smoothstep(0.42, 0.62, latitude)) * 0.35);
  landColor = mix(landColor, vec3(0.55, 0.58, 0.60), smoothstep(0.78, 0.97, latitude) * 0.55);

  // Shallow water hugs the coast: slightly lighter ocean just before land.
  vec3 oceanColor = mix(uOceanColor, uOceanColor * 1.6 + vec3(0.0, 0.03, 0.05), smoothstep(0.15, 0.35, land) * 0.6);

  vec3 surface = mix(oceanColor, landColor, coverage);

  // A brighter band exactly at the shoreline, which is what makes a coast
  // read as a coast rather than as a colour change.
  float shore = 1.0 - abs(coverage - 0.5) * 2.0;
  surface = mix(surface, uCoastColor, pow(max(shore, 0.0), 3.0) * 0.45);

  vec3 normal = normalize(vNormal);
  vec3 viewDirection = normalize(uCameraPosition - vWorld);

  // Day and night. The terminator is deliberately wide: a hard edge looks
  // like a rendering error, and the real one is softened by the atmosphere
  // over several degrees anyway.
  float sunAngle = dot(normal, normalize(uSunDirection));
  float daylight = smoothstep(-0.18, 0.22, sunAngle);

  vec3 night = surface * 0.16 + vec3(0.02, 0.03, 0.06);
  vec3 lit = surface * (0.35 + 0.65 * max(sunAngle, 0.0));

  vec3 color = mix(lit, mix(night, lit, 1.0 - uNightMix), 1.0 - daylight);

  // Warm the light close to the terminator, the way sunrise looks from orbit.
  float grazing = 1.0 - abs(sunAngle);
  color += vec3(0.20, 0.09, 0.02) * pow(grazing, 6.0) * daylight * uNightMix;

  // The sun's glint on open water — the single strongest "this is a planet,
  // not a diagram" cue. Blinn-Phong on the ocean only; land stays matte.
  vec3 halfVector = normalize(normalize(uSunDirection) + viewDirection);
  float glint = pow(max(dot(normal, halfVector), 0.0), 90.0);
  color += vec3(0.9, 0.85, 0.7) * glint * (1.0 - coverage) * daylight * 0.5;

  // Atmospheric rim: strongest where the surface turns away from the viewer.
  float fresnel = 1.0 - max(dot(normal, viewDirection), 0.0);
  color += uAtmosphereColor * pow(fresnel, 3.0) * (0.35 + 0.65 * daylight);

  gl_FragColor = vec4(color, 1.0);
}
`;

/**
 * Coastlines, borders and the graticule.
 *
 * Drawn as gl.LINES just above the surface. The depth test does the occlusion,
 * so the far side of the globe hides itself without a second pass.
 */
export const lineVertex = `
precision highp float;

attribute vec3 aPosition;

uniform mat4 uViewProjection;
uniform mat4 uModel;
uniform vec3 uCameraPosition;

varying float vFacing;

void main() {
  vec4 world = uModel * vec4(aPosition, 1.0);

  vec3 normal = normalize(world.xyz);
  vec3 toCamera = normalize(uCameraPosition - world.xyz);

  vFacing = dot(normal, toCamera);

  gl_Position = uViewProjection * world;
}
`;

export const lineFragment = `
precision highp float;

uniform vec3 uColor;
uniform float uOpacity;

varying float vFacing;

void main() {
  // Fade lines out as they approach the silhouette. Without this they pile up
  // into a hard dark ring at the edge, where many segments cover few pixels.
  float edge = smoothstep(0.0, 0.25, vFacing);

  gl_FragColor = vec4(uColor, uOpacity * edge);
}
`;

/**
 * Route ribbons.
 *
 * The width is applied in clip space so a route keeps the same thickness on
 * screen however far away it is — and because `lineWidth` above 1.0 is ignored
 * by every current desktop driver, which would otherwise leave routes as a
 * single-pixel thread.
 */
export const routeVertex = `
precision highp float;

attribute vec3 aPosition;
attribute vec3 aDirection;
attribute float aSide;
attribute float aProgress;
attribute float aTime;
attribute float aArc;

uniform mat4 uViewProjection;
uniform mat4 uModel;
uniform vec2 uViewport;
uniform float uWidth;
uniform float uReveal;   // 0..1 along the route
uniform float uNow;      // timeline position, as a unix timestamp
uniform float uUseTime;  // 1 when the timeline is driving the reveal

varying float vProgress;
varying float vSide;
varying float vVisible;
varying float vArc;

void main() {
  vec4 world = uModel * vec4(aPosition, 1.0);
  vec4 clip = uViewProjection * world;

  // Project a point a short way along the tangent, then take the screen-space
  // perpendicular between the two. Doing this in clip space keeps the ribbon
  // an even width regardless of perspective.
  vec4 aheadClip = uViewProjection * (uModel * vec4(aPosition + aDirection * 0.01, 1.0));

  vec2 here = clip.xy / max(clip.w, 0.0001);
  vec2 ahead = aheadClip.xy / max(aheadClip.w, 0.0001);

  vec2 tangent = normalize((ahead - here) * uViewport);
  vec2 normal = vec2(-tangent.y, tangent.x);

  // Divide by the viewport so the offset is in pixels, and by w so it does not
  // shrink with distance.
  vec2 offset = normal * aSide * uWidth / uViewport * clip.w;

  clip.xy += offset;

  vProgress = aProgress;
  vSide = aSide;
  vArc = aArc;

  vVisible = uUseTime > 0.5
    ? step(aTime, uNow)
    : step(aProgress, uReveal);

  gl_Position = clip;
}
`;

export const routeFragment = `
precision highp float;

uniform vec3 uColor;
uniform float uOpacity;

varying float vProgress;
varying float vSide;
varying float vVisible;
varying float vArc;

void main() {
  if (vVisible < 0.5) discard;

  // Soften both long edges of the ribbon, which is the cheapest antialiasing
  // available without multisampling the whole pass.
  float edge = 1.0 - smoothstep(0.55, 1.0, abs(vSide));

  // A dashed travel line, in the itinerary-on-a-map tradition. The pattern is
  // cut along the accumulated arc so every dash is the same length on the
  // ground: one cycle per 1.6 degrees of arc, just over half of it drawn.
  // Cheap smoothsteps on both dash edges keep the cuts from shimmering.
  float cycle = fract(vArc / 0.028);
  float dash = smoothstep(0.0, 0.10, cycle) * (1.0 - smoothstep(0.55, 0.65, cycle));

  float alpha = uOpacity * edge * dash;

  if (alpha < 0.01) discard;

  gl_FragColor = vec4(uColor, alpha);
}
`;

/**
 * Stop markers.
 *
 * Two appearances from one shader: a stop with a photo becomes a small round
 * preview ringed in its trip's colour — the Polarsteps idiom — and a stop
 * without one stays the plain disc-and-ring. Which one is chosen per marker
 * by its atlas coordinate: (-1, -1) means "no photo".
 */
export const markerVertex = `
precision highp float;

attribute vec3 aCentre;
attribute vec2 aCorner;
attribute vec3 aColor;
attribute float aSize;
attribute float aIndex;
attribute vec2 aUvOrigin;

uniform mat4 uViewProjection;
uniform mat4 uModel;
uniform vec3 uCameraRight;
uniform vec3 uCameraUp;
uniform vec3 uCameraPosition;
uniform float uScale;
uniform float uHovered;
uniform float uSelected;

varying vec2 vCorner;
varying vec3 vColor;
varying float vFacing;
varying float vHighlight;
varying vec2 vUvOrigin;

void main() {
  vec4 centre = uModel * vec4(aCentre, 1.0);

  vec3 normal = normalize(centre.xyz);
  vec3 toCamera = normalize(uCameraPosition - centre.xyz);
  vFacing = dot(normal, toCamera);

  float highlight = 0.0;
  if (abs(aIndex - uHovered) < 0.5) highlight = 1.0;
  if (abs(aIndex - uSelected) < 0.5) highlight = 2.0;

  vHighlight = highlight;

  // The billboard is built from the camera's right and up vectors, so it faces
  // the viewer without any per-marker matrix.
  float size = aSize * uScale * (1.0 + highlight * 0.22);

  vec3 offset = uCameraRight * aCorner.x * size + uCameraUp * aCorner.y * size;

  vCorner = aCorner;
  vColor = aColor;
  vUvOrigin = aUvOrigin;

  gl_Position = uViewProjection * vec4(centre.xyz + offset, 1.0);
}
`;

export const markerFragment = `
precision highp float;

uniform sampler2D uAtlas;
uniform float uCell;   // one atlas cell, in UV units

varying vec2 vCorner;
varying vec3 vColor;
varying float vFacing;
varying float vHighlight;
varying vec2 vUvOrigin;

void main() {
  // A marker on the far side of the globe is behind the sphere and would be
  // hidden by the depth test anyway, but discarding early avoids the halo that
  // its soft edge leaves on the silhouette.
  if (vFacing < 0.02) discard;

  float distance = length(vCorner);

  if (distance > 1.0) discard;

  float fade = 0.35 + 0.65 * smoothstep(0.02, 0.3, vFacing);

  // Sampled unconditionally: a texture fetch inside a branch has undefined
  // derivatives. The atlas has no mipmaps, but drivers are not to be tempted.
  vec2 uv = vUvOrigin + vec2(vCorner.x * 0.5 + 0.5, 0.5 - vCorner.y * 0.5) * uCell;
  vec3 photo = texture2D(uAtlas, uv).rgb;

  if (vUvOrigin.x >= 0.0) {
    // The photo preview: the picture inside, a ring of the trip's colour
    // around it, and a hairline of white between the two so the ring reads
    // against any photograph.
    float picture = 1.0 - smoothstep(0.74, 0.80, distance);
    float halo = smoothstep(0.74, 0.80, distance) * (1.0 - smoothstep(0.82, 0.86, distance));
    float ring = smoothstep(0.82, 0.86, distance) * (1.0 - smoothstep(0.94, 1.0, distance));

    vec3 ringColor = mix(vColor, vec3(1.0), vHighlight * 0.25);
    vec3 color = photo * picture + vec3(1.0) * halo + ringColor * ring;

    float alpha = max(picture, max(halo, ring));

    if (alpha < 0.01) discard;

    gl_FragColor = vec4(color, alpha * fade);
    return;
  }

  // Concentric bands: a filled centre, a gap, then a ring.
  float core = 1.0 - smoothstep(0.42, 0.52, distance);
  float ring = smoothstep(0.62, 0.72, distance) * (1.0 - smoothstep(0.86, 0.98, distance));

  float alpha = max(core, ring * (0.55 + vHighlight * 0.45));

  if (alpha < 0.01) discard;

  // The centre is lightened so the marker stays visible against a route of the
  // same colour running underneath it.
  vec3 color = mix(vColor, vec3(1.0), core * 0.35 + vHighlight * 0.2);

  gl_FragColor = vec4(color, alpha * fade);
}
`;

/**
 * The star field: a few thousand points on a far shell around the scene.
 *
 * Sizes and phases vary per star; the phase drives a slow twinkle whenever
 * the scene is animating anyway (auto-rotation, a flight). A static frame
 * simply shows them still — twinkling is never worth waking the render loop.
 */
export const starVertex = `
precision highp float;

attribute vec3 aPosition;
attribute float aSize;
attribute float aPhase;

uniform mat4 uViewProjection;
uniform float uTime;
uniform float uPixelRatio;

varying float vAlpha;
varying float vWarmth;

void main() {
  gl_Position = uViewProjection * vec4(aPosition, 1.0);
  gl_PointSize = aSize * uPixelRatio;

  vAlpha = 0.55 + 0.45 * sin(uTime * (0.4 + fract(aPhase * 7.0)) + aPhase * 40.0);
  vWarmth = fract(aPhase * 13.0);
}
`;

export const starFragment = `
precision highp float;

varying float vAlpha;
varying float vWarmth;

void main() {
  float distance = length(gl_PointCoord - 0.5);

  float alpha = smoothstep(0.5, 0.12, distance) * vAlpha;

  if (alpha < 0.01) discard;

  // A slight spread of star colour, blue-white to warm white.
  vec3 color = mix(vec3(0.75, 0.83, 1.0), vec3(1.0, 0.93, 0.82), vWarmth);

  gl_FragColor = vec4(color, alpha * 0.85);
}
`;

/**
 * The atmosphere: a slightly larger sphere drawn with front faces culled, so
 * only the shell behind the planet is visible as a glow around its edge.
 */
export const atmosphereVertex = `
precision highp float;

attribute vec3 aPosition;

uniform mat4 uViewProjection;
uniform mat4 uModel;
uniform vec3 uCameraPosition;

varying float vIntensity;

void main() {
  vec4 world = uModel * vec4(aPosition * 1.16, 1.0);

  vec3 normal = normalize(aPosition);
  vec3 toCamera = normalize(uCameraPosition - world.xyz);

  // Brightest where the shell is edge-on to the viewer.
  vIntensity = pow(1.0 - abs(dot(normal, toCamera)), 3.5);

  gl_Position = uViewProjection * world;
}
`;

export const atmosphereFragment = `
precision highp float;

uniform vec3 uColor;

varying float vIntensity;

void main() {
  gl_FragColor = vec4(uColor, clamp(vIntensity, 0.0, 1.0) * 0.55);
}
`;
