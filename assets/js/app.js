/**
 * The front-end entry point.
 *
 * Everything MTL does works without JavaScript: pages are rendered on the
 * server, forms submit normally, and the globe has a list of trips underneath
 * it. This file adds the layers that need a browser — and loads the heavy ones
 * only on the pages that use them, so a reader on a phone never downloads the
 * editor.
 */

// The shared helpers live in a leaf module so that nothing ever imports this
// entry point. See assets/js/lib/api.js for why that matters.
import { request, notify, t } from './lib/api.js';

const config = window.MTL ?? {};

// ---------------------------------------------------------------------------
// Progressive enhancements applied to every page
// ---------------------------------------------------------------------------

function enhanceNotifications() {
  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-mtl-dismiss]');

    if (button) {
      button.closest('[data-mtl-toast], .p-notification, [class^="p-notification--"]')?.remove();
    }
  });

  // Server-rendered notifications fade out on their own, except errors.
  document.querySelectorAll('[data-mtl-toast]').forEach((toast) => {
    if (!toast.className.includes('negative')) {
      setTimeout(() => toast.remove(), 6000);
    }
  });
}

/**
 * Turns any form or link marked with data-confirm into one that asks first.
 *
 * A native confirm() rather than a styled dialog: it cannot be missed, it is
 * keyboard accessible everywhere, and it works inside a headset browser.
 */
function enhanceConfirmations() {
  document.addEventListener('submit', (event) => {
    const message = event.target.dataset?.confirm;

    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  });

  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[data-confirm]');

    if (link && !window.confirm(link.dataset.confirm)) {
      event.preventDefault();
    }
  });
}

/**
 * Buttons that copy a value — the share link, mainly.
 */
function enhanceCopyButtons() {
  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');

    if (!button) return;

    event.preventDefault();

    const value = button.dataset.copy || button.previousElementSibling?.value || '';

    try {
      await navigator.clipboard.writeText(value);
      notify(t('js.common.copied'), 'positive');
    } catch {
      // The clipboard API needs a secure context and, in some browsers, a
      // permission. Selecting the text is a workable fallback.
      const input = button.closest('.mtl-row')?.querySelector('input');
      input?.select();
    }
  });
}

/**
 * The light/dark/system switch. The choice is applied immediately and, for a
 * signed-in visitor, persisted through the profile endpoint.
 */
function enhanceThemeToggle() {
  document.querySelectorAll('[data-theme-set]').forEach((button) => {
    button.addEventListener('click', async () => {
      const theme = button.dataset.themeSet;
      const root = document.documentElement;

      root.classList.remove('is-light', 'is-dark', 'is-auto');
      root.classList.add(theme === 'light' ? 'is-light' : theme === 'dark' ? 'is-dark' : 'is-auto');
      root.dataset.theme = theme;

      document.querySelectorAll('[data-theme-set]').forEach((other) => {
        other.setAttribute('aria-pressed', String(other.dataset.themeSet === theme));
      });

      if (!config.signedIn) return;

      try {
        await request(`${config.base}/admin/profile/preferences`, {
          method: 'POST',
          body: JSON.stringify({ theme }),
        });
      } catch {
        // A preference that failed to save is not worth interrupting anyone
        // over; it still applies for this visit.
      }
    });
  });
}

/**
 * Marks the document as running inside a headset so the stylesheet can enlarge
 * targets. There is no media feature for this, so the WebXR capability is used
 * as the signal.
 */
async function detectImmersiveDevice() {
  if (!navigator.xr) return;

  try {
    if (await navigator.xr.isSessionSupported('immersive-vr')) {
      document.documentElement.classList.add('is-immersive');
    }
  } catch {
    // Blocked by permissions policy; treat as a normal browser.
  }
}

/**
 * The admin rail collapses to a drawer on a narrow screen.
 */
function enhanceAdminDrawer() {
  const toggle = document.querySelector('[data-admin-menu-toggle]');
  const rail = document.querySelector('.mtl-admin__rail');

  if (!toggle || !rail) return;

  toggle.addEventListener('click', () => {
    const open = rail.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded', String(open));
  });
}

/**
 * Registers the service worker that backs offline reading and the Android
 * wrapper.
 */
function registerServiceWorker() {
  if (!('serviceWorker' in navigator) || location.protocol !== 'https:') return;

  window.addEventListener('load', () => {
    navigator.serviceWorker.register(`${config.base || ''}/sw.js`, { scope: `${config.base || ''}/` })
      .catch((error) => console.warn('Service worker registration failed', error));
  });
}

// ---------------------------------------------------------------------------
// Lazy modules
// ---------------------------------------------------------------------------

async function bootGlobe() {
  const element = document.querySelector('[data-globe]');

  if (!element) return;

  try {
    const { Globe } = await import('./globe/index.js');

    const globe = new Globe(element, JSON.parse(element.dataset.globeConfig || '{}'));

    window.mtlGlobe = globe;

    await globe.init();

    // The fallback list doubles as a table of contents: clicking an entry flies
    // the camera to that stop instead of leaving the page.
    document.querySelectorAll('[data-globe-focus]').forEach((link) => {
      link.addEventListener('click', (event) => {
        const id = Number(link.dataset.globeFocus);

        if (globe.markerData?.some((marker) => marker.stop.id === id)) {
          event.preventDefault();
          globe.focusStop(id);
        }
      });
    });
  } catch (error) {
    console.error('The globe could not start', error);
    element.dataset.globeState = 'failed';
  }
}

