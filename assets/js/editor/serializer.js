/**
 * Turns the rich editing surface back into markdown.
 *
 * The direction that matters is the other one — markdown to HTML — and that is
 * done on the server, by the same renderer that produces the published page.
 * So there is exactly one markdown implementation, and the preview can never
 * disagree with what a reader sees.
 *
 * This file handles the return trip. It only has to understand the tags the
 * server's renderer emits plus the handful a browser inserts while editing
 * (<b>, <i>, <div>, <font>), which is a small, closed set.
 */

/** Characters that would otherwise start a construct at the head of a line. */
const BLOCK_ESCAPES = /^(\s*)([-*+>#]|\d+[.)])(\s)/;

export function htmlToMarkdown(root) {
  const blocks = [];

  for (const node of root.childNodes) {
    const markdown = serialiseBlock(node, { depth: 0 });

    if (markdown !== null && markdown.trim() !== '') {
      blocks.push(markdown);
    }
  }

  return blocks
    .join('\n\n')
    // Collapse the runs of blank lines that nested blocks tend to leave.
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

/**
 * @param {Node} node
 * @param {{depth:number}} context
 * @returns {string|null}
 */
function serialiseBlock(node, context) {
  if (node.nodeType === Node.TEXT_NODE) {
    const text = node.textContent ?? '';

    // Loose text directly under the root is a paragraph.
    return text.trim() === '' ? null : escapeLine(text.trim());
  }

  if (node.nodeType !== Node.ELEMENT_NODE) return null;

  const element = /** @type {HTMLElement} */ (node);
  const tag = element.tagName.toLowerCase();

  switch (tag) {
    case 'h1': case 'h2': case 'h3':
    case 'h4': case 'h5': case 'h6': {
      // The server renders a document's `#` as an <h2>, so the level is shifted
      // back on the way out. Without this every save would demote the headings
      // one more step.
      const level = Math.max(1, Number(tag[1]) - 1);
      return `${'#'.repeat(level)} ${serialiseInline(element).trim()}`;
    }

    case 'p':
      return serialiseInline(element).trim() || null;

    case 'br':
      return null;

    case 'hr':
      return '---';

    case 'blockquote': {
      const inner = serialiseChildren(element, context);

      return inner
        .split('\n')
        .map((line) => (line === '' ? '>' : `> ${line}`))
        .join('\n');
    }

    case 'pre': {
      const code = element.querySelector('code');
      const text = (code ?? element).textContent ?? '';

      const className = code?.className ?? '';
      const language = /language-([\w+#.-]+)/.exec(className)?.[1] ?? '';

      // A fence long enough to survive backticks in the code itself.
      const longest = (text.match(/`+/g) ?? []).reduce((n, run) => Math.max(n, run.length), 0);
      const fence = '`'.repeat(Math.max(3, longest + 1));

      return `${fence}${language}\n${text.replace(/\n$/, '')}\n${fence}`;
    }

    case 'ul':
    case 'ol':
      return serialiseList(element, context);

    case 'table':
      return serialiseTable(element);

    case 'figure': {
      const image = element.querySelector('img, video');
      const caption = element.querySelector('figcaption')?.textContent?.trim() ?? '';

      if (!image) return serialiseChildren(element, context);

      return serialiseMedia(image, caption);
    }

    case 'img':
    case 'video':
      return serialiseMedia(element, '');

    case 'section':
      // The rendered footnote list; its source lives in the definitions, which
      // are preserved separately, so it is dropped here.
      if (element.classList.contains('mtl-footnotes')) return null;
      return serialiseChildren(element, context);

    case 'div':
      // Browsers wrap lines in <div> while editing. Treat one as a paragraph.
      return serialiseInline(element).trim() || null;

    default:
      return serialiseInline(element).trim() || null;
  }
}

function serialiseChildren(element, context) {
  const parts = [];

  for (const child of element.childNodes) {
    const markdown = serialiseBlock(child, context);

    if (markdown !== null && markdown !== '') {
      parts.push(markdown);
    }
  }

  return parts.join('\n\n');
}

function serialiseList(element, context) {
  const ordered = element.tagName.toLowerCase() === 'ol';
  const start = Number(element.getAttribute('start') ?? 1) || 1;

  const lines = [];
  let index = start;

  for (const item of element.children) {
    if (item.tagName.toLowerCase() !== 'li') continue;

    const checkbox = item.querySelector(':scope > input[type="checkbox"]');
    let marker = ordered ? `${index}. ` : '- ';

    if (checkbox) {
      marker += checkbox.checked ? '[x] ' : '[ ] ';
      checkbox.remove();
    }

    // The item's own text, then any nested block content below it.
    const nested = [...item.children].filter((child) =>
      ['ul', 'ol', 'blockquote', 'pre'].includes(child.tagName.toLowerCase()));

    const clone = item.cloneNode(true);

    clone.querySelectorAll(':scope > ul, :scope > ol, :scope > blockquote, :scope > pre').forEach((child) => child.remove());

    const text = serialiseInline(clone).trim();

    lines.push(marker + text);

    for (const child of nested) {
      const inner = serialiseBlock(child, { depth: context.depth + 1 });

      if (inner) {
        // Indent by the marker width so the nesting survives a round trip.
        const indent = ' '.repeat(ordered ? String(index).length + 2 : 2);
        lines.push(inner.split('\n').map((line) => indent + line).join('\n'));
      }
    }

    index += 1;
  }

  return lines.join('\n');
}

function serialiseTable(table) {
  const rows = [...table.querySelectorAll('tr')];

  if (rows.length === 0) return '';

  const header = [...rows[0].children].map((cell) => serialiseInline(cell).trim().replace(/\|/g, '\\|'));

  const alignments = [...rows[0].children].map((cell) => {
    const align = cell.style.textAlign || '';

    return align === 'center' ? ':---:' : align === 'right' ? '---:' : align === 'left' ? ':---' : '---';
  });

  const lines = [`| ${header.join(' | ')} |`, `| ${alignments.join(' | ')} |`];

  for (const row of rows.slice(1)) {
    const cells = [...row.children].map((cell) => serialiseInline(cell).trim().replace(/\|/g, '\\|'));

    // Pad short rows so the table stays rectangular in the source.
    while (cells.length < header.length) cells.push('');

    lines.push(`| ${cells.join(' | ')} |`);
  }

  return lines.join('\n');
}

function serialiseMedia(element, caption) {
  const reference = element.dataset?.mediaUuid
    ? `mtl:media/${element.dataset.mediaUuid}`
    : element.getAttribute('src') ?? '';

  const alt = element.getAttribute('alt') ?? '';
  const title = caption ? ` "${caption.replace(/"/g, '\\"')}"` : '';

  return `![${alt}](${reference}${title})`;
}

// ---------------------------------------------------------------------------
// Inline content
// ---------------------------------------------------------------------------

function serialiseInline(node) {
  let out = '';

  for (const child of node.childNodes) {
    out += serialiseInlineNode(child);
  }

  return out;
}

function serialiseInlineNode(node) {
  if (node.nodeType === Node.TEXT_NODE) {
    return escapeInline(node.textContent ?? '');
  }

  if (node.nodeType !== Node.ELEMENT_NODE) return '';

  const element = /** @type {HTMLElement} */ (node);
  const tag = element.tagName.toLowerCase();

  switch (tag) {
    case 'strong':
    case 'b': {
      const inner = serialiseInline(element);
      // An empty emphasis span would produce "****", which is a literal in
      // markdown rather than formatting.
      return inner.trim() === '' ? inner : wrap(inner, '**');
    }

    case 'em':
    case 'i': {
      const inner = serialiseInline(element);
      return inner.trim() === '' ? inner : wrap(inner, '*');
    }

    case 'del':
    case 's':
    case 'strike': {
      const inner = serialiseInline(element);
      return inner.trim() === '' ? inner : wrap(inner, '~~');
    }

    case 'code': {
      const text = element.textContent ?? '';

      // Enough backticks to contain any run inside the span.
      const longest = (text.match(/`+/g) ?? []).reduce((n, run) => Math.max(n, run.length), 0);
      const fence = '`'.repeat(longest + 1);
      const padding = text.startsWith('`') || text.endsWith('`') ? ' ' : '';

      return `${fence}${padding}${text}${padding}${fence}`;
    }

    case 'a': {
      const href = element.getAttribute('href') ?? '';
      const label = serialiseInline(element);
      const title = element.getAttribute('title');

      if (!href) return label;

      // An autolink whose label is its own URL is written bare.
      if (label === href) return href;

      return `[${label}](${href}${title ? ` "${title}"` : ''})`;
    }

    case 'img':
      return serialiseMedia(element, '');

    case 'br':
      // Two trailing spaces are the markdown hard break; a backslash would
      // also work but is less forgiving of an editor that trims lines.
      return '  \n';

    case 'sup':
      // A rendered footnote reference goes back to its source form.
      if (element.classList.contains('mtl-footnote-ref')) {
        const id = element.querySelector('a')?.getAttribute('href')?.replace('#fn-', '') ?? '';
        return id ? `[^${id}]` : '';
      }
      return serialiseInline(element);

    case 'span':
    case 'font':
      // Styling wrappers a browser inserts carry no markdown meaning.
      return serialiseInline(element);

    default:
      return serialiseInline(element);
  }
}

/**
 * Wraps inline content in a delimiter, keeping any leading and trailing
 * whitespace outside it.
 *
 * "** bold **" is not emphasis in markdown — the delimiter has to sit against
 * a non-space character — so moving the spaces out is what makes a selection
 * that includes them still work.
 */
function wrap(text, delimiter) {
  const leading = /^\s*/.exec(text)[0];
  const trailing = /\s*$/.exec(text)[0];
  const core = text.slice(leading.length, text.length - trailing.length);

  if (core === '') return text;

  return `${leading}${delimiter}${core}${delimiter}${trailing}`;
}

/**
 * Escapes characters that would otherwise be read as markup.
 *
 * Deliberately conservative: over-escaping fills the source with backslashes
 * that the author then has to look at in source mode.
 */
function escapeInline(text) {
  return text
    .replace(/([\\`*_[\]])/g, '\\$1')
    // A non-breaking space is what a browser inserts when you type two spaces;
    // markdown treats it as an ordinary character and it is invisible in the
    // source, so it is normalised away.
    .replace(/ /g, ' ');
}

function escapeLine(text) {
  return escapeInline(text).replace(BLOCK_ESCAPES, '$1\\$2$3');
}
