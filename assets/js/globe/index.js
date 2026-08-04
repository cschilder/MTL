/**
 * The globe.
 *
 * Five dimensions, in the sense the site uses the term:
 *
 *   1-3  where a stop is — latitude, longitude and altitude on the sphere
 *   4    when it happened — the timeline reveals routes and markers as their
 *        moment arrives, and drives the day/night terminator
 *   5    what it was like — a chosen measure (photographs, rating, altitude,
 *        temperature) that sets each marker's height above the surface and its
 *        colour
 *
 * Everything is drawn with WebGL directly; see lib/gl.js for why there is no
 * 3D library underneath.
 */

import * as mat4 from '../lib/mat4.js';
import {
  createContext, createProgram, createBuffer, updateBuffer,
  bindAttribute, disableAttribute, createTexture, loadImage,
  resizeCanvas, hexToRgb,
} from '../lib/gl.js';
import {
  createSphere, parseLineGeometry, createGraticule, createStars,
  createRouteRibbon, createMarkerGeometry, createMarkerStems,
} from './geometry.js';
import * as shaders from './shaders.js';
import { OrbitControls } from './controls.js';
import { XRSessionManager } from './xr.js';
import { GlobeAudio } from './audio.js';

/** Colour ramps for the data layers, from low to high. */
const RAMPS = {
  rating: [[0.35, 0.42, 0.55], [0.30, 0.68, 0.55], [0.98, 0.79, 0.20]],
  temperature: [[0.30, 0.52, 0.90], [0.55, 0.78, 0.62], [0.92, 0.42, 0.28]],
  altitude: [[0.35, 0.62, 0.48], [0.75, 0.68, 0.45], [0.95, 0.95, 0.98]],
  photos: [[0.28, 0.55, 0.75], [0.35, 0.75, 0.58], [0.98, 0.85, 0.35]],
};

export class Globe {
  /**
   * @param {HTMLElement} element the .mtl-globe container
   * @param {object} config from GlobeService::clientConfig()
   */
  constructor(element, config = {}) {
    this.element = element;
    this.canvas = element.querySelector('[data-globe-canvas]');
    this.config = config;

    this.strings = window.MTL?.strings ?? {};

    this.trips = [];
    this.markers = [];
    this.routes = [];

    this.layer = 'none';
    this.timeEnabled = false;
    this.now = Infinity;
    this.playing = false;

    this.hoveredIndex = -1;
    this.selectedIndex = -1;

    this.needsRender = true;
    this.lastFrame = 0;
    this.flight = null;

    // Reused per frame so the render loop allocates nothing.
    this.matrices = {
      view: mat4.create(),
      projection: mat4.create(),
      viewProjection: mat4.create(),
      model: mat4.create(),
    };

    this.cameraPosition = [0, 0, 0];
    this.sun = [0, 0, 1];
  }

  // -------------------------------------------------------------------------
  // Start-up
  // -------------------------------------------------------------------------

  async init() {
    if (!this.canvas) return false;

    this.gl = createContext(this.canvas);

    if (!this.gl) {
      // The fallback list is already in the document; leaving `is-supported`
      // off keeps it visible.
      this.element.dataset.globeState = 'unsupported';
      return false;
    }

    this.element.classList.add('is-supported');
    this.element.dataset.globeState = 'loading';

    try {
      this.compilePrograms();

      const [geometry, mask, data] = await Promise.all([
        this.fetchLineGeometry(),
        this.fetchLandMask(),
        this.fetchData(),
      ]);

      this.buildSphere();
      this.buildLines(geometry);
      this.landTexture = createTexture(this.gl, mask);
      this.maskTexelSize = 1 / mask.width;

      this.applyData(data);
    } catch (error) {
      console.error('Globe failed to start', error);
      this.element.dataset.globeState = 'failed';
      this.element.classList.remove('is-supported');
      return false;
    }

    this.setupControls();
    this.bindInterface();

    this.xr = new XRSessionManager(this);
    this.xr.detect();

    this.element.dataset.globeState = 'ready';
    this.hideLoading();

    this.observeResize();
    this.start();

    return true;
  }

  compilePrograms() {
    const gl = this.gl;

    this.programs = {
      sphere: createProgram(gl, shaders.sphereVertex, shaders.sphereFragment, 'sphere'),
      line: createProgram(gl, shaders.lineVertex, shaders.lineFragment, 'line'),
      route: createProgram(gl, shaders.routeVertex, shaders.routeFragment, 'route'),
      marker: createProgram(gl, shaders.markerVertex, shaders.markerFragment, 'marker'),
      atmosphere: createProgram(gl, shaders.atmosphereVertex, shaders.atmosphereFragment, 'atmosphere'),
      star: createProgram(gl, shaders.starVertex, shaders.starFragment, 'star'),
    };
  }

  async fetchLineGeometry() {
    // The config points at the land mask; the line file sits beside it under a
    // parallel name, so one setting selects both at the chosen resolution.
    const linesUrl = (this.config.geometry ?? '')
      .replace('globe-land-', 'globe-lines-')
      .replace('.png', '.bin');

    const response = await fetch(linesUrl, { credentials: 'same-origin' });

    if (!response.ok) throw new Error(`Could not load globe geometry (${response.status})`);

    return parseLineGeometry(await response.arrayBuffer());
  }