async function bootEditor() {
  const editors = document.querySelectorAll('[data-editor]');

  if (editors.length === 0) return;

  const { MarkdownEditor } = await import('./editor/index.js');

  editors.forEach((element) => new MarkdownEditor(element).init());
}

async function bootUploader() {
  const zones = document.querySelectorAll('[data-uploader]');

  if (zones.length === 0) return;

  const { Uploader } = await import('./media/uploader.js');

  zones.forEach((element) => new Uploader(element).init());
}

async function bootMediaPicker() {
  if (!document.querySelector('[data-media-picker]')) return;

  const { MediaPicker } = await import('./media/picker.js');

  MediaPicker.attachAll();
}

async function bootGallery() {
  if (!document.querySelector('[data-gallery]')) return;

  const { Lightbox } = await import('./media/lightbox.js');

  Lightbox.attachAll();
}

async function bootSortables() {
  if (!document.querySelector('[data-sortable]')) return;

  const { Sortable } = await import('./ui/sortable.js');

  document.querySelectorAll('[data-sortable]').forEach((element) => new Sortable(element).init());
}

/**
 * Lets "63.985, -22.605" — the form every map app copies — be pasted straight
 * into the latitude field: the pair is split over both fields. Without this,
 * placing a stop means editing the clipboard by hand twice per stop, and stops
 * without coordinates never appear on the globe.
 */
function enhanceCoordinatePaste() {
  const latitude = document.querySelector('input[name="latitude"]');
  const longitude = document.querySelector('input[name="longitude"]');

  if (!latitude || !longitude) return;

  latitude.addEventListener('paste', (event) => {
    const text = event.clipboardData?.getData('text') ?? '';
    const match = /^\s*(-?\d+(?:[.,]\d+)?)[,;\s]+(-?\d+(?:[.,]\d+)?)\s*$/.exec(text);

    if (!match) return;

    event.preventDefault();

    // A decimal comma only appears when the pair is separated by something
    // else; after splitting, normalise it for the number input.
    latitude.value = match[1].replace(',', '.');
    longitude.value = match[2].replace(',', '.');

    latitude.dispatchEvent(new Event('input', { bubbles: true }));
    longitude.dispatchEvent(new Event('input', { bubbles: true }));
  });
}

/**
 * The "find coordinates" button on the stop form.
 *
 * Asks the server (own previous places first, then the geocoder), lists the
 * candidates, and a click on one fills latitude, longitude and — when still
 * empty — the country. Built with textContent throughout: the names come from
 * an external service and must render as text no matter what they contain.
 */
function enhanceGeocode() {
  const button = document.querySelector('[data-geocode]');

  if (!button) return;

  const queryField = document.querySelector(button.dataset.geocodeQuery);
  const latitude = document.querySelector(button.dataset.geocodeLatitude);
  const longitude = document.querySelector(button.dataset.geocodeLongitude);
  const country = document.querySelector(button.dataset.geocodeCountry);
  const list = document.querySelector('[data-geocode-results]');

  if (!queryField || !latitude || !longitude || !list) return;

  const search = async () => {
    const query = queryField.value.trim();

    if (query.length < 2) return;

    button.disabled = true;
    list.hidden = false;
    list.textContent = '';

    const busy = document.createElement('li');
    busy.className = 'mtl-geocode__status';
    busy.textContent = t('js.geocode.searching');
    list.append(busy);

    try {
      const payload = await request(
        `${button.dataset.geocodeEndpoint}?q=${encodeURIComponent(query)}`,
        { method: 'GET' },
      );

      list.textContent = '';

      if (payload.error) {
        const item = document.createElement('li');
        item.className = 'mtl-geocode__status';
        item.textContent = payload.error;
        list.append(item);
      }

      if ((payload.results ?? []).length === 0 && !payload.error) {
        const item = document.createElement('li');
        item.className = 'mtl-geocode__status';
        item.textContent = t('js.geocode.none');
        list.append(item);
        return;
      }

      for (const hit of payload.results) {
        const item = document.createElement('li');
        const pick = document.createElement('button');

        pick.type = 'button';
        pick.className = 'mtl-geocode__hit';

        const name = document.createElement('strong');
        name.textContent = hit.name + (hit.country ? ` (${hit.country})` : '');

        const detail = document.createElement('span');
        detail.className = 'mtl-muted';
        detail.textContent = hit.display || `${hit.latitude}, ${hit.longitude}`;

        pick.append(name, detail);

        pick.addEventListener('click', () => {
          latitude.value = String(hit.latitude);
          longitude.value = String(hit.longitude);

          if (country && country.value.trim() === '' && hit.country) {
            country.value = hit.country;
          }

          latitude.dispatchEvent(new Event('input', { bubbles: true }));
          longitude.dispatchEvent(new Event('input', { bubbles: true }));

          list.hidden = true;
          list.textContent = '';
        });

        item.append(pick);
        list.append(item);
      }
    } catch (error) {
      list.textContent = '';
      notify(error.message, 'negative');
    } finally {
      button.disabled = false;
    }
  };

  button.addEventListener('click', search);

  // Enter in the place field searches instead of submitting half a form.
  queryField.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      search();
    }
  });
}

// ---------------------------------------------------------------------------

function boot() {
  enhanceNotifications();
  enhanceConfirmations();
  enhanceCopyButtons();
  enhanceThemeToggle();
  enhanceAdminDrawer();
  enhanceCoordinatePaste();
  enhanceGeocode();

  detectImmersiveDevice();
  registerServiceWorker();

  bootGlobe();
  bootEditor();
  bootUploader();
  bootMediaPicker();
  bootGallery();
  bootSortables();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}
