/**
 * Orbit controls for the globe.
 *
 * Handles mouse, touch, pen and keyboard through Pointer Events, which is the
 * only way to get the three behaving identically. Everything is expressed as
 * three numbers — yaw, pitch and distance — with momentum applied per frame by
 * the render loop rather than by a timer, so the feel does not change with the
 * refresh rate.
 */

const DEGREES = Math.PI / 180;

export class OrbitControls {
  /**
   * @param {HTMLCanvasElement} canvas
   * @param {{minDistance?:number, maxDistance?:number, onChange?:Function, onTap?:Function}} options
   */
  constructor(canvas, options = {}) {
    this.canvas = canvas;

    this.yaw = options.yaw ?? 0;
    this.pitch = options.pitch ?? 20 * DEGREES;
    this.distance = options.distance ?? 3.2;

    this.minDistance = options.minDistance ?? 1.15;
    this.maxDistance = options.maxDistance ?? 8;

    // Beyond this the camera crosses the pole and the view flips, which is
    // disorienting and makes "up" ambiguous.
    this.maxPitch = 89 * DEGREES;

    this.autoRotate = options.autoRotate ?? false;
    this.autoRotateSpeed = 0.035;

    this.onChange = options.onChange ?? (() => {});
    this.onTap = options.onTap ?? (() => {});
    this.onHover = options.onHover ?? (() => {});

    this.velocityYaw = 0;
    this.velocityPitch = 0;
    this.dragging = false;
    this.moved = false;

    /** @type {Map<number, {x:number, y:number}>} */
    this.pointers = new Map();
    this.pinchDistance = 0;

    this.pressedKeys = new Set();

    // Respect the operating system's reduced-motion setting: the idle spin is
    // exactly the kind of continuous movement it is meant to stop.
    this.reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

    this.attach();
  }

  attach() {
    const canvas = this.canvas;

    canvas.style.touchAction = 'none';

    this.handlePointerDown = this.handlePointerDown.bind(this);
    this.handlePointerMove = this.handlePointerMove.bind(this);
    this.handlePointerUp = this.handlePointerUp.bind(this);
    this.handleWheel = this.handleWheel.bind(this);
    this.handleKeyDown = this.handleKeyDown.bind(this);
    this.handleKeyUp = this.handleKeyUp.bind(this);

    canvas.addEventListener('pointerdown', this.handlePointerDown);
    canvas.addEventListener('pointermove', this.handlePointerMove);
    canvas.addEventListener('pointerup', this.handlePointerUp);
    canvas.addEventListener('pointercancel', this.handlePointerUp);
    canvas.addEventListener('pointerleave', this.handlePointerUp);

    // Not passive: the globe consumes the wheel, and letting the page scroll at
    // the same time makes zooming unusable.
    canvas.addEventListener('wheel', this.handleWheel, { passive: false });

    canvas.addEventListener('keydown', this.handleKeyDown);
    canvas.addEventListener('keyup', this.handleKeyUp);

    // Keyboard reachable, and announced as an interactive element.
    if (!canvas.hasAttribute('tabindex')) {
      canvas.tabIndex = 0;
    }
  }

  detach() {
    const canvas = this.canvas;

    canvas.removeEventListener('pointerdown', this.handlePointerDown);
    canvas.removeEventListener('pointermove', this.handlePointerMove);
    canvas.removeEventListener('pointerup', this.handlePointerUp);
    canvas.removeEventListener('pointercancel', this.handlePointerUp);
    canvas.removeEventListener('pointerleave', this.handlePointerUp);
    canvas.removeEventListener('wheel', this.handleWheel);
    canvas.removeEventListener('keydown', this.handleKeyDown);
    canvas.removeEventListener('keyup', this.handleKeyUp);
  }

  // -------------------------------------------------------------------------
  // Pointer
  // -------------------------------------------------------------------------

  handlePointerDown(event) {
    this.canvas.setPointerCapture?.(event.pointerId);
    this.pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

    this.dragging = true;
    this.moved = false;
    this.velocityYaw = 0;
    this.velocityPitch = 0;

    this.canvas.classList.add('is-dragging');

    if (this.pointers.size === 2) {
      this.pinchDistance = this.currentPinchDistance();
    }
  }

