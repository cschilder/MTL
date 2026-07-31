/**
 * WebXR support for the globe.
 *
 * In an immersive session the planet is placed in front of the viewer at arm's
 * length and can be turned with a controller, the way you would turn a physical
 * globe. The page's own controls stay usable through a DOM overlay, which is
 * what makes the timeline and the layer picker reachable without building a
 * second interface in 3D.
 *
 * Everything here degrades to nothing on a browser without WebXR: detect()
 * simply leaves the button hidden.
 */

import * as mat4 from '../lib/mat4.js';

export class XRSessionManager {
  /** @param {import('./index.js').Globe} globe */
  constructor(globe) {
    this.globe = globe;
    this.session = null;
    this.presenting = false;
    this.referenceSpace = null;
    this.button = globe.element.querySelector('[data-globe-action="vr"]');

    // The globe sits a little below eye level and about a metre away, which is
    // where you would hold something you are examining.
    this.origin = [0, -0.15, -1.0];
    this.scale = 0.45;

    this.grabbing = null;
  }

  async detect() {
    if (!this.button) return;

    if (!navigator.xr || this.globe.config.webxr === false) {
      this.button.hidden = true;
      return;
    }

    try {
      const supported = await navigator.xr.isSessionSupported('immersive-vr');

      this.button.hidden = !supported;

      if (supported) {
        this.button.addEventListener('click', () => this.toggle());
      }
    } catch {
      // Some browsers throw rather than resolving false when the feature is
      // present but blocked by permissions policy.
      this.button.hidden = true;
    }
  }

  async toggle() {
    if (this.presenting) {
      await this.end();
      return;
    }

    await this.begin();
  }

  async begin() {
    const gl = this.globe.gl;

    try {
      // The context has to be XR-compatible before it can back a layer. It is
      // created with xrCompatible, but a context that has already rendered may
      // need to be migrated to the headset's GPU.
      await gl.makeXRCompatible();

      this.session = await navigator.xr.requestSession('immersive-vr', {
        optionalFeatures: ['local-floor', 'bounded-floor', 'dom-overlay', 'hand-tracking'],
        domOverlay: { root: this.globe.element },
      });
    } catch (error) {
      console.warn('Could not start the VR session', error);
      return;
    }

    this.presenting = true;

    document.documentElement.classList.add('is-xr-presenting');

    this.session.addEventListener('end', () => this.handleEnd());
    this.session.addEventListener('selectstart', (event) => this.handleSelectStart(event));
    this.session.addEventListener('selectend', () => this.handleSelectEnd());
    this.session.addEventListener('squeezestart', () => { this.squeezing = true; });
    this.session.addEventListener('squeezeend', () => { this.squeezing = false; });

    this.session.updateRenderState({
      baseLayer: new XRWebGLLayer(this.session, gl, { antialias: true }),
    });

    this.referenceSpace =
      (await this.session.requestReferenceSpace('local-floor').catch(() => null)) ||
      (await this.session.requestReferenceSpace('local'));

    if (this.button) {
      this.button.setAttribute('aria-pressed', 'true');
      this.button.setAttribute(
        'aria-label',
        this.globe.strings['js.globe.exit_vr'] ?? 'Leave VR',
      );
    }

    // The page's own animation loop stops; the session drives frames from here.
    this.session.requestAnimationFrame((time, frame) => this.frame(time, frame));
  }

  async end() {
    if (this.session) {
      await this.session.end().catch(() => {});
    }
  }

  handleEnd() {
    this.presenting = false;
    this.session = null;
    this.referenceSpace = null;

    document.documentElement.classList.remove('is-xr-presenting');

    if (this.button) {
      this.button.setAttribute('aria-pressed', 'false');
      this.button.setAttribute('aria-label', this.globe.strings['js.globe.enter_vr'] ?? 'View in VR');
    }

    // Hand the canvas back to the page and restart the normal loop.
    const gl = this.globe.gl;
    gl.bindFramebuffer(gl.FRAMEBUFFER, null);

    this.globe.needsRender = true;
    this.globe.lastFrame = performance.now();
    this.globe.start();
  }

