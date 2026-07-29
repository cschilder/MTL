/**
 * The front-end entry point.
 *
 * Everything MTL does works without JavaScript: pages are rendered on the
 * server, forms submit normally, and the globe has a list of trips underneath
 * it. This file adds the layers that need a browser — and loads the heavy ones
 * only on the pages that use them, so a reader on a phone never downloads the
 * editor.
 */

const config = window.MTL ?? {};

const strings = config.strings ?? {};

/** Looks up an interface string sent from the server. */
export function t(key, replacements = {}) {
  let text = strings[key] ?? key;

  for (const [name, value] of Object.entries(replacements)) {
    text = text.replaceAll(`:${name}`, String(value));
  }

  return text;
}

/**
 * A fetch wrapper that carries the CSRF token and unwraps the error shape the
 * application uses.
 */
export async function request(url, options = {}) {
  const response = await fetch(url, {
    credentials: 'same-origin',
    ...options,
    headers: {
      Accept: 'application/json',
      'X-CSRF-Token': config.csrf ?? '',
      'X-Requested-With': 'XMLHttpRequest',
      ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
      ...options.headers,
    },
  });

  let payload = null;

  try {
    payload = await response.json();
  } catch {
    payload = null;
  }

  if (!response.ok) {
    const error = new Error(payload?.error ?? `Request failed (${response.status})`);
    error.status = response.status;
    error.errors = payload?.errors ?? {};
    throw error;
  }

  return payload;
}

/** Shows a transient message in the notification strip. */
export function notify(message, type = 'information') {
  let container = document.querySelector('.mtl-toasts');

  if (!container) {
    container = document.createElement('div');
    container.className = 'mtl-toasts';
    container.setAttribute('role', 'status');
    container.setAttribute('aria-live', 'polite');
    document.body.append(container);
  }

  const toast = document.createElement('div');
  toast.className = `p-notification--${type}`;
  toast.dataset.mtlToast = '';
  toast.innerHTML =
    '<div class="p-notification__content"><p class="p-notification__message"></p></div>' +
    '<button class="p-notification__close" data-mtl-dismiss></button>';

  toast.querySelector('.p-notification__message').textContent = message;
  toast.querySelector('[data-mtl-dismiss]').textContent = t('app.close', {}) || 'Close';

  container.append(toast);

  // Errors stay until dismissed; anything else clears itself.
  if (type !== 'negative') {
    setTimeout(() => toast.remove(), 6000);
  }

  return toast;
}

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

// ---------------------------------------------------------------------------

function boot() {
  enhanceNotifications();
  enhanceConfirmations();
  enhanceCopyButtons();
  enhanceThemeToggle();
  enhanceAdminDrawer();

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
