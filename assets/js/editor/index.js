/**
 * The travel-report editor: StackEdit, hosted by this site.
 *
 * Markdown is the only truth. The form's textarea holds it, StackEdit edits
 * it full-screen, and every change comes straight back over postMessage —
 * nothing is converted to HTML and back on the way, so what the author wrote
 * is what gets saved. On the form itself the report shows as a rendered
 * preview with one button; there is no second editor to switch to.
 *
 * The StackEdit build in assets/stackedit is this site's own (see
 * tools/stackedit/README.md): same origin, full menus, and an image button
 * that opens this site's photo library instead of asking for a URL.
 */

import { Stackedit } from './stackedit.js';
import { request, notify, t } from '../lib/api.js';

const AUTOSAVE_DELAY = 4000;

/** How long StackEdit gets to report in before the sheet explains itself. */
const READY_TIMEOUT = 10000;

export class MarkdownEditor {
  /** @param {HTMLElement} element the [data-editor] wrapper */
  constructor(element) {
    this.element = element;

    /** The real form field. */
    this.field = element.querySelector('[data-editor-field]');

    this.preview = element.querySelector('[data-editor-preview]');
    this.openButton = element.querySelector('[data-editor-open]');
    this.status = element.querySelector('[data-editor-status]');
    this.statusDot = element.querySelector('[data-editor-dot]');

    this.url = element.dataset.editorStackedit || '';
    this.autosaveUrl = element.dataset.editorAutosave || '';
    this.autosaveKey = element.dataset.editorKey || '';

    this.dirty = false;
    this.autosaveTimer = null;
    this.readyTimer = null;

    /** The StackEdit overlay, created on first use. */
    this.stackedit = null;
  }

  init() {
    if (!this.field || !this.preview) return;

    // The media picker and the uploader insert into whichever editor raised
    // the request, so they need a way back from the element to the instance.
    this.element.mtlEditor = this;

    // With script running, the textarea gives way to the preview and the
    // button. It stays in the form (hidden) as the field that is submitted.
    this.field.hidden = true;
    this.preview.hidden = false;

    if (this.openButton) {
      this.openButton.hidden = false;
      this.openButton.addEventListener('click', () => this.open());
    }

    // The rendered report is the biggest target on a phone; tapping it opens
    // the editor too. Links inside it still work as links.
    this.preview.addEventListener('click', (event) => {
      if (event.target.closest('a')) return;
      this.open();
    });

    this.bindDropTarget();
    this.updateStatus('saved');

    // Warn before losing unsaved work. The browser replaces the message with
    // its own text; the value only has to be non-empty.
    window.addEventListener('beforeunload', (event) => {
      if (this.dirty) {
        event.preventDefault();
        event.returnValue = t('js.editor.unsaved');
      }
    });

    // Submitting the form is the moment the work is safe.
    this.field.form?.addEventListener('submit', () => {
      this.dirty = false;
    });
  }

  // -------------------------------------------------------------------------
  // StackEdit
  // -------------------------------------------------------------------------

  /**
   * Opens the report in StackEdit, full-screen over the form.
   *
   * StackEdit talks back over postMessage: every edit lands in the form
   * field immediately, so closing the sheet (its ✓ button) and saving the
   * form is the whole workflow — there is no separate export step.
   */
  open() {
    if (!this.url) {
      notify(t('js.common.error'), 'negative');
      return;
    }

    if (!this.stackedit) {
      this.stackedit = new Stackedit({ url: this.url });

      this.stackedit.on('fileChange', (file) => {
        const text = file?.content?.text ?? '';

        if (text === this.field.value) return;

        this.field.value = text;
        this.field.dispatchEvent(new Event('input', { bubbles: true }));
        this.markDirty();
      });

      this.stackedit.on('ready', () => {
        clearTimeout(this.readyTimer);
        document.querySelector('.mtl-stackedit-blocked')?.remove();
      });

      this.stackedit.on('close', () => {
        clearTimeout(this.readyTimer);
        this.refreshPreview();
      });

      // StackEdit's own image button, wired to this site's photo library.
      // The picker answers through insertMedia().
      this.stackedit.on('pickImage', () => {
        this.element.dispatchEvent(new CustomEvent('mtl:pick-media', { bubbles: true }));
      });
    }

    this.stackedit.openFile({
      name: document.title || 'MTL',
      content: { text: this.field.value },
    });

    // If StackEdit never reports in (a blocked script, a broken connection),
    // the sheet would stay an empty white page. After a patient wait it says
    // what happened and what to do instead.
    clearTimeout(this.readyTimer);
    this.readyTimer = setTimeout(() => {
      const box = document.querySelector('.stackedit-iframe-container');

      if (!box || box.querySelector('.mtl-stackedit-blocked')) return;

      const note = document.createElement('div');
      note.className = 'mtl-stackedit-blocked';
      note.textContent = t('js.editor.stackedit_blocked');
      box.appendChild(note);
    }, READY_TIMEOUT);
  }