  /**
   * One XR frame: render the scene once per eye into the session's framebuffer.
   */
  frame(time, frame) {
    if (!this.session) return;

    this.session.requestAnimationFrame((t, f) => this.frame(t, f));

    const pose = frame.getViewerPose(this.referenceSpace);

    if (!pose) return;

    const gl = this.globe.gl;
    const layer = this.session.renderState.baseLayer;

    gl.bindFramebuffer(gl.FRAMEBUFFER, layer.framebuffer);

    gl.clearColor(0.008, 0.012, 0.024, 1);
    gl.clear(gl.COLOR_BUFFER_BIT | gl.DEPTH_BUFFER_BIT);

    const delta = Math.min(0.1, (time - (this.lastTime ?? time)) / 1000);
    this.lastTime = time;

    this.updateFromControllers(frame, delta);
    this.globe.update(delta);

    for (const view of pose.views) {
      const viewport = layer.getViewport(view);

      gl.viewport(viewport.x, viewport.y, viewport.width, viewport.height);

      this.renderView(view, viewport);
    }
  }

  /**
   * Renders one eye.
   *
   * The globe's model matrix places and scales the planet in the room; the
   * projection and view come from the headset, so the rest of the scene code
   * is unchanged.
   */
  renderView(view, viewport) {
    const globe = this.globe;

    // The headset supplies the projection; the orbit controls' own camera is
    // not involved.
    globe.matrices.projection.set(view.projectionMatrix);
    globe.matrices.view.set(view.transform.inverse.matrix);

    // Model: the orbit rotation, then a scale, then a translation to where the
    // globe hangs in the room.
    const rotationY = mat4.rotationY(mat4.create(), globe.controls.yaw);
    const rotationX = mat4.rotationX(mat4.create(), -globe.controls.pitch);

    const model = mat4.multiply(mat4.create(), rotationX, rotationY);

    for (let i = 0; i < 12; i += 1) {
      model[i] *= this.scale;
    }

    model[12] = this.origin[0];
    model[13] = this.origin[1];
    model[14] = this.origin[2];

    globe.matrices.model.set(model);

    mat4.multiply(globe.matrices.viewProjection, globe.matrices.projection, globe.matrices.view);

    // The camera position in the globe's own space, which the shaders use for
    // the rim light and for hiding markers on the far side.
    const position = view.transform.position;
    const inverseModel = mat4.invert(mat4.create(), model);

    if (inverseModel) {
      mat4.transformPoint(globe.cameraPosition, inverseModel, [position.x, position.y, position.z]);
    }

    globe.drawScene(viewport.width, viewport.height);
  }

  /**
   * Turns the globe with the controllers.
   *
   * Holding the trigger and moving the hand sideways spins it; squeezing and
   * moving towards or away from the body zooms. Both are relative to where the
   * grab started, which is what makes it feel like holding an object rather
   * than operating a control.
   */
  updateFromControllers(frame, delta) {
    if (!this.session.inputSources) return;

    for (const source of this.session.inputSources) {
      if (!source.gripSpace || !this.grabbing || source !== this.grabbing.source) continue;

      const pose = frame.getPose(source.gripSpace, this.referenceSpace);

      if (!pose) continue;

      const position = pose.transform.position;

      const deltaX = position.x - this.grabbing.x;
      const deltaY = position.y - this.grabbing.y;
      const deltaZ = position.z - this.grabbing.z;

      if (this.squeezing) {
        // Pulling the hand towards the body brings the globe closer.
        this.scale = Math.max(0.15, Math.min(1.4, this.scale - deltaZ * 0.6));
      } else {
        this.globe.controls.yaw -= deltaX * 3.2;
        this.globe.controls.pitch += deltaY * 3.2;
        this.globe.controls.clamp();
      }

      this.grabbing.x = position.x;
      this.grabbing.y = position.y;
      this.grabbing.z = position.z;

      this.globe.needsRender = true;
    }
  }

  handleSelectStart(event) {
    const source = event.inputSource;

    if (!source?.gripSpace) return;

    this.grabbing = { source, x: 0, y: 0, z: 0 };

    // Seed the position on the next frame; the pose is not available here.
    this.grabbing.pending = true;

    this.session.requestAnimationFrame((time, frame) => {
      if (!this.grabbing) return;

      const pose = frame.getPose(source.gripSpace, this.referenceSpace);

      if (pose) {
        this.grabbing.x = pose.transform.position.x;
        this.grabbing.y = pose.transform.position.y;
        this.grabbing.z = pose.transform.position.z;
        this.grabbing.pending = false;
      }
    });
  }

  handleSelectEnd() {
    this.grabbing = null;
  }
}
