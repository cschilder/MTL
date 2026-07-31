/**
 * The three helpers every other module needs: interface strings, the fetch
 * wrapper, and the notification strip.
 *
 * These live here rather than in app.js on purpose. app.js is the entry point
 * and is loaded with a cache-busting query string (`app.js?v=<hash>`). A module
 * is identified by its full URL, so a lazy module that imported `../app.js`
 * would pull in a *second*, separate copy of the entry point — and that copy
 * would run boot() again, mounting every component twice. Every toolbar command
 * would then fire twice, which is invisible for an idempotent one and silently
 * destructive for a toggle.
 *
 * Keeping the shared code in a leaf module means nothing ever imports the entry
 * point, and the problem cannot come back.
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
