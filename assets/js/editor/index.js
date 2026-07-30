/**
 * The travel-report editor.
 *
 * Modelled on Slack's composer: you type formatted text and see it formatted,
 * with a toolbar that reflects what the caret is inside. Markdown is the stored
 * truth, and a source view is always one click away — which is what stops a
 * rich editor from becoming a place your text goes in and never comes out of
 * the same shape.
 *
 * Markdown is rendered to HTML by the server, by the same renderer that
 * produces the published page. The client only ever converts the other way
 * (see serializer.js). One implementation, so the preview and the page cannot
 * drift apart.
 */

import { htmlToMarkdown } from './serializer.js';
import { request, notify, t } from '../lib/api.js';

const AUTOSAVE_DELAY = 4000;
const PREVIEW_DELAY = 400;

export class MarkdownEditor {
  /** @param {HTMLElement} element the [data-editor] wrapper */
  constructor(element) {
    this.element = element;

    /** The real form field. Everything else is an editing surface over it. */
    this.field = element.querySelector('[data-editor-field]');

    this.surface = element.querySelector('[data-editor-surface]');
    this.source = element.querySelector('[data-editor-source]');
    this.preview = element.querySelector('[data-editor-preview]');
    this.status = element.querySelector('[data-editor-status]');
    this.statusDot = element.querySelector('[data-editor-dot]');

    this.mode = element.dataset.editorMode || 'rich';
    this.autosaveUrl = element.dataset.editorAutosave || '';
    this.autosaveKey = element.dataset.editorKey || '';

    this.dirty = false;
    this.previewTimer = null;
    this.autosaveTimer = null;

    // The undo stack for source mode. The rich surface uses the browser's own.
    this.history = [];
    this.historyIndex = -1;
  }

