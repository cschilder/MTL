/**
 * Reorderable lists — the steps of a trip, the photos in a gallery.
 *
 * Pointer dragging for a mouse or a finger, and buttons plus arrow keys for
 * everything else. The keyboard path is not a fallback: a drag interaction is
 * unusable with a headset's laser pointer, and reordering is a core editing
 * task, so both have to work properly.
 */

import { request, notify, t } from '../lib/api.js';

export class Sortable {
  /** @param {HTMLElement} element the [data-sortable] list */
  constructor(element) {
    this.element = element;
    this.endpoint = element.dataset.sortableEndpoint || '';
    this.field = element.dataset.sortableField || 'order';

    this.dragged = null;
    this.saveTimer = null;
  }

  init() {
    this.element.querySelectorAll('[data-sortable-item]').forEach((item) => this.prepare(item));

    this.element.addEventListener('dragover', (event) => this.handleDragOver(event));
    this.element.addEventListener('drop', (event) => event.preventDefault());
  }

  prepare(item) {
    const handle = item.querySelector('[data-sortable-handle]') ?? item;

    handle.setAttribute('draggable', 'true');

    handle.addEventListener('dragstart', (event) => {
      this.dragged = item;
      item.classList.add('is-dragging');

      event.dataTransfer.effectAllowed = 'move';
      // Firefox refuses to start a drag without payload.
      event.dataTransfer.setData('text/plain', item.dataset.sortableItem ?? '');
    });

    handle.addEventListener('dragend', () => {
      item.classList.remove('is-dragging');
      this.element.querySelectorAll('.is-drop-target').forEach((node) => node.classList.remove('is-drop-target'));

      this.dragged = null;
      this.scheduleSave();
    });

    item.querySelector('[data-sortable-up]')?.addEventListener('click', () => this.move(item, -1));
    item.querySelector('[data-sortable-down]')?.addEventListener('click', () => this.move(item, 1));

    item.addEventListener('keydown', (event) => {
      // Alt keeps the plain arrows free for moving the focus.
      if (!event.altKey) return;

      if (event.key === 'ArrowUp') {
        event.preventDefault();
        this.move(item, -1);
      }

      if (event.key === 'ArrowDown') {
        event.preventDefault();
        this.move(item, 1);
      }
    });
  }

  handleDragOver(event) {
    if (!this.dragged) return;

    event.preventDefault();

    const target = event.target.closest('[data-sortable-item]');

    if (!target || target === this.dragged) return;

    const box = target.getBoundingClientRect();
    const afterMidpoint = event.clientY > box.top + box.height / 2;

    this.element.querySelectorAll('.is-drop-target').forEach((node) => node.classList.remove('is-drop-target'));
    target.classList.add('is-drop-target');

    // Inserting on the far side of the midpoint is what makes dragging past an
    // item feel like it lands where you expect.
    target.parentNode.insertBefore(this.dragged, afterMidpoint ? target.nextSibling : target);
  }

  move(item, offset) {
    const items = [...this.element.querySelectorAll('[data-sortable-item]')];
    const index = items.indexOf(item);
    const target = index + offset;

    if (target < 0 || target >= items.length) return;

    if (offset < 0) {
      items[target].before(item);
    } else {
      items[target].after(item);
    }

    // Keep the focus on the thing that moved, so repeated presses work.
    (item.querySelector('[data-sortable-up], [data-sortable-down]') ?? item).focus();

    this.announce(item, target + 1, items.length);
    this.scheduleSave();
  }

  /**
   * Tells a screen reader where the item ended up; without this the reorder is
   * silent and there is no way to tell whether it worked.
   */
  announce(item, position, total) {
    let live = this.element.querySelector('[data-sortable-live]');

    if (!live) {
      live = document.createElement('p');
      live.className = 'mtl-visually-hidden';
      live.dataset.sortableLive = '';
      live.setAttribute('aria-live', 'polite');
      this.element.append(live);
    }

    const label = item.dataset.sortableLabel || item.textContent.trim().slice(0, 60);

    live.textContent = `${label}: ${position} / ${total}`;
  }

  scheduleSave() {
    if (!this.endpoint) return;

    // A burst of keyboard moves becomes one request.
    clearTimeout(this.saveTimer);
    this.saveTimer = setTimeout(() => this.save(), 600);
  }

  async save() {
    const order = [...this.element.querySelectorAll('[data-sortable-item]')]
      .map((item) => item.dataset.sortableItem)
      .filter(Boolean);

    try {
      await request(this.endpoint, {
        method: 'POST',
        body: JSON.stringify({ [this.field]: order }),
      });

      this.element.dispatchEvent(new CustomEvent('mtl:reordered', { bubbles: true, detail: { order } }));
    } catch (error) {
      notify(error.message || t('js.common.error'), 'negative');
    }
  }
}