  fetchLandMask() {
    return loadImage(this.config.geometry);
  }

  async fetchData() {
    const url = this.element.dataset.globeSource || window.MTL?.routes?.globe;

    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });

    if (!response.ok) throw new Error(`Could not load globe data (${response.status})`);

    return response.json();
  }

  // -------------------------------------------------------------------------
  // Static geometry
  // -------------------------------------------------------------------------

  buildSphere() {
    const gl = this.gl;

    // A denser mesh in a headset: the sphere fills the view and its silhouette
    // is the most scrutinised edge on screen.
    const dense = this.config.resolution === 'high';
    const sphere = createSphere(dense ? 192 : 128, dense ? 96 : 64);

    this.sphere = {
      positions: createBuffer(gl, sphere.positions),
      uvs: createBuffer(gl, sphere.uvs),
      indices: createBuffer(gl, sphere.indices, gl.ELEMENT_ARRAY_BUFFER),
      count: sphere.indices.length,
    };
  }

  buildLines(geometry) {
    const gl = this.gl;

    this.coast = { buffer: createBuffer(gl, geometry.coast), count: geometry.coast.length / 3 };
    this.borders = { buffer: createBuffer(gl, geometry.borders), count: geometry.borders.length / 3 };

    const graticule = createGraticule(15);
    this.graticule = { buffer: createBuffer(gl, graticule), count: graticule.length / 3 };

    const stars = createStars();

    this.stars = {
      positions: createBuffer(gl, stars.positions),
      sizes: createBuffer(gl, stars.sizes),
      phases: createBuffer(gl, stars.phases),
      count: stars.count,
    };

    this.startedAt = performance.now();
  }

  // -------------------------------------------------------------------------
  // Trip data
  // -------------------------------------------------------------------------

  applyData(data) {
    const gl = this.gl;

    this.trips = data.trips ?? [];
    this.stats = data.stats ?? {};
    this.bounds = data.bounds ?? { from: null, to: null };

    // A flat list of every stop, which is what picking and the marker buffer
    // both work from.
    this.stops = [];

    for (const trip of this.trips) {
      const colour = hexToRgb(trip.color);

      for (const step of trip.steps) {
        this.stops.push({ ...step, trip, colour });
      }
    }

    // Routes: one ribbon per trip.
    this.routes = this.trips
      .map((trip) => {
        const ribbon = createRouteRibbon(trip.steps.map((s) => ({ lat: s.lat, lon: s.lon, t: s.t })));

        if (ribbon.count === 0) return null;

        return {
          trip,
          colour: hexToRgb(trip.color),
          count: ribbon.count,
          buffers: {
            positions: createBuffer(gl, ribbon.positions),
            directions: createBuffer(gl, ribbon.directions),
            sides: createBuffer(gl, ribbon.sides),
            progress: createBuffer(gl, ribbon.progress),
            times: createBuffer(gl, ribbon.times),
            arcs: createBuffer(gl, ribbon.arcs),
          },
        };
      })
      .filter(Boolean);

    this.markerBuffers = {
      centres: gl.createBuffer(),
      corners: gl.createBuffer(),
      colours: gl.createBuffer(),
      sizes: gl.createBuffer(),
      indexes: gl.createBuffer(),
      uvOrigins: gl.createBuffer(),
    };

    this.stemBuffers = {
      positions: gl.createBuffer(),
      colours: gl.createBuffer(),
    };

    this.rebuildMarkers();
    this.updateTimelineBounds();

    // The photo previews arrive after the first frame: the globe draws with
    // plain discs immediately and swaps them for thumbnails when the atlas is
    // ready, so a slow photo never delays the planet.
    this.buildThumbAtlas().catch((error) => console.warn('Globe thumbnails skipped', error));
  }

  /**
   * Packs every stop's thumbnail into one texture atlas.
   *
   * One texture rather than one per stop: markers are drawn in a single call,
   * and a call per photo would defeat that. Failures are simply skipped — a
   * stop whose photo cannot load keeps its disc.
   */
  async buildThumbAtlas() {
    const urls = [];
    const cellByUrl = new Map();

    for (const stop of this.stops) {
      if (stop.thumb && !cellByUrl.has(stop.thumb) && urls.length < 256) {
        cellByUrl.set(stop.thumb, urls.length);
        urls.push(stop.thumb);
      }
    }

    if (urls.length === 0) return;

    const cellPixels = 64;
    const columns = urls.length > 64 ? 16 : 8;
    const atlasPixels = columns * cellPixels;

    const canvas = document.createElement('canvas');
    canvas.width = atlasPixels;
    canvas.height = atlasPixels;

    const context = canvas.getContext('2d');
    const loaded = await Promise.allSettled(urls.map((url) => loadImage(url)));

    // stop.id → the UV origin of its cell. Only successfully drawn photos are
    // entered, so a failed load falls back to the disc by absence.
    this.thumbOrigins = new Map();

    loaded.forEach((result, index) => {
      if (result.status !== 'fulfilled') return;

      const image = result.value;
      const x = (index % columns) * cellPixels;
      const y = Math.floor(index / columns) * cellPixels;

      // Cover-crop into the square cell, clipped so a wide photo cannot bleed
      // into its neighbour's cell.
      const scale = Math.max(cellPixels / image.width, cellPixels / image.height);

      context.save();
      context.beginPath();
      context.rect(x, y, cellPixels, cellPixels);
      context.clip();
      context.drawImage(
        image,
        x + (cellPixels - image.width * scale) / 2,
        y + (cellPixels - image.height * scale) / 2,
        image.width * scale,
        image.height * scale,
      );
      context.restore();

      for (const stop of this.stops) {
        if (stop.thumb === urls[index]) {
          this.thumbOrigins.set(stop.id, [x / atlasPixels, y / atlasPixels]);
        }
      }
    });

    if (this.thumbOrigins.size === 0) return;

    const gl = this.gl;

    this.thumbAtlas = gl.createTexture();
    this.thumbCell = cellPixels / atlasPixels;

    gl.bindTexture(gl.TEXTURE_2D, this.thumbAtlas);
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, canvas);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);

    this.rebuildMarkers();
  }

  /**
   * Rebuilds the marker geometry for the current layer and timeline position.
   */
  rebuildMarkers() {
    const gl = this.gl;

    const values = this.stops.map((stop) => this.layerValue(stop));
    const finite = values.filter((value) => Number.isFinite(value));

    const minimum = finite.length ? Math.min(...finite) : 0;
    const maximum = finite.length ? Math.max(...finite) : 1;
    const span = maximum - minimum || 1;

    this.visibleStops = [];

    const markers = [];

    this.stops.forEach((stop, index) => {
      if (this.timeEnabled && stop.t !== null && stop.t > this.now) return;

      const value = values[index];
      const normalised = Number.isFinite(value) ? (value - minimum) / span : 0;

      // In the plain view a stop with a photo is drawn as a round preview of
      // it. The data layers keep the coloured discs: a thumbnail cannot also
      // read as a measurement.
      const uvOrigin = this.layer === 'none' ? this.thumbOrigins?.get(stop.id) ?? null : null;

      markers.push({
        lat: stop.lat,
        lon: stop.lon,
        colour: this.layer === 'none' ? stop.colour : rampColour(RAMPS[this.layer] ?? RAMPS.photos, normalised),
        // A floor on the size keeps a stop with no data for the current layer
        // clickable rather than invisible — and a photo preview needs room to
        // be recognisable at all.
        size: (uvOrigin ? 0.052 : 0.018 + normalised * 0.022) * (this.config.markerScale ?? 1),
        elevation: this.layer === 'none' ? 0 : normalised * 0.18,
        uvOrigin,
        stop,
      });

      this.visibleStops.push({ ...stop, markerIndex: markers.length - 1 });
    });

    this.markerData = markers;

    const geometry = createMarkerGeometry(markers);

    updateBuffer(gl, this.markerBuffers.centres, geometry.centres);
    updateBuffer(gl, this.markerBuffers.corners, geometry.corners);
    updateBuffer(gl, this.markerBuffers.colours, geometry.colours);
    updateBuffer(gl, this.markerBuffers.sizes, geometry.sizes);
    updateBuffer(gl, this.markerBuffers.indexes, geometry.indexes);
    updateBuffer(gl, this.markerBuffers.uvOrigins, geometry.uvOrigins);

    this.markerCount = geometry.count;

    const stems = createMarkerStems(markers);

    updateBuffer(gl, this.stemBuffers.positions, stems.positions);
    updateBuffer(gl, this.stemBuffers.colours, stems.colours);

    this.stemCount = stems.count;

    this.needsRender = true;
  }

  /** The measure the fifth dimension is currently keyed on. */
  layerValue(stop) {
    switch (this.layer) {
      case 'photos': return stop.photos ?? 0;
      case 'rating': return stop.rating ?? NaN;
      case 'altitude': return stop.alt ?? NaN;
      case 'temperature': return stop.temp ?? NaN;
      default: return 0;
    }
  }

  // -------------------------------------------------------------------------
  // Controls and interface
  // -------------------------------------------------------------------------

  setupControls() {
    this.controls = new OrbitControls(this.canvas, {
      autoRotate: this.config.autoRotate ?? true,
      onChange: () => { this.needsRender = true; },
      onTap: (event) => this.handleTap(event),
      onHover: (event) => this.handleHover(event),
    });

    this.canvas.setAttribute('role', 'application');
    this.canvas.setAttribute('aria-label', this.strings['js.globe.loading'] ?? 'Globe');
  }

  bindInterface() {
    const on = (selector, type, handler) => {
      const node = this.element.querySelector(selector);
      if (node) node.addEventListener(type, handler);
      return node;
    };

    on('[data-globe-action="zoom-in"]', 'click', () => this.controls.zoomBy(0.8));
    on('[data-globe-action="zoom-out"]', 'click', () => this.controls.zoomBy(1.25));
    on('[data-globe-action="reset"]', 'click', () => this.controls.reset());

    this.rotateButton = on('[data-globe-action="rotate"]', 'click', (event) => {
      this.controls.autoRotate = !this.controls.autoRotate;
      event.currentTarget.setAttribute('aria-pressed', String(this.controls.autoRotate));
      this.needsRender = true;
    });

    this.rotateButton?.setAttribute('aria-pressed', String(this.controls.autoRotate));

    // Sound is opt-in and remembered. Starting it requires a user gesture,
    // so a remembered "on" arms itself on the first interaction with the page
    // rather than presuming to play unasked.
    this.audio = new GlobeAudio();

    const soundButton = on('[data-globe-action="sound"]', 'click', (event) => {
      const enabled = this.audio.toggle();
      event.currentTarget.setAttribute('aria-pressed', String(enabled));
    });

    if (soundButton && GlobeAudio.remembered()) {
      const resume = () => {
        this.audio.start();
        soundButton.setAttribute('aria-pressed', 'true');
      };

      this.element.addEventListener('pointerdown', resume, { once: true });
    }

    this.timelinePanel = this.element.querySelector('[data-globe-timeline]');
    this.layerPanel = this.element.querySelector('[data-globe-layers]');

    on('[data-globe-action="timeline"]', 'click', (event) => {
      this.toggleTimeline(!this.timeEnabled);
      event.currentTarget.setAttribute('aria-pressed', String(this.timeEnabled));
    });

    on('[data-globe-action="layers"]', 'click', (event) => {
      const open = this.layerPanel?.hasAttribute('hidden');
      this.layerPanel?.toggleAttribute('hidden', !open);
      event.currentTarget.setAttribute('aria-pressed', String(open));
    });

    this.playButton = on('[data-globe-action="play"]', 'click', () => this.togglePlayback());

    this.timeSlider = this.element.querySelector('[data-globe-slider]');
    this.timeLabel = this.element.querySelector('[data-globe-date]');

    this.timeSlider?.addEventListener('input', () => {
      this.playing = false;
      this.updatePlayButton();
      this.setTimeFraction(Number(this.timeSlider.value) / 1000);
    });

    this.element.querySelectorAll('[data-globe-layer]').forEach((input) => {
      input.addEventListener('change', () => {
        if (input.checked) this.setLayer(input.value);
      });
    });

    this.tooltip = this.element.querySelector('[data-globe-tooltip]');

    // Any click outside the sphere dismisses the preview card.
    this.element.addEventListener('pointerdown', (event) => {
      if (this.tooltip && !this.tooltip.contains(event.target) && event.target !== this.canvas) {
        this.hideTooltip();
      }
    });
  }

  hideLoading() {
    const loading = this.element.querySelector('[data-globe-loading]');
    if (loading) loading.hidden = true;
  }

  observeResize() {
    const observer = new ResizeObserver(() => { this.needsRender = true; });
    observer.observe(this.canvas);

    this.resizeObserver = observer;
  }

  // -------------------------------------------------------------------------
  // Timeline — the fourth dimension
  // -------------------------------------------------------------------------

  updateTimelineBounds() {
    const times = this.stops.map((stop) => stop.t).filter((t) => typeof t === 'number');

    this.timeFrom = times.length ? Math.min(...times) : null;
    this.timeTo = times.length ? Math.max(...times) : null;

    // Without at least two dated stops there is no span to scrub through.
    const usable = this.timeFrom !== null && this.timeTo !== null && this.timeTo > this.timeFrom;

    const button = this.element.querySelector('[data-globe-action="timeline"]');

    if (button) button.hidden = !usable;
    if (!usable && this.timelinePanel) this.timelinePanel.hidden = true;

    this.timelineUsable = usable;

    if (usable) this.renderTimelineTicks();
  }

  /** Draws a tick per trip so the scrubber shows where the journeys sit. */
  renderTimelineTicks() {
    const container = this.element.querySelector('[data-globe-ticks]');

    if (!container) return;

    container.textContent = '';

    const span = this.timeTo - this.timeFrom || 1;

    for (const trip of this.trips) {
      const times = trip.steps.map((s) => s.t).filter((t) => typeof t === 'number');

      if (times.length === 0) continue;

      const tick = document.createElement('span');
      tick.className = 'mtl-globe__timeline-tick';
      tick.style.left = `${((Math.min(...times) - this.timeFrom) / span) * 100}%`;
      tick.style.background = trip.color;
      tick.title = trip.title;

      container.append(tick);
    }
  }

  toggleTimeline(enabled) {
    this.timeEnabled = enabled && this.timelineUsable;

    if (this.timelinePanel) this.timelinePanel.hidden = !this.timeEnabled;

    if (this.timeEnabled) {
      this.setTimeFraction(Number(this.timeSlider?.value ?? 1000) / 1000);
    } else {
      this.playing = false;
      this.now = Infinity;
      this.updatePlayButton();
      this.rebuildMarkers();
    }

    this.needsRender = true;
  }

  setTimeFraction(fraction) {
    if (this.timeFrom === null || this.timeTo === null) return;

    const clamped = Math.max(0, Math.min(1, fraction));

    this.now = this.timeFrom + (this.timeTo - this.timeFrom) * clamped;
    this.timeFraction = clamped;

    if (this.timeSlider) this.timeSlider.value = String(Math.round(clamped * 1000));

    if (this.timeLabel) {
      this.timeLabel.textContent = new Date(this.now * 1000).toLocaleDateString(
        window.MTL?.locale ?? 'nl',
        { year: 'numeric', month: 'long', day: 'numeric' },
      );
    }

    this.rebuildMarkers();
  }

  togglePlayback() {
    if (!this.timeEnabled) this.toggleTimeline(true);

    this.playing = !this.playing;

    // Starting from the end would look like nothing happens.
    if (this.playing && (this.timeFraction ?? 1) >= 0.999) {
      this.setTimeFraction(0);
    }

    this.updatePlayButton();
    this.needsRender = true;
  }

  updatePlayButton() {
    if (!this.playButton) return;

    this.playButton.setAttribute('aria-pressed', String(this.playing));
    this.playButton.setAttribute(
      'aria-label',
      this.playing ? (this.strings['js.globe.pause'] ?? 'Pause') : (this.strings['js.globe.play'] ?? 'Play'),
    );
  }

  // -------------------------------------------------------------------------
  // Data layers — the fifth dimension
  // -------------------------------------------------------------------------

  setLayer(name) {
    this.layer = name;
    this.rebuildMarkers();
    this.updateLegend();
  }

  updateLegend() {
    const legend = this.element.querySelector('[data-globe-legend]');

    if (!legend) return;

    legend.hidden = this.layer === 'none';

    if (this.layer === 'none') return;

    const ramp = RAMPS[this.layer] ?? RAMPS.photos;

    legend.style.background = `linear-gradient(to right, ${ramp
      .map((c) => `rgb(${c.map((v) => Math.round(v * 255)).join(',')})`)
      .join(', ')})`;
  }

  // -------------------------------------------------------------------------
  // Picking
  // -------------------------------------------------------------------------

  /**
   * Finds the marker nearest to a screen position.
   *
   * Projecting the markers and comparing in screen space, rather than casting
   * a ray: with a few hundred stops it is the same amount of work, and it gets
   * the near-miss case right — a tap fifteen pixels off a marker should still
   * select it.
   */
  pick(clientX, clientY) {
    const rect = this.canvas.getBoundingClientRect();

    const x = ((clientX - rect.left) / rect.width) * 2 - 1;
    const y = -(((clientY - rect.top) / rect.height) * 2 - 1);

    const projected = [0, 0, 0];
    const point = [0, 0, 0];

    let best = -1;
    let bestDistance = Infinity;

    // In device-independent terms: about 26 px on a phone, which is roughly a
    // fingertip.
    const threshold = Math.max(0.04, 26 / Math.min(rect.width, rect.height) * 2);

    this.markerData.forEach((marker, index) => {
      mat4.latLonToVector(marker.lat, marker.lon, 1.008 + (marker.elevation ?? 0), point);

      // Facing away from the camera means it is behind the planet.
      const toCamera = [
        this.cameraPosition[0] - point[0],
        this.cameraPosition[1] - point[1],
        this.cameraPosition[2] - point[2],
      ];

      const facing = point[0] * toCamera[0] + point[1] * toCamera[1] + point[2] * toCamera[2];

      if (facing <= 0) return;

      mat4.transformPoint(projected, this.matrices.viewProjection, point);

      const distance = Math.hypot(projected[0] - x, projected[1] - y);

      if (distance < threshold && distance < bestDistance) {
        bestDistance = distance;
        best = index;
      }
    });

    return best;
  }

  handleHover(event) {
    const index = this.pick(event.clientX, event.clientY);

    if (index === this.hoveredIndex) return;

    this.hoveredIndex = index;
    this.canvas.style.cursor = index >= 0 ? 'pointer' : '';
    this.needsRender = true;
  }

  handleTap(event) {
    const index = this.pick(event.clientX, event.clientY);

    if (index < 0) {
      this.hideTooltip();
      this.selectedIndex = -1;
      this.needsRender = true;
      return;
    }

    // Tapping the already-selected marker opens it; the first tap previews.
    if (index === this.selectedIndex) {
      const stop = this.markerData[index].stop;
      if (stop.url) window.location.assign(stop.url);
      return;
    }

    this.selectedIndex = index;
    this.showTooltip(index);
    this.audio?.tick();
    this.needsRender = true;
  }

  showTooltip(index) {
    if (!this.tooltip) return;

    const marker = this.markerData[index];
    const stop = marker.stop;

    const point = mat4.latLonToVector(marker.lat, marker.lon, 1.008 + (marker.elevation ?? 0));
    const projected = mat4.transformPoint([0, 0, 0], this.matrices.viewProjection, point);

    const rect = this.canvas.getBoundingClientRect();

    this.tooltip.style.left = `${((projected[0] + 1) / 2) * rect.width}px`;
    this.tooltip.style.top = `${((1 - projected[1]) / 2) * rect.height}px`;

    const image = this.tooltip.querySelector('[data-tooltip-image]');
    const title = this.tooltip.querySelector('[data-tooltip-title]');
    const meta = this.tooltip.querySelector('[data-tooltip-meta]');
    const link = this.tooltip.querySelector('[data-tooltip-link]');

    if (image) {
      image.hidden = !stop.thumb;
      if (stop.thumb) {
        image.src = stop.thumb;
        image.alt = '';
      }
    }

    if (title) title.textContent = stop.title;

    if (meta) {
      const parts = [stop.trip.title];

      if (stop.place) parts.push(stop.place);

      if (stop.t) {
        parts.push(new Date(stop.t * 1000).toLocaleDateString(window.MTL?.locale ?? 'nl', {
          year: 'numeric', month: 'short', day: 'numeric',
        }));
      }

      meta.textContent = parts.join(' · ');
    }

    if (link) link.href = stop.url;

    this.tooltip.hidden = false;
  }

  hideTooltip() {
    if (this.tooltip) this.tooltip.hidden = true;
  }

  /** Moves the camera to a stop and opens its card. */
  focusStop(stopId) {
    const index = this.markerData.findIndex((marker) => marker.stop.id === stopId);

    if (index < 0) return;

    const marker = this.markerData[index];

    this.flight = this.controls.flyTo(marker.lat, marker.lon, 1.9);
    this.selectedIndex = index;
    this.audio?.whoosh();
    this.needsRender = true;
  }

  // -------------------------------------------------------------------------
  // Render loop
  // -------------------------------------------------------------------------

  start() {
    this.running = true;
    this.lastFrame = performance.now();

    const frame = (timestamp) => {
      if (!this.running) return;

      // Only schedule through requestAnimationFrame when not in XR; the XR
      // session drives its own loop at the headset's refresh rate.
      if (!this.xr?.presenting) {
        this.animationHandle = requestAnimationFrame(frame);
      }

      const delta = Math.min(0.1, (timestamp - this.lastFrame) / 1000);
      this.lastFrame = timestamp;

      this.update(delta);

      if (this.needsRender) {
        this.render();
        this.needsRender = false;
      }
    };

    this.animationHandle = requestAnimationFrame(frame);
  }

  stop() {
    this.running = false;

    if (this.animationHandle) cancelAnimationFrame(this.animationHandle);
  }

  update(delta) {
    if (this.flight) {
      if (!this.flight(delta)) this.flight = null;
      this.needsRender = true;
    }

    this.controls.update(delta);

    if (this.playing && this.timeEnabled) {
      // A full journey plays in about half a minute regardless of how long it
      // actually took.
      this.setTimeFraction((this.timeFraction ?? 0) + delta / 30);

      if ((this.timeFraction ?? 0) >= 1) {
        this.playing = false;
        this.updatePlayButton();
      }

      this.needsRender = true;
    }

    // The terminator follows the scrubbed moment when the timeline is running,
    // and real time otherwise.
    const moment = this.timeEnabled && Number.isFinite(this.now) ? new Date(this.now * 1000) : new Date();

    this.sun = mat4.sunDirection(moment);
  }

  render() {
    const gl = this.gl;

    if (resizeCanvas(this.canvas)) {
      this.needsRender = true;
    }

    const width = this.canvas.width;
    const height = this.canvas.height;

    gl.viewport(0, 0, width, height);

    this.controls.cameraPosition(this.cameraPosition);

    mat4.perspective(this.matrices.projection, (42 * Math.PI) / 180, width / Math.max(1, height), 0.01);
    mat4.lookAt(this.matrices.view, this.cameraPosition, [0, 0, 0], [0, 1, 0]);
    mat4.multiply(this.matrices.viewProjection, this.matrices.projection, this.matrices.view);
    mat4.identity(this.matrices.model);

    this.drawScene(width, height);

    // The preview card is positioned in page space, so it has to follow the
    // marker as the globe turns.
    if (this.selectedIndex >= 0 && this.tooltip && !this.tooltip.hidden) {
      this.showTooltip(this.selectedIndex);
    }
  }

  /**
   * One complete pass. Separated from render() because the XR loop calls it
   * once per eye with its own matrices.
   */
  drawScene(width, height) {
    const gl = this.gl;

    gl.clearColor(0.024, 0.031, 0.055, 1);
    gl.clear(gl.COLOR_BUFFER_BIT | gl.DEPTH_BUFFER_BIT);

    gl.enable(gl.DEPTH_TEST);
    gl.depthFunc(gl.LEQUAL);
    gl.enable(gl.CULL_FACE);
    gl.cullFace(gl.BACK);

    // The sky first, behind everything and without touching depth.
    gl.enable(gl.BLEND);
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
    this.drawStars();
    gl.disable(gl.BLEND);

    this.drawSphere();

    gl.enable(gl.BLEND);
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);

    // Subdued reference lines: they orient, the planet performs.
    if (this.config.showGraticule) {
      this.drawLines(this.graticule, [0.45, 0.55, 0.68], 0.07);
    }

    this.drawLines(this.borders, [0.55, 0.62, 0.70], 0.22);
    this.drawLines(this.coast, [0.78, 0.86, 0.94], 0.55);

    this.drawRoutes(width, height);
    this.drawStems();
    this.drawMarkers();
    this.drawAtmosphere();

    gl.disable(gl.BLEND);
  }

  drawStars() {
    if (!this.stars) return;

    const gl = this.gl;
    const { program, uniforms, attributes } = this.programs.star;

    gl.useProgram(program);
    gl.depthMask(false);

    bindAttribute(gl, attributes.aPosition, this.stars.positions, 3);
    bindAttribute(gl, attributes.aSize, this.stars.sizes, 1);
    bindAttribute(gl, attributes.aPhase, this.stars.phases, 1);

    gl.uniformMatrix4fv(uniforms.uViewProjection, false, this.matrices.viewProjection);
    gl.uniform1f(uniforms.uTime, (performance.now() - (this.startedAt ?? 0)) / 1000);
    gl.uniform1f(uniforms.uPixelRatio, Math.min(window.devicePixelRatio || 1, 2));

    gl.drawArrays(gl.POINTS, 0, this.stars.count);

    gl.depthMask(true);

    disableAttribute(gl, attributes.aSize);
    disableAttribute(gl, attributes.aPhase);
  }

  drawSphere() {
    const gl = this.gl;
    const { program, uniforms, attributes } = this.programs.sphere;

    gl.useProgram(program);

    bindAttribute(gl, attributes.aPosition, this.sphere.positions, 3);
    bindAttribute(gl, attributes.aUv, this.sphere.uvs, 2);

    gl.uniformMatrix4fv(uniforms.uViewProjection, false, this.matrices.viewProjection);
    gl.uniformMatrix4fv(uniforms.uModel, false, this.matrices.model);

    gl.activeTexture(gl.TEXTURE0);
    gl.bindTexture(gl.TEXTURE_2D, this.landTexture);
    gl.uniform1i(uniforms.uLandMask, 0);

    gl.uniform3f(uniforms.uOceanColor, 0.043, 0.106, 0.208);
    gl.uniform3f(uniforms.uLandColor, 0.118, 0.239, 0.180);
    gl.uniform3f(uniforms.uCoastColor, 0.32, 0.47, 0.42);
    gl.uniform3f(uniforms.uAtmosphereColor, 0.20, 0.42, 0.75);
    gl.uniform3fv(uniforms.uSunDirection, this.sun);
    gl.uniform3fv(uniforms.uCameraPosition, this.cameraPosition);
    gl.uniform1f(uniforms.uNightMix, this.config.showTerminator ? 1 : 0);
    gl.uniform1f(uniforms.uTexelSize, this.maskTexelSize ?? 1 / 2048);

    gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, this.sphere.indices);
    gl.drawElements(gl.TRIANGLES, this.sphere.count, gl.UNSIGNED_SHORT, 0);

    disableAttribute(gl, attributes.aUv);
  }

  drawLines(geometry, colour, opacity) {
    if (!geometry || geometry.count === 0) return;

    const gl = this.gl;
    const { program, uniforms, attributes } = this.programs.line;

    gl.useProgram(program);

    bindAttribute(gl, attributes.aPosition, geometry.buffer, 3);

    gl.uniformMatrix4fv(uniforms.uViewProjection, false, this.matrices.viewProjection);
    gl.uniformMatrix4fv(uniforms.uModel, false, this.matrices.model);
    gl.uniform3fv(uniforms.uCameraPosition, this.cameraPosition);
    gl.uniform3fv(uniforms.uColor, colour);
    gl.uniform1f(uniforms.uOpacity, opacity);

    gl.drawArrays(gl.LINES, 0, geometry.count);
  }

  drawRoutes(width, height) {
    const gl = this.gl;
    const { program, uniforms, attributes } = this.programs.route;

    gl.useProgram(program);

    gl.uniformMatrix4fv(uniforms.uViewProjection, false, this.matrices.viewProjection);
    gl.uniformMatrix4fv(uniforms.uModel, false, this.matrices.model);
    gl.uniform2f(uniforms.uViewport, width, height);
    gl.uniform1f(uniforms.uReveal, 1);
    gl.uniform1f(uniforms.uNow, this.timeEnabled ? this.now : 0);
    gl.uniform1f(uniforms.uUseTime, this.timeEnabled ? 1 : 0);

    // Routes are drawn thicker when zoomed in, where they carry more meaning,
    // but never so thick that they swamp the coastlines.
    const thickness = Math.max(1.5, Math.min(5, 7 / this.controls.distance)) * (window.devicePixelRatio || 1);

    gl.uniform1f(uniforms.uWidth, thickness);

    for (const route of this.routes) {
      bindAttribute(gl, attributes.aPosition, route.buffers.positions, 3);
      bindAttribute(gl, attributes.aDirection, route.buffers.directions, 3);
      bindAttribute(gl, attributes.aSide, route.buffers.sides, 1);
      bindAttribute(gl, attributes.aProgress, route.buffers.progress, 1);
      bindAttribute(gl, attributes.aTime, route.buffers.times, 1);
      bindAttribute(gl, attributes.aArc, route.buffers.arcs, 1);

      gl.uniform3fv(uniforms.uColor, route.colour);
      gl.uniform1f(uniforms.uOpacity, 0.85);

      gl.drawArrays(gl.TRIANGLE_STRIP, 0, route.count);
    }

    disableAttribute(gl, attributes.aDirection);
    disableAttribute(gl, attributes.aSide);
    disableAttribute(gl, attributes.aProgress);
    disableAttribute(gl, attributes.aTime);
    disableAttribute(gl, attributes.aArc);
  }

  drawStems() {
    if (!this.stemCount) return;

    const gl = this.gl;
    const { program, uniforms, attributes } = this.programs.line;

    gl.useProgram(program);

    bindAttribute(gl, attributes.aPosition, this.stemBuffers.positions, 3);

    gl.uniformMatrix4fv(uniforms.uViewProjection, false, this.matrices.viewProjection);
    gl.uniformMatrix4fv(uniforms.uModel, false, this.matrices.model);
    gl.uniform3fv(uniforms.uCameraPosition, this.cameraPosition);
    gl.uniform3f(uniforms.uColor, 0.85, 0.9, 0.95);
    gl.uniform1f(uniforms.uOpacity, 0.35);

    gl.drawArrays(gl.LINES, 0, this.stemCount);
  }

  drawMarkers() {
    if (!this.markerCount) return;

    const gl = this.gl;
    const { program, uniforms, attributes } = this.programs.marker;

    gl.useProgram(program);

    bindAttribute(gl, attributes.aCentre, this.markerBuffers.centres, 3);
    bindAttribute(gl, attributes.aCorner, this.markerBuffers.corners, 2);
    bindAttribute(gl, attributes.aColor, this.markerBuffers.colours, 3);
    bindAttribute(gl, attributes.aSize, this.markerBuffers.sizes, 1);
    bindAttribute(gl, attributes.aIndex, this.markerBuffers.indexes, 1);
    bindAttribute(gl, attributes.aUvOrigin, this.markerBuffers.uvOrigins, 2);

    gl.uniformMatrix4fv(uniforms.uViewProjection, false, this.matrices.viewProjection);
    gl.uniformMatrix4fv(uniforms.uModel, false, this.matrices.model);

    // Unit 1: unit 0 belongs to the land mask, and rebinding it every frame
    // would force the sphere pass to set it back.
    gl.activeTexture(gl.TEXTURE1);
    gl.bindTexture(gl.TEXTURE_2D, this.thumbAtlas ?? null);
    gl.uniform1i(uniforms.uAtlas, 1);
    gl.uniform1f(uniforms.uCell, this.thumbCell ?? 0);
    gl.activeTexture(gl.TEXTURE0);

    // The camera's right and up axes, read out of the view matrix.
    const view = this.matrices.view;

    gl.uniform3f(uniforms.uCameraRight, view[0], view[4], view[8]);
    gl.uniform3f(uniforms.uCameraUp, view[1], view[5], view[9]);
    gl.uniform3fv(uniforms.uCameraPosition, this.cameraPosition);

    // Markers keep a roughly constant screen size as the viewer zooms.
    gl.uniform1f(uniforms.uScale, Math.max(0.6, Math.min(2.2, this.controls.distance / 2.4)));
    gl.uniform1f(uniforms.uHovered, this.hoveredIndex);
    gl.uniform1f(uniforms.uSelected, this.selectedIndex);

    gl.drawArrays(gl.TRIANGLES, 0, this.markerCount);

    disableAttribute(gl, attributes.aCorner);
    disableAttribute(gl, attributes.aColor);
    disableAttribute(gl, attributes.aSize);
    disableAttribute(gl, attributes.aIndex);
    disableAttribute(gl, attributes.aUvOrigin);
  }

  drawAtmosphere() {
    const gl = this.gl;
    const { program, uniforms, attributes } = this.programs.atmosphere;

    gl.useProgram(program);

    // Front faces culled so only the far side of the shell shows, which is
    // what puts the glow outside the planet's silhouette rather than over it.
    gl.cullFace(gl.FRONT);
    gl.depthMask(false);

    bindAttribute(gl, attributes.aPosition, this.sphere.positions, 3);

    gl.uniformMatrix4fv(uniforms.uViewProjection, false, this.matrices.viewProjection);
    gl.uniformMatrix4fv(uniforms.uModel, false, this.matrices.model);
    gl.uniform3fv(uniforms.uCameraPosition, this.cameraPosition);
    gl.uniform3f(uniforms.uColor, 0.32, 0.55, 0.92);

    gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, this.sphere.indices);
    gl.drawElements(gl.TRIANGLES, this.sphere.count, gl.UNSIGNED_SHORT, 0);

    gl.depthMask(true);
    gl.cullFace(gl.BACK);
  }

  destroy() {
    this.stop();
    this.controls?.detach();
    this.resizeObserver?.disconnect();
    this.xr?.end();
  }
}

/** Samples a three-stop colour ramp. */
function rampColour(ramp, t) {
  const clamped = Math.max(0, Math.min(1, t));

  const [low, middle, high] = ramp;

  if (clamped < 0.5) {
    const k = clamped * 2;
    return [
      low[0] + (middle[0] - low[0]) * k,
      low[1] + (middle[1] - low[1]) * k,
      low[2] + (middle[2] - low[2]) * k,
    ];
  }

  const k = (clamped - 0.5) * 2;

  return [
    middle[0] + (high[0] - middle[0]) * k,
    middle[1] + (high[1] - middle[1]) * k,
    middle[2] + (high[2] - middle[2]) * k,
  ];
}