  handlePointerMove(event) {
    if (!this.pointers.has(event.pointerId)) {
      // Not dragging: report the position so the caller can do hit testing for
      // the hover state.
      this.onHover(event);
      return;
    }

    const previous = this.pointers.get(event.pointerId);
    this.pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

    if (this.pointers.size === 2) {
      const distance = this.currentPinchDistance();

      if (this.pinchDistance > 0 && distance > 0) {
        this.zoomBy(this.pinchDistance / distance);
      }

      this.pinchDistance = distance;
      this.moved = true;

      return;
    }

    const deltaX = event.clientX - previous.x;
    const deltaY = event.clientY - previous.y;

    if (Math.abs(deltaX) > 2 || Math.abs(deltaY) > 2) {
      this.moved = true;
    }

    // Drag sensitivity scales with the zoom level: at close range the same
    // finger movement should cover far less of the surface, or the globe
    // becomes impossible to aim.
    const sensitivity = 0.005 * Math.min(1, (this.distance - 1) / 2 + 0.25);

    this.yaw -= deltaX * sensitivity;
    this.pitch += deltaY * sensitivity;

    this.velocityYaw = -deltaX * sensitivity;
    this.velocityPitch = deltaY * sensitivity;

    this.clamp();
    this.onChange();
  }

  handlePointerUp(event) {
    if (!this.pointers.has(event.pointerId)) return;

    this.canvas.releasePointerCapture?.(event.pointerId);
    this.pointers.delete(event.pointerId);

    if (this.pointers.size === 0) {
      this.dragging = false;
      this.canvas.classList.remove('is-dragging');

      // A press that did not move is a tap on whatever is under it.
      if (!this.moved) {
        this.velocityYaw = 0;
        this.velocityPitch = 0;
        this.onTap(event);
      }
    }

    if (this.pointers.size < 2) {
      this.pinchDistance = 0;
    }
  }

  currentPinchDistance() {
    const [a, b] = [...this.pointers.values()];

    return a && b ? Math.hypot(a.x - b.x, a.y - b.y) : 0;
  }

  handleWheel(event) {
    event.preventDefault();

    // Normalise the three delta modes: lines and pages arrive from some mice
    // and from a few browsers on Linux.
    const scale =
      event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? this.canvas.clientHeight : 1;

    this.zoomBy(Math.exp(event.deltaY * scale * 0.0012));
  }

  // -------------------------------------------------------------------------
  // Keyboard
  //
  // The globe has to be operable without a pointer: it is the primary
  // navigation of the site.
  // -------------------------------------------------------------------------

  handleKeyDown(event) {
    const handled = [
      'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
      '+', '=', '-', '_', 'Home', 'PageUp', 'PageDown',
    ];

    if (!handled.includes(event.key)) return;

    event.preventDefault();
    this.pressedKeys.add(event.key);

    if (event.key === 'Home') {
      this.reset();
    }
  }

  handleKeyUp(event) {
    this.pressedKeys.delete(event.key);
  }

  applyKeyboard(deltaSeconds) {
    if (this.pressedKeys.size === 0) return false;

    const speed = 1.2 * deltaSeconds;

    if (this.pressedKeys.has('ArrowLeft')) this.yaw += speed;
    if (this.pressedKeys.has('ArrowRight')) this.yaw -= speed;
    if (this.pressedKeys.has('ArrowUp')) this.pitch -= speed;
    if (this.pressedKeys.has('ArrowDown')) this.pitch += speed;

    if (this.pressedKeys.has('+') || this.pressedKeys.has('=') || this.pressedKeys.has('PageUp')) {
      this.zoomBy(1 - 1.5 * deltaSeconds);
    }

    if (this.pressedKeys.has('-') || this.pressedKeys.has('_') || this.pressedKeys.has('PageDown')) {
      this.zoomBy(1 + 1.5 * deltaSeconds);
    }

    this.clamp();

    return true;
  }

  // -------------------------------------------------------------------------
  // State
  // -------------------------------------------------------------------------

  zoomBy(factor) {
    this.distance = Math.max(this.minDistance, Math.min(this.maxDistance, this.distance * factor));
    this.onChange();
  }

