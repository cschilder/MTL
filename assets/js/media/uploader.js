/**
 * The chunked uploader.
 *
 * Files are split into fixed-size parts and sent one at a time. That is what
 * makes a 300 MB video possible on shared hosting, where a single request would
 * hit post_max_size or the FastCGI timeout long before it finished — and it
 * means a dropped connection resumes instead of starting over.
 *
 * The server decides the chunk size (it knows the real PHP limits), so this
 * side simply obeys what /upload/init returns.
 */

import { request, notify, t } from '../lib/api.js';

/** Parts sent at once. Enough to fill a fast connection, few enough that a
 *  phone on mobile data does not stall on the first one. */
const CONCURRENCY = 3;

/** How many times a single part is retried before the upload is given up on. */
const MAX_RETRIES = 4;

export class Uploader {
  /** @param {HTMLElement} element the [data-uploader] drop zone */
  constructor(element) {
    this.element = element;

    this.input = element.querySelector('input[type="file"]');
    this.list = document.querySelector(element.dataset.uploaderList || '[data-upload-list]');

    this.target = {
      type: element.dataset.uploaderTarget || 'none',
      id: Number(element.dataset.uploaderTargetId || 0) || null,
    };

    this.queue = [];
    this.active = 0;
    this.cancelled = new Set();
  }