  async init() {
    if (!this.field || !this.surface || !this.source) return;

    // The media picker inserts into whichever editor raised the request, so it
    // needs a way back from the element to the instance.
    this.element.mtlEditor = this;

    this.source.value = this.field.value;

    // The rich surface is seeded with the HTML the server already rendered for
    // this document, so opening an existing report costs no extra request.
    const initialHtml = this.element.querySelector('[data-editor-initial]')?.innerHTML ?? '';

    this.surface.innerHTML = initialHtml;

    // Without this Chromium separates paragraphs with <div>, which the
    // serialiser and the block-level commands both have to special-case. Asking
    // for <p> up front means the surface holds the same block elements the
    // server renders from the markdown.
    try {
      document.execCommand('defaultParagraphSeparator', false, 'p');
    } catch {
      // Firefox has never supported setting it and already defaults to <p>.
    }

    this.bindToolbar();
    this.bindSurface();
    this.bindSource();
    this.bindShortcuts();
    this.bindDropTarget();

    await this.setMode(this.mode, { initial: true });

    this.pushHistory();
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
      this.syncToField();
      this.dirty = false;
    });
  }

  // -------------------------------------------------------------------------
  // Modes
  // -------------------------------------------------------------------------

  async setMode(mode, { initial = false } = {}) {
    // Take the current content from whichever surface was active.
    if (!initial) {
      this.syncToField();
    }

    this.mode = mode;

    this.surface.hidden = mode !== 'rich';
    this.source.hidden = mode !== 'source';

    if (this.preview) {
      this.preview.hidden = mode !== 'preview';
    }

    this.element.querySelectorAll('[data-editor-mode-button]').forEach((button) => {
      button.setAttribute('aria-pressed', String(button.dataset.editorModeButton === mode));
    });

    // Formatting buttons only apply to the rich surface.
    this.element.querySelectorAll('[data-command]').forEach((button) => {
      button.disabled = mode === 'preview';
    });

    if (mode === 'rich' && !initial) {
      await this.refreshSurfaceFromMarkdown();
    }

    if (mode === 'source') {
      this.source.value = this.field.value;
      this.source.focus();
    }

    if (mode === 'preview') {
      await this.refreshPreview();
    }

    if (mode === 'rich') {
      this.surface.focus();
    }
  }

  /**
   * Re-renders the rich surface from the markdown, through the server.
   */
  async refreshSurfaceFromMarkdown() {
    const markdown = this.field.value;

    if (markdown.trim() === '') {
      this.surface.innerHTML = '';
      return;
    }

    try {
      // `editing` rather than `document`: the surface is converted back to
      // markdown on save, and the heading permalink anchors a document gets
      // would be written out as links the author never typed.
      const result = await request(window.MTL.routes.preview, {
        method: 'POST',
        body: JSON.stringify({ markdown, mode: 'editing' }),
      });

      this.surface.innerHTML = result.html ?? '';
    } catch (error) {
      // Falling back to source mode is better than showing an empty surface
      // and letting someone type over their report.
      notify(error.message, 'negative');
      await this.setMode('source', { initial: true });
    }
  }

  async refreshPreview() {
    if (!this.preview) return;

    this.syncToField();

    try {
      const result = await request(window.MTL.routes.preview, {
        method: 'POST',
        body: JSON.stringify({ markdown: this.field.value }),
      });

      this.preview.innerHTML = result.html ?? '';
    } catch (error) {
      this.preview.textContent = error.message;
    }
  }

  // -------------------------------------------------------------------------
  // Keeping the field current
  // -------------------------------------------------------------------------

  /** Writes the active surface's content into the form field. */
  syncToField() {
    if (!this.field) return;

    const markdown = this.mode === 'source' ? this.source.value : htmlToMarkdown(this.surface);

    if (markdown !== this.field.value) {
      this.field.value = markdown;
      this.field.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

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

    this.syncToField();

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
  // Surfaces
  // -------------------------------------------------------------------------

  bindSurface() {
    this.surface.setAttribute('contenteditable', 'true');
    this.surface.setAttribute('role', 'textbox');
    this.surface.setAttribute('aria-multiline', 'true');
    this.surface.dataset.placeholder = t('js.editor.placeholder');

    this.surface.addEventListener('input', () => {
      this.syncToField();
      this.markDirty();
      this.schedulePreview();
    });

    this.surface.addEventListener('keyup', () => this.refreshToolbarState());
    this.surface.addEventListener('mouseup', () => this.refreshToolbarState());

    // Pasted content is reduced to plain text and then re-rendered through the
    // server. Letting a browser insert arbitrary HTML from the clipboard is
    // how a rich editor ends up carrying styles, comments and scripts from
    // whatever application it was copied out of.
    this.surface.addEventListener('paste', (event) => {
      event.preventDefault();

      const text = event.clipboardData?.getData('text/plain') ?? '';

      if (text) {
        document.execCommand('insertText', false, text);
      }
    });
  }

  bindSource() {
    this.source.addEventListener('input', () => {
      this.field.value = this.source.value;
      this.markDirty();
      this.schedulePreview();
    });

    // Tab indents rather than leaving the field: in a markdown source view,
    // indentation is meaningful.
    this.source.addEventListener('keydown', (event) => {
      if (event.key !== 'Tab' || event.ctrlKey || event.metaKey) return;

      event.preventDefault();

      const { selectionStart, selectionEnd, value } = this.source;

      this.source.value = `${value.slice(0, selectionStart)}  ${value.slice(selectionEnd)}`;
      this.source.selectionStart = this.source.selectionEnd = selectionStart + 2;

      this.field.value = this.source.value;
      this.markDirty();
    });
  }

  schedulePreview() {
    if (!this.preview || this.preview.hidden) return;

    clearTimeout(this.previewTimer);
    this.previewTimer = setTimeout(() => this.refreshPreview(), PREVIEW_DELAY);
  }

  // -------------------------------------------------------------------------
  // Toolbar
  // -------------------------------------------------------------------------

  bindToolbar() {
    this.element.querySelectorAll('[data-command]').forEach((button) => {
      // mousedown rather than click: the default action of a button steals
      // focus from the contenteditable, losing the selection the command needs.
      button.addEventListener('mousedown', (event) => {
        event.preventDefault();
        this.runCommand(button.dataset.command, button.dataset.commandValue);
      });
    });

    this.element.querySelectorAll('[data-editor-mode-button]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        this.setMode(button.dataset.editorModeButton);
      });
    });
  }

  /**
   * Applies a formatting command.
   *
   * In rich mode this leans on execCommand. It is formally deprecated and
   * nothing has replaced it: every browser still implements it, it is the only
   * API that produces a correct undo entry in a contenteditable, and the
   * alternative is hand-rolled range surgery that breaks on the first nested
   * selection. The output is normalised by the serialiser anyway.
   */
  runCommand(command, value = '') {
    if (this.mode === 'source') {
      this.applySourceCommand(command, value);
      return;
    }

    if (this.mode !== 'rich') return;

    this.surface.focus();

    switch (command) {
      case 'bold':
      case 'italic':
      case 'strikeThrough':
      case 'insertUnorderedList':
      case 'insertOrderedList':
      case 'undo':
      case 'redo':
        document.execCommand(command, false);
        break;

      case 'heading':
        // The value is the markdown level, and the surface uses the same levels
        // the source does — see MarkdownOptions::editing().
        this.toggleBlock(`h${headingTag(value)}`);
        break;

      case 'quote':
        this.toggleBlock('blockquote');
        break;

      case 'code':
        this.wrapSelection('code');
        break;

      case 'codeBlock':
        this.insertCodeBlock();
        break;

      case 'link':
        this.insertLink();
        break;

      case 'divider':
        document.execCommand('insertHTML', false, '<hr><p><br></p>');
        break;

      case 'table':
        this.insertTable();
        break;

      case 'taskList':
        this.insertTaskItem();
        break;

      case 'image':
        this.element.dispatchEvent(new CustomEvent('mtl:pick-media', { bubbles: true }));
        break;

      default:
        break;
    }

    // The list and quote commands can nest a block inside the paragraph they
    // were called on, exactly as the typed shorthands do.
    this.normaliseBlocks();

    this.syncToField();
    this.markDirty();
    this.refreshToolbarState();
  }

  /**
   * Wraps or unwraps the selection in a tag that execCommand does not cover.
   */
  wrapSelection(tag) {
    const selection = window.getSelection();

    if (!selection || selection.rangeCount === 0) return;

    const range = selection.getRangeAt(0);

    // Already inside the tag: unwrap instead, so the button toggles.
    const existing = this.closestWithin(range.startContainer, tag);

    if (existing) {
      const parent = existing.parentNode;

      while (existing.firstChild) {
        parent.insertBefore(existing.firstChild, existing);
      }

      existing.remove();

      return;
    }

    if (range.collapsed) return;

    const element = document.createElement(tag);

    try {
      range.surroundContents(element);
    } catch {
      // surroundContents throws when the selection crosses element boundaries;
      // extracting and re-inserting handles that case.
      element.append(range.extractContents());
      range.insertNode(element);
    }

    selection.removeAllRanges();

    const after = document.createRange();
    after.selectNodeContents(element);
    selection.addRange(after);
  }

  toggleBlock(tag) {
    const current = this.currentBlockTag();

    document.execCommand('formatBlock', false, current === tag ? 'p' : tag);
  }

  insertCodeBlock() {
    const selection = window.getSelection();
    const text = selection?.toString() ?? '';

    document.execCommand(
      'insertHTML',
      false,
      `<pre><code>${escapeHtml(text || '')}</code></pre><p><br></p>`,
    );
  }

  insertLink() {
    const selection = window.getSelection();
    const text = selection?.toString() ?? '';

    const url = window.prompt(t('js.editor.link_url'), 'https://');

    if (!url) return;

    // Only schemes the renderer would accept anyway; refusing here means the
    // author finds out immediately rather than on the published page.
    if (!/^(https?:|mailto:|tel:|\/|#)/i.test(url)) {
      notify(t('js.common.error'), 'negative');
      return;
    }

    if (text) {
      document.execCommand('createLink', false, url);
    } else {
      document.execCommand('insertHTML', false, `<a href="${escapeHtml(url)}">${escapeHtml(url)}</a>`);
    }
  }

  insertTable() {
    const header = '<tr><th>&nbsp;</th><th>&nbsp;</th><th>&nbsp;</th></tr>';
    const row = '<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>';

    document.execCommand(
      'insertHTML',
      false,
      `<table><thead>${header}</thead><tbody>${row}${row}</tbody></table><p><br></p>`,
    );
  }

  insertTaskItem() {
    document.execCommand(
      'insertHTML',
      false,
      '<ul class="mtl-task-list"><li class="mtl-task-list-item"><input type="checkbox">&nbsp;</li></ul>',
    );
  }

  /**
   * Inserts a reference to a library item at the caret.
   *
   * Called by the media picker. The `mtl:media/` form keeps the report linked
   * to the library record rather than to one particular file URL.
   */
  insertMedia({ uuid, url, alt = '', caption = '' }) {
    if (this.mode === 'source') {
      this.insertAtCursor(`![${alt}](mtl:media/${uuid}${caption ? ` "${caption}"` : ''})`);
      return;
    }

    this.surface.focus();

    const figure = caption
      ? `<figure><img src="${escapeHtml(url)}" alt="${escapeHtml(alt)}" data-media-uuid="${escapeHtml(uuid)}"><figcaption>${escapeHtml(caption)}</figcaption></figure><p><br></p>`
      : `<img src="${escapeHtml(url)}" alt="${escapeHtml(alt)}" data-media-uuid="${escapeHtml(uuid)}"><p><br></p>`;

    document.execCommand('insertHTML', false, figure);

    this.syncToField();
    this.markDirty();
  }

  insertAtCursor(text) {
    const { selectionStart, selectionEnd, value } = this.source;

    this.source.value = `${value.slice(0, selectionStart)}${text}${value.slice(selectionEnd)}`;
    this.source.selectionStart = this.source.selectionEnd = selectionStart + text.length;

    this.field.value = this.source.value;
    this.markDirty();
  }

  /**
   * The markdown equivalents, for when the author is editing the source
   * directly and still wants the toolbar.
   */
  applySourceCommand(command, value = '') {
    const wrappers = {
      bold: '**',
      italic: '*',
      strikeThrough: '~~',
      code: '`',
    };

    const { selectionStart, selectionEnd, value: text } = this.source;
    const selected = text.slice(selectionStart, selectionEnd);

    let replacement = null;

    if (wrappers[command]) {
      const delimiter = wrappers[command];

      // Toggle: strip the delimiters when they are already there.
      replacement = selected.startsWith(delimiter) && selected.endsWith(delimiter)
        ? selected.slice(delimiter.length, -delimiter.length)
        : `${delimiter}${selected}${delimiter}`;
    } else if (command === 'heading') {
      replacement = `${'#'.repeat(Number(value) || 1)} ${selected}`;
    } else if (command === 'quote') {
      replacement = selected.split('\n').map((line) => `> ${line}`).join('\n');
    } else if (command === 'insertUnorderedList') {
      replacement = (selected || '').split('\n').map((line) => `- ${line}`).join('\n');
    } else if (command === 'insertOrderedList') {
      replacement = (selected || '').split('\n').map((line, i) => `${i + 1}. ${line}`).join('\n');
    } else if (command === 'taskList') {
      replacement = (selected || '').split('\n').map((line) => `- [ ] ${line}`).join('\n');
    } else if (command === 'codeBlock') {
      replacement = `\`\`\`\n${selected}\n\`\`\``;
    } else if (command === 'divider') {
      replacement = `${selected}\n\n---\n`;
    } else if (command === 'table') {
      replacement = '| A | B |\n| --- | --- |\n|  |  |\n';
    } else if (command === 'link') {
      const url = window.prompt(t('js.editor.link_url'), 'https://');
      if (!url) return;
      replacement = `[${selected || url}](${url})`;
    } else if (command === 'image') {
      this.element.dispatchEvent(new CustomEvent('mtl:pick-media', { bubbles: true }));
      return;
    }

    if (replacement === null) return;

    this.source.setRangeText(replacement, selectionStart, selectionEnd, 'end');
    this.source.focus();

    this.field.value = this.source.value;
    this.markDirty();
    this.schedulePreview();
  }

  // -------------------------------------------------------------------------
  // Toolbar state
  // -------------------------------------------------------------------------

  /**
   * Lights up the buttons matching the formatting under the caret, the way
   * Slack's composer does.
   */
  refreshToolbarState() {
    if (this.mode !== 'rich') return;

    const states = {
      bold: this.queryState('bold'),
      italic: this.queryState('italic'),
      strikeThrough: this.queryState('strikeThrough'),
      insertUnorderedList: this.queryState('insertUnorderedList'),
      insertOrderedList: this.queryState('insertOrderedList'),
      code: Boolean(this.closestWithin(window.getSelection()?.anchorNode, 'code')),
      quote: Boolean(this.closestWithin(window.getSelection()?.anchorNode, 'blockquote')),
    };

    this.element.querySelectorAll('[data-command]').forEach((button) => {
      const command = button.dataset.command;

      if (command === 'heading') {
        const tag = this.currentBlockTag();
        button.setAttribute('aria-pressed', String(tag === `h${headingTag(button.dataset.commandValue)}`));
        return;
      }

      if (command in states) {
        button.setAttribute('aria-pressed', String(states[command]));
      }
    });
  }

  queryState(command) {
    try {
      return document.queryCommandState(command);
    } catch {
      return false;
    }
  }

  currentBlockTag() {
    const node = window.getSelection()?.anchorNode;

    if (!node) return 'p';

    const element = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
    const block = element?.closest('h1, h2, h3, h4, h5, h6, blockquote, pre, p, li');

    return block ? block.tagName.toLowerCase() : 'p';
  }

  /** The nearest ancestor with the given tag, without leaving the surface. */
  closestWithin(node, tag) {
    if (!node) return null;

    const element = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
    const found = element?.closest(tag);

    return found && this.surface.contains(found) ? found : null;
  }

  // -------------------------------------------------------------------------
  // Keyboard
  // -------------------------------------------------------------------------

  bindShortcuts() {
    const shortcuts = {
      b: 'bold',
      i: 'italic',
      k: 'link',
      e: 'code',
    };

    const handler = (event) => {
      const modifier = event.metaKey || event.ctrlKey;

      if (!modifier) return;

      // Slack's own bindings, which is what anyone reaching for these expects.
      if (event.shiftKey && event.key.toLowerCase() === 'x') {
        event.preventDefault();
        this.runCommand('strikeThrough');
        return;
      }

      if (event.shiftKey && event.key.toLowerCase() === '7') {
        event.preventDefault();
        this.runCommand('insertOrderedList');
        return;
      }

      if (event.shiftKey && event.key.toLowerCase() === '8') {
        event.preventDefault();
        this.runCommand('insertUnorderedList');
        return;
      }

      if (event.shiftKey && event.key.toLowerCase() === '9') {
        event.preventDefault();
        this.runCommand('quote');
        return;
      }

      // Cmd/Ctrl+Enter submits, which is what a long form wants.
      if (event.key === 'Enter') {
        event.preventDefault();
        this.syncToField();
        this.field.form?.requestSubmit();
        return;
      }

      // Cmd/Ctrl+S saves rather than opening the browser's save dialog.
      if (event.key.toLowerCase() === 's') {
        event.preventDefault();
        this.syncToField();
        this.field.form?.requestSubmit();
        return;
      }

      const command = shortcuts[event.key.toLowerCase()];

      if (command) {
        event.preventDefault();
        this.runCommand(command);
      }
    };

    this.surface.addEventListener('keydown', handler);
    this.source.addEventListener('keydown', handler);

    // Markdown shorthand typed at the start of a line, as Slack and every
    // other rich composer does: "## " becomes a heading, "- " a list.
    this.surface.addEventListener('keydown', (event) => {
      if (event.key !== ' ') return;

      const node = window.getSelection()?.anchorNode;

      if (!node || node.nodeType !== Node.TEXT_NODE) return;

      const text = node.textContent?.slice(0, window.getSelection().anchorOffset) ?? '';

      const rules = [
        [/^(#{1,6})$/, (m) => this.toggleBlock(`h${headingTag(m[1].length)}`)],
        [/^[-*+]$/, () => document.execCommand('insertUnorderedList', false)],
        [/^\d+[.)]$/, () => document.execCommand('insertOrderedList', false)],
        [/^>$/, () => this.toggleBlock('blockquote')],
      ];

      for (const [pattern, action] of rules) {
        const match = pattern.exec(text);

        if (!match) continue;

        event.preventDefault();

        // The block command runs first, while the shorthand is still in the
        // block. Two reasons, both learned the hard way:
        //
        //   * execCommand needs a block with content. Applied to an empty one it
        //     silently formats the *previous* block instead, so "## " on a fresh
        //     line turned the paragraph above it into a heading.
        //   * the caret has to be inside the block being formatted, and the only
        //     way to keep it there is not to touch the DOM first.
        action(match);

        // Then remove the shorthand, through a range rather than by assigning
        // textContent. Assigning replaces the text node's data outright, which
        // drops the caret out of the block — after which every following
        // keystroke was appended to whatever block the caret landed in.
        this.removeShorthand(text);

        // insertUnorderedList wraps the new list inside the paragraph it was
        // called on, which is invalid and unserialisable.
        this.normaliseBlocks();

        this.syncToField();
        this.markDirty();
        return;
      }
    });
  }

  /**
   * Removes the markdown shorthand from the block the caret is in.
   *
   * Anchored on the text rather than on the caret, because the browser leaves
   * the caret in different places depending on the command: formatBlock keeps it
   * after the shorthand, while insertUnorderedList moves it to the start of the
   * new list item — in front of it. Deleting "the characters before the caret"
   * is therefore right half the time, and in the list case deletes nothing at
   * all and leaves a stray "-" for the next keystrokes to type around.
   */
  removeShorthand(shorthand) {
    const selection = window.getSelection();
    const anchor = selection?.anchorNode;

    if (!selection || anchor?.nodeType !== Node.TEXT_NODE) return;

    if (!(anchor.textContent ?? '').startsWith(shorthand)) return;

    const range = document.createRange();
    range.setStart(anchor, 0);
    range.setEnd(anchor, shorthand.length);
    range.deleteContents();
    range.collapse(true);

    selection.removeAllRanges();
    selection.addRange(range);
  }

  /**
   * Repairs block nesting that execCommand produces but HTML does not allow.
   *
   * A <p> cannot contain a list, a heading or a blockquote, yet
   * insertUnorderedList called on a paragraph produces exactly that. The
   * serialiser walks blocks, so a list hidden inside a paragraph came out as
   * run-together text with the list structure gone.
   *
   * Nodes are moved rather than re-created, so the text nodes the selection
   * points at survive and the caret does not jump.
   */
  normaliseBlocks() {
    const nested = this.surface.querySelectorAll(
      'p > ul, p > ol, p > blockquote, p > pre, p > h1, p > h2, p > h3, p > h4, p > h5, p > h6, p > hr, p > table',
    );

    nested.forEach((child) => {
      const paragraph = child.parentElement;

      if (!paragraph) return;

      paragraph.after(child);

      // Whatever is left of the paragraph is either empty or a stray <br>.
      if ((paragraph.textContent ?? '').trim() === '' && !paragraph.querySelector('img')) {
        paragraph.remove();
      }
    });
  }

  pushHistory() {
    this.history = this.history.slice(0, this.historyIndex + 1);
    this.history.push(this.field.value);
    this.historyIndex = this.history.length - 1;
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

/** Markdown heading level to the tag the surface uses for it. */
function headingTag(level) {
  return Math.min(6, Math.max(1, Number(level) || 1));
}

function escapeHtml(text) {
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
