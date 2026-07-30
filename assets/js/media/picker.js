/**
 * The media picker dialog.
 *
 * Opened from the editor's image button and from any field that holds a media
 * reference (a trip's cover, a step's lead photo). Built on <dialog>, which
 * gives focus trapping, Escape to close and the top-layer stacking for free —
 * all of which a hand-rolled modal gets wrong on a phone and in a headset.
 */

import { request, t } from '../lib/api.js';

export class MediaPicker {
  static instance = null;

  /** Wires up every trigger on the page. */
  static attachAll() {
    const dialog = document.querySelector('[data-media-picker]');

    if (!dialog) return;

    MediaPicker.instance = new MediaPicker(dialog);
    MediaPicker.instance.init();
  }

  /** @param {HTMLDialogElement} dialog */
  constructor(dialog) {
    this.dialog = dialog;
    this.grid = dialog.querySelector('[data-picker-grid]');
    this.search = dialog.querySelector('[data-picker-search]');
    this.confirmButton = dialog.querySelector('[data-picker-confirm]');
    this.countLabel = dialog.querySelector('[data-picker-count]');

    this.selection = new Set();
    this.multiple = false;
    this.resolve = null;
    this.page = 1;
    this.loading = false;
    this.exhausted = false;
    this.items = new Map();
  }