  init() {
    if (!this.input) return;

    this.input.addEventListener('change', () => {
      this.enqueue([...this.input.files]);
      // Reset so choosing the same file twice in a row still fires.
      this.input.value = '';
    });

    // Clicking anywhere in the zone opens the file dialog, except on the input
    // itself, which would then open it twice.
    this.element.addEventListener('click', (event) => {
      if (event.target !== this.input) this.input.click();
    });

    this.element.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        this.input.click();
      }
    });

    this.bindDragAndDrop();

    // The editor asks for files dropped onto it to be uploaded here.
    document.addEventListener('mtl:upload-files', (event) => {
      this.enqueue(event.detail.files, event.detail.editor);
    });

    if (!this.element.hasAttribute('tabindex')) {
      this.element.tabIndex = 0;
      this.element.setAttribute('role', 'button');
    }
  }

  bindDragAndDrop() {
    const stop = (event) => {
      event.preventDefault();
      event.stopPropagation();
    };

    // Without a document-level handler the browser navigates away when a file
    // is dropped anywhere but the zone, losing whatever was being edited.
    ['dragover', 'drop'].forEach((type) => {
      document.addEventListener(type, (event) => {
        if (event.dataTransfer?.types?.includes('Files')) event.preventDefault();
      });
    });

    ['dragenter', 'dragover'].forEach((type) => {
      this.element.addEventListener(type, (event) => {
        stop(event);
        this.element.classList.add('is-dragover');
      });
    });

    ['dragleave', 'dragend'].forEach((type) => {
      this.element.addEventListener(type, () => this.element.classList.remove('is-dragover'));
    });

    this.element.addEventListener('drop', (event) => {
      stop(event);
      this.element.classList.remove('is-dragover');
      this.enqueue([...(event.dataTransfer?.files ?? [])]);
    });
  }

  enqueue(files, editor = null) {
    for (const file of files) {
      const item = {
        file,
        editor,
        id: `u${Date.now()}${Math.random().toString(36).slice(2, 8)}`,
        row: null,
        uuid: null,
      };

      item.row = this.renderRow(item);
      this.queue.push(item);
    }

    this.pump();
  }

  pump() {
    while (this.active < CONCURRENCY && this.queue.length > 0) {
      const item = this.queue.shift();

      this.active += 1;

      this.upload(item)
        .catch((error) => this.fail(item, error))
        .finally(() => {
          this.active -= 1;
          this.pump();
        });
    }
  }

  // -------------------------------------------------------------------------
  // One file
  // -------------------------------------------------------------------------

  async upload(item) {
    const { file } = item;

    this.setProgress(item, 0, t('media.uploading'));

    const session = await request(window.MTL.routes.uploadInit, {
      method: 'POST',
      body: JSON.stringify({
        name: file.name,
        // A browser occasionally reports an empty type for an unusual
        // extension; the server identifies the real one from the bytes.
        mime: file.type || '',
        size: file.size,
        target_type: this.target.type,
        target_id: this.target.id,
      }),
    });

    item.uuid = session.uuid;

    const { chunk_bytes: chunkBytes, chunks_total: total } = session;
    const received = new Set(session.received ?? []);

    if (session.resumed && received.size > 0) {
      this.setProgress(item, (received.size / total) * 100, t('js.upload.progress', {
        done: received.size,
        total,
      }));
    }

    // Parts are sent in order rather than in parallel: on a mobile connection
    // three concurrent multi-megabyte bodies is slower than one, and the
    // server writes into a single file either way.
    for (let index = 0; index < total; index += 1) {
      if (this.cancelled.has(item.id)) {
        await this.abort(item);
        return;
      }

      if (received.has(index)) continue;

      const start = index * chunkBytes;
      const blob = file.slice(start, Math.min(start + chunkBytes, file.size));

      await this.sendChunk(item, index, blob);

      received.add(index);

      this.setProgress(item, (received.size / total) * 100, t('js.upload.progress', {
        done: received.size,
        total,
      }));
    }

    const result = await request(window.MTL.routes.uploadComplete, {
      method: 'POST',
      body: JSON.stringify({ uuid: item.uuid }),
    });

    this.succeed(item, result.media);
  }

  async sendChunk(item, index, blob) {
    let attempt = 0;

    for (;;) {
      try {
        const form = new FormData();

        form.append('uuid', item.uuid);
        form.append('index', String(index));
        form.append('chunk', blob, 'chunk');

        await request(window.MTL.routes.uploadChunk, { method: 'POST', body: form });

        return;
      } catch (error) {
        attempt += 1;

        // A rejected chunk (wrong size, expired session) will not succeed on a
        // retry; only a transport failure is worth repeating.
        if (attempt > MAX_RETRIES || (error.status && error.status < 500 && error.status !== 429)) {
          throw error;
        }

        // Back off, so a flaky connection is not hammered.
        await new Promise((resolve) => setTimeout(resolve, 2 ** attempt * 400));
      }
    }
  }

  async abort(item) {
    if (!item.uuid) return;

    try {
      await request(`${window.MTL.routes.uploadInit.replace('/init', '')}/${item.uuid}`, {
        method: 'DELETE',
      });
    } catch {
      // The sweeper removes abandoned uploads anyway.
    }

    item.row?.remove();
  }

  // -------------------------------------------------------------------------
  // Row rendering
  // -------------------------------------------------------------------------

  renderRow(item) {
    if (!this.list) return null;

    const row = document.createElement('div');
    row.className = 'mtl-upload';
    row.dataset.uploadId = item.id;

    row.innerHTML = `
      <img class="mtl-upload__thumb" alt="">
      <div class="mtl-upload__meta">
        <span class="mtl-upload__name"></span>
        <div class="mtl-upload__bar"><div class="mtl-upload__bar-fill"></div></div>
        <small data-upload-state></small>
      </div>
      <button type="button" class="p-button--base" data-upload-cancel></button>
    `;

    row.querySelector('.mtl-upload__name').textContent = item.file.name;
    row.querySelector('[data-upload-cancel]').textContent = t('js.upload.cancel');

    // A local preview while the bytes are still on their way up.
    if (item.file.type.startsWith('image/')) {
      const url = URL.createObjectURL(item.file);
      const image = row.querySelector('.mtl-upload__thumb');

      image.src = url;
      // Release the object URL once the browser has decoded it, or the page
      // holds every uploaded file in memory until it is closed.
      image.addEventListener('load', () => URL.revokeObjectURL(url), { once: true });
    } else {
      row.querySelector('.mtl-upload__thumb').remove();
    }

    row.querySelector('[data-upload-cancel]').addEventListener('click', () => {
      this.cancelled.add(item.id);
      this.abort(item);
    });

    this.list.prepend(row);

    return row;
  }

  setProgress(item, percent, label) {
    if (!item.row) return;

    item.row.querySelector('.mtl-upload__bar-fill').style.inlineSize = `${Math.round(percent)}%`;
    item.row.querySelector('[data-upload-state]').textContent = label;
  }

  succeed(item, media) {
    if (item.row) {
      item.row.classList.add('is-complete');
      item.row.querySelector('[data-upload-state]').textContent = t('js.upload.done');
      item.row.querySelector('[data-upload-cancel]')?.remove();

      if (media?.thumb) {
        const image = item.row.querySelector('.mtl-upload__thumb');
        if (image) image.src = media.thumb;
      }
    }

    // The report the file was dropped onto gets it inserted at the caret.
    if (item.editor && media) {
      item.editor.insertMedia({
        uuid: media.uuid,
        url: media.url,
        alt: media.alt ?? '',
      });
    }

    this.element.dispatchEvent(
      new CustomEvent('mtl:uploaded', { bubbles: true, detail: { media } }),
    );
  }

  fail(item, error) {
    if (this.cancelled.has(item.id)) return;

    console.error('Upload failed', error);

    if (item.row) {
      item.row.classList.add('is-failed');
      item.row.querySelector('[data-upload-state]').textContent = error.message || t('media.upload_failed');

      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'p-button--base';
      retry.textContent = t('js.upload.retry');

      retry.addEventListener('click', () => {
        item.row.classList.remove('is-failed');
        item.uuid = null;
        this.queue.push(item);
        this.pump();
      });

      item.row.querySelector('[data-upload-cancel]')?.replaceWith(retry);
    }

    notify(error.message || t('media.upload_failed'), 'negative');
  }
}
