/**
 * The photo lightbox.
 *
 * Built on <dialog> for focus trapping and Escape handling, with swipe
 * navigation on touch and arrow keys everywhere else.
 */

export class Lightbox {
  static instance = null;

  static attachAll() {
    Lightbox.instance ??= new Lightbox();
    Lightbox.instance.init();
  }

  constructor() {
    this.dialog = null;
    this.items = [];
    this.index = 0;
  }

  init() {
    // One delegated listener rather than one per image: galleries are rendered
    // on many pages and can be added by the editor after load.
    document.addEventListener('click', (event) => {
      const trigger = event.target.closest('[data-gallery] img, [data-gallery] [data-lightbox]');

      if (!trigger) return;

      const gallery = trigger.closest('[data-gallery]');

      event.preventDefault();

      this.items = [...gallery.querySelectorAll('img, [data-lightbox]')].map((node) => ({
        src: node.dataset.full || node.currentSrc || node.src,
        alt: node.alt ?? '',
        caption: node.closest('figure')?.querySelector('figcaption')?.textContent ?? '',
        video: node.dataset.video || '',
      }));

      this.index = this.items.findIndex((item) => item.src === (trigger.dataset.full || trigger.currentSrc || trigger.src));

      this.open(Math.max(0, this.index));
    });
  }

  ensureDialog() {
    if (this.dialog) return this.dialog;

    const dialog = document.createElement('dialog');
    dialog.className = 'mtl-lightbox';

    dialog.innerHTML = `
      <div class="mtl-lightbox__stage" data-stage></div>
      <button type="button" class="mtl-lightbox__close" data-close aria-label="Close">✕</button>
      <button type="button" class="mtl-lightbox__nav mtl-lightbox__nav--prev" data-prev aria-label="Previous">‹</button>
      <button type="button" class="mtl-lightbox__nav mtl-lightbox__nav--next" data-next aria-label="Next">›</button>
      <p class="mtl-lightbox__caption" data-caption></p>
    `;

    dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
    dialog.querySelector('[data-prev]').addEventListener('click', () => this.show(this.index - 1));
    dialog.querySelector('[data-next]').addEventListener('click', () => this.show(this.index + 1));

    dialog.addEventListener('click', (event) => {
      // Clicking the backdrop or the empty space around the photo closes it.
      if (event.target === dialog || event.target.dataset.stage !== undefined) {
        dialog.close();
      }
    });

    dialog.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowLeft') this.show(this.index - 1);
      if (event.key === 'ArrowRight') this.show(this.index + 1);
    });

    // Swipe between photos on touch.
    let startX = 0;

    dialog.addEventListener('pointerdown', (event) => { startX = event.clientX; });

    dialog.addEventListener('pointerup', (event) => {
      const distance = event.clientX - startX;

      if (Math.abs(distance) > 60) {
        this.show(this.index + (distance < 0 ? 1 : -1));
      }
    });

    document.body.append(dialog);
    this.dialog = dialog;

    return dialog;
  }

  open(index) {
    const dialog = this.ensureDialog();

    this.show(index);

    if (!dialog.open) dialog.showModal();
  }

  show(index) {
    if (this.items.length === 0) return;

    // Wrapping round is what a gallery is expected to do.
    this.index = (index + this.items.length) % this.items.length;

    const item = this.items[this.index];
    const stage = this.dialog.querySelector('[data-stage]');

    stage.textContent = '';

    if (item.video) {
      const video = document.createElement('video');
      video.src = item.video;
      video.controls = true;
      video.autoplay = true;
      video.playsInline = true;
      stage.append(video);
    } else {
      const image = document.createElement('img');
      image.src = item.src;
      image.alt = item.alt;
      stage.append(image);
    }

    this.dialog.querySelector('[data-caption]').textContent = item.caption;

    const single = this.items.length < 2;

    this.dialog.querySelector('[data-prev]').hidden = single;
    this.dialog.querySelector('[data-next]').hidden = single;

    // Warm the neighbours so moving through a gallery is instant.
    for (const offset of [1, -1]) {
      const neighbour = this.items[(this.index + offset + this.items.length) % this.items.length];

      if (neighbour && !neighbour.video) {
        const preload = new Image();
        preload.src = neighbour.src;
      }
    }
  }
}