  init() {
    this.dialog.querySelectorAll('[data-picker-close]').forEach((button) => {
      button.addEventListener('click', () => this.close(null));
    });

    this.confirmButton?.addEventListener('click', () => this.close(this.selectedItems()));

    // A click on the backdrop closes; a click inside must not.
    this.dialog.addEventListener('click', (event) => {
      if (event.target === this.dialog) this.close(null);
    });

    this.dialog.addEventListener('close', () => {
      if (this.resolve) {
        this.resolve(null);
        this.resolve = null;
      }
    });

    let searchTimer = null;

    this.search?.addEventListener('input', () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => this.reload(), 300);
    });

    // Infinite scroll inside the dialog body.
    const body = this.dialog.querySelector('.mtl-picker__body');

    body?.addEventListener('scroll', () => {
      if (this.loading || this.exhausted) return;

      if (body.scrollTop + body.clientHeight >= body.scrollHeight - 200) {
        this.loadPage();
      }
    });

    this.grid?.addEventListener('click', (event) => {
      const tile = event.target.closest('[data-picker-item]');

      if (tile) this.toggle(tile.dataset.pickerItem);
    });

    // Keyboard: the grid is a listbox, so arrows move and Enter chooses.
    this.grid?.addEventListener('keydown', (event) => {
      const tile = event.target.closest('[data-picker-item]');

      if (!tile) return;

      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        this.toggle(tile.dataset.pickerItem);
      }
    });

    // Triggers anywhere on the page.
    document.addEventListener('click', (event) => {
      const trigger = event.target.closest('[data-pick-media]');

      if (trigger) {
        event.preventDefault();
        this.handleTrigger(trigger);
      }
    });

    // The editor's image button.
    document.addEventListener('mtl:pick-media', async (event) => {
      const editor = event.target.closest('[data-editor]');
      const chosen = await this.open({ multiple: true });

      if (!chosen || !editor) return;

      const instance = editor.mtlEditor;

      for (const media of chosen) {
        instance?.insertMedia({
          uuid: media.uuid,
          url: media.url,
          alt: media.alt ?? '',
          caption: media.title ?? '',
        });
      }
    });

    // A newly uploaded file should appear in the picker without a reload.
    document.addEventListener('mtl:uploaded', (event) => {
      const media = event.detail?.media;

      if (media && this.grid) {
        this.items.set(String(media.id), media);
        this.grid.prepend(this.renderTile(media));
      }
    });
  }

  /**
   * Handles a trigger that fills a hidden field and a preview image, which is
   * how the cover-image controls work.
   */
  async handleTrigger(trigger) {
    const chosen = await this.open({ multiple: trigger.dataset.pickMedia === 'multiple' });

    if (!chosen || chosen.length === 0) return;

    const field = document.querySelector(trigger.dataset.pickTarget || '');
    const preview = document.querySelector(trigger.dataset.pickPreview || '');

    if (field) {
      field.value = trigger.dataset.pickMedia === 'multiple'
        ? chosen.map((m) => m.id).join(',')
        : String(chosen[0].id);

      field.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (preview) {
      preview.src = chosen[0].thumb ?? chosen[0].url;
      preview.hidden = false;
    }
  }

  /**
   * Opens the dialog and resolves with the chosen media, or null on cancel.
   *
   * @returns {Promise<Array<object>|null>}
   */
  open({ multiple = false } = {}) {
    this.multiple = multiple;
    this.selection.clear();
    this.updateCount();

    this.dialog.showModal();

    if (this.items.size === 0) this.reload();

    return new Promise((resolve) => {
      this.resolve = resolve;
    });
  }

  close(value) {
    const resolve = this.resolve;
    this.resolve = null;

    if (this.dialog.open) this.dialog.close();

    resolve?.(value);
  }

  reload() {
    this.page = 1;
    this.exhausted = false;
    this.items.clear();

    if (this.grid) this.grid.textContent = '';

    this.loadPage();
  }

  async loadPage() {
    if (this.loading || this.exhausted) return;

    this.loading = true;

    try {
      const parameters = new URLSearchParams({
        page: String(this.page),
        q: this.search?.value ?? '',
        kind: this.dialog.dataset.pickerKind || '',
      });

      const result = await request(`${window.MTL.routes.media}?${parameters}`);

      const media = result.data ?? [];

      for (const item of media) {
        this.items.set(String(item.id), item);
        this.grid?.append(this.renderTile(item));
      }

      this.exhausted = this.page >= (result.pages ?? 1);
      this.page += 1;
    } catch (error) {
      console.error('Could not load media', error);
    } finally {
      this.loading = false;
    }
  }

  renderTile(media) {
    const tile = document.createElement('button');

    tile.type = 'button';
    tile.className = 'mtl-media-tile';
    tile.dataset.pickerItem = String(media.id);
    tile.setAttribute('role', 'option');
    tile.setAttribute('aria-selected', 'false');

    const image = document.createElement('img');

    image.src = media.thumb ?? media.url;
    image.alt = media.alt || media.title || '';
    image.loading = 'lazy';

    if (media.color) image.style.backgroundColor = media.color;

    tile.append(image);

    if (media.kind === 'video') {
      const badge = document.createElement('span');
      badge.className = 'mtl-media-tile__badge';
      badge.textContent = media.duration || 'video';
      tile.append(badge);
    }

    const check = document.createElement('span');
    check.className = 'mtl-media-tile__check';
    check.textContent = '✓';
    tile.append(check);

    return tile;
  }

  toggle(id) {
    if (!this.multiple) {
      this.selection.clear();

      this.grid?.querySelectorAll('[aria-selected="true"]').forEach((tile) => {
        tile.setAttribute('aria-selected', 'false');
      });
    }

    const tile = this.grid?.querySelector(`[data-picker-item="${CSS.escape(id)}"]`);

    if (this.selection.has(id)) {
      this.selection.delete(id);
      tile?.setAttribute('aria-selected', 'false');
    } else {
      this.selection.add(id);
      tile?.setAttribute('aria-selected', 'true');
    }

    this.updateCount();

    // With a single-selection picker, choosing is the whole interaction.
    if (!this.multiple && this.selection.size === 1) {
      this.close(this.selectedItems());
    }
  }

  updateCount() {
    if (this.countLabel) {
      this.countLabel.textContent = t('media.selected', { count: this.selection.size });
    }

    if (this.confirmButton) {
      this.confirmButton.disabled = this.selection.size === 0;
    }
  }

  selectedItems() {
    return [...this.selection].map((id) => this.items.get(id)).filter(Boolean);
  }
}