  clamp() {
    this.pitch = Math.max(-this.maxPitch, Math.min(this.maxPitch, this.pitch));

    // Keep yaw in a sane range so it never loses precision after a long spin.
    const twoPi = Math.PI * 2;
    this.yaw = ((this.yaw % twoPi) + twoPi) % twoPi;
  }

  /** Points the camera at a place, without animating. */
  lookAt(latitude, longitude, distance = null) {
    this.pitch = latitude * DEGREES;
    // A surface point at longitude L sits at (sin L, ·, cos L) and the camera
    // at yaw Y looks at longitude Y, so the yaw *is* the longitude. This used
    // to negate it, which flew every "show on the globe" to the mirrored
    // meridian — Iceland's stops landed the camera over Scandinavia.
    this.yaw = longitude * DEGREES;

    if (distance !== null) {
      this.distance = Math.max(this.minDistance, Math.min(this.maxDistance, distance));
    }

    this.clamp();
    this.onChange();
  }

  /**
   * Eases towards a place over time. Returns a function to call each frame,
   * which reports true while the movement is still running.
   */
  flyTo(latitude, longitude, distance = 1.9, seconds = 1.1) {
    const startYaw = this.yaw;
    const startPitch = this.pitch;
    const startDistance = this.distance;

    let targetYaw = latitudeSafe(longitude) * DEGREES;

    // Take the short way round rather than unwinding the long way.
    const twoPi = Math.PI * 2;
    while (targetYaw - startYaw > Math.PI) targetYaw -= twoPi;
    while (targetYaw - startYaw < -Math.PI) targetYaw += twoPi;

    const targetPitch = latitude * DEGREES;
    let elapsed = 0;

    // Reduced motion means jumping straight there.
    if (this.reducedMotion) {
      this.lookAt(latitude, longitude, distance);
      return () => false;
    }

    return (deltaSeconds) => {
      elapsed += deltaSeconds;

      const t = Math.min(1, elapsed / seconds);
      const eased = t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2;

      this.yaw = startYaw + (targetYaw - startYaw) * eased;
      this.pitch = startPitch + (targetPitch - startPitch) * eased;

      // Pull back before diving in, which reads as travel rather than as a cut.
      const arc = Math.sin(eased * Math.PI) * 0.55;
      this.distance = startDistance + (distance - startDistance) * eased + arc;

      this.clamp();
      this.onChange();

      return t < 1;
    };
  }

  reset() {
    this.yaw = 0;
    this.pitch = 20 * DEGREES;
    this.distance = 3.2;
    this.velocityYaw = 0;
    this.velocityPitch = 0;
    this.onChange();
  }

  /**
   * Advances momentum and the idle rotation. Called once per frame.
   *
   * @returns {boolean} whether anything moved, so the caller can skip a redraw
   */
  update(deltaSeconds) {
    let moved = this.applyKeyboard(deltaSeconds);

    if (!this.dragging && (Math.abs(this.velocityYaw) > 1e-5 || Math.abs(this.velocityPitch) > 1e-5)) {
      this.yaw += this.velocityYaw;
      this.pitch += this.velocityPitch;

      // Exponential decay, scaled by the frame time so the glide lasts the
      // same wall-clock duration at any refresh rate.
      const damping = Math.pow(0.92, deltaSeconds * 60);

      this.velocityYaw *= damping;
      this.velocityPitch *= damping;

      this.clamp();
      moved = true;
    }

    if (this.autoRotate && !this.dragging && !this.reducedMotion && this.pointers.size === 0) {
      this.yaw += this.autoRotateSpeed * deltaSeconds;
      moved = true;
    }

    if (moved) this.onChange();

    return moved;
  }

  /** The camera position implied by the current yaw, pitch and distance. */
  cameraPosition(out = [0, 0, 0]) {
    const cosPitch = Math.cos(this.pitch);

    out[0] = this.distance * cosPitch * Math.sin(this.yaw);
    out[1] = this.distance * Math.sin(this.pitch);
    out[2] = this.distance * cosPitch * Math.cos(this.yaw);

    return out;
  }
}

/** Guards against a NaN longitude reaching the trigonometry. */
function latitudeSafe(value) {
  return Number.isFinite(value) ? value : 0;
}