  /**
   * Inserts a photo or video from the library.
   *
   * Called by the media picker and by the uploader. With StackEdit open the
   * markdown goes to the caret inside StackEdit — which is where the author
   * is — and otherwise to the end of the report.
   */
  insertMedia({ url, alt = '', caption = '' }) {
    const text = `![${alt}](${url}${caption ? ` "${caption.replace(/"/g, '\\"')}"` : ''})`;

    if (this.stackedit?.isOpen()) {
      // StackEdit pads it onto a line of its own at the caret.
      this.stackedit.post('insertText', { text });
      return;
    }

    const current = this.field.value.replace(/\s+$/, '');

    this.field.value = `${current}${current === '' ? '' : '\n\n'}${text}\n`;
    this.markDirty();
    this.refreshPreview();
  }

  // -------------------------------------------------------------------------
  // Preview
  // -------------------------------------------------------------------------

  /** Re-renders the preview from the markdown, through the server. */
  async refreshPreview() {
    if (!this.preview) return;

    const markdown = this.field.value;

    if (markdown.trim() === '') {
      this.preview.innerHTML = '';
      return;
    }

    try {
      const result = await request(window.MTL.routes.preview, {
        method: 'POST',
        body: JSON.stringify({ markdown, mode: 'editing' }),
      });

      this.preview.innerHTML = result.html ?? '';
    } catch {
      // The preview is a convenience; the report itself is safe in the field.
    }
  }

  // -------------------------------------------------------------------------
  // Saving
  // -------------------------------------------------------------------------

  markDirty() {
    this.dirty = true;
    this.updateStatus('dirty');

    clearTimeout(this.autosaveTimer);

    if (this.autosaveUrl) {
      this.autosaveTimer = setTimeout(() => this.autosave(), AUTOSAVE_DELAY);
    }
  }

  async autosave() {
    if (!this.autosaveUrl || !this.dirty) return;

    try {
      await request(this.autosaveUrl, {
        method: 'POST',
        body: JSON.stringify({ key: this.autosaveKey, markdown: this.field.value }),
      });

      this.dirty = false;

      this.updateStatus('saved', t('js.editor.autosaved', {
        time: new Date().toLocaleTimeString(window.MTL?.locale ?? 'nl', { hour: '2-digit', minute: '2-digit' }),
      }));
    } catch {
      // A failed autosave is not worth an alert: the work is still in the form
      // and will go with the next real save.
      this.updateStatus('error');
    }
  }

  updateStatus(state, message = '') {
    if (this.statusDot) {
      this.statusDot.className = `mtl-editor__status-dot is-${state}`;
    }

    if (!this.status) return;

    this.status.textContent = message || {
      saved: t('app.saved'),
      dirty: t('js.editor.unsaved'),
      error: t('js.common.error'),
    }[state] || '';
  }

  // -------------------------------------------------------------------------
  // Drag and drop
  // -------------------------------------------------------------------------

  bindDropTarget() {
    const stop = (event) => {
      event.preventDefault();
      event.stopPropagation();
    };

    ['dragenter', 'dragover'].forEach((type) => {
      this.element.addEventListener(type, (event) => {
        if (!event.dataTransfer?.types?.includes('Files')) return;

        stop(event);
        this.element.classList.add('is-dropping');
      });
    });

    ['dragleave', 'drop'].forEach((type) => {
      this.element.addEventListener(type, (event) => {
        if (type === 'drop') stop(event);
        this.element.classList.remove('is-dropping');
      });
    });

    this.element.addEventListener('drop', (event) => {
      const files = [...(event.dataTransfer?.files ?? [])];

      if (files.length === 0) return;

      // The uploader owns the transfer; the editor only says where the result
      // should end up.
      this.element.dispatchEvent(
        new CustomEvent('mtl:upload-files', { bubbles: true, detail: { files, editor: this } }),
      );
    });
  }
}
