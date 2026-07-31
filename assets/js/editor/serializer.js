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
      // One-to-one with the source, because the surface is rendered with
      // MarkdownOptions::editing(), which applies no heading offset. The
      // published page does shift `#` to an <h2>, but undoing a shift is lossy
      // at the top of the range — see that method for why.
      const level = Number(tag[1]);
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
      if (element.classList.contains('mtl-footnotes')) return serialiseFootnotes(element, context);
      return serialiseChildren(element, context);

    case 'div':
    default:
      // Two quite different things arrive here. A browser wraps an edited line
      // in a bare <div>, which is a paragraph. But the renderer also wraps a
      // table in <div class="mtl-table-scroll">, and treating that as inline
      // flattened the table into its cell text — a table in a report did not
      // survive its first save.
      return hasBlockChildren(element)
        ? serialiseChildren(element, context)
        : serialiseInline(element).trim() || null;
  }
}

/** Block-level tags that must never be serialised as inline content. */
const BLOCK_TAGS = new Set([
  'address', 'article', 'aside', 'blockquote', 'div', 'dl', 'figure', 'footer',
  'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'ol', 'p', 'pre',
  'section', 'table', 'ul',
]);

function hasBlockChildren(element) {
  return [...element.children].some((child) => BLOCK_TAGS.has(child.tagName.toLowerCase()));
}

/**
 * Turns the rendered footnote list back into `[^label]: text` definitions.
 *
 * This used to return null on the grounds that the definitions were preserved
 * elsewhere. They were not: saving a report with a footnote kept the `[^1]`
 * reference and threw the note itself away, leaving a reference pointing at
 * nothing.
 *
 * The label comes from the list item's id, which the renderer builds from the
 * author's own label, so `[^bron]` comes back as `[^bron]` rather than `[^1]`.
 */
function serialiseFootnotes(section, context) {
  const definitions = [];

  section.querySelectorAll('li[id^="fn-"]').forEach((item) => {
    const label = item.id.slice('fn-'.length);

    if (label === '') return;

    // Serialised from a copy: the back-link is presentation, and removing it
    // from the live DOM would break the published page the surface came from.
    const clone = item.cloneNode(true);

    clone.querySelectorAll('.mtl-footnote-back').forEach((back) => back.remove());

    const body = serialiseChildren(clone, context).trim();

    if (body === '') return;

    // Continuation lines are indented so they stay part of the definition.
    const [first, ...rest] = body.split('\n');

    definitions.push(
      [`[^${label}]: ${first}`, ...rest.map((line) => (line === '' ? '' : `    ${line}`))].join('\n'),
    );
  });

  return definitions.length > 0 ? definitions.join('\n\n') : null;
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

  // A figure's caption becomes the title; a bare image keeps the title it
  // already has. Reading only the caption meant `![alt](url "titel")` came back
  // without its title.
  const text = caption || element.getAttribute('title') || '';
  const title = text ? ` "${text.replace(/"/g, '\\"')}"` : '';

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

  // A hard break is "two spaces, newline". The renderer pretty-prints its HTML,
  // so the text node after a <br> starts with the newline that ended the source
  // line — which turned one paragraph with a line break into two paragraphs.
  // Whitespace directly after a break carries no meaning.
  return out.replace(/ {2}\n[ \t]+/g, '  \n');
}

/**
 * Collapses runs of whitespace the way HTML rendering does.
 *
 * A newline inside a text node is layout, not content: the renderer puts one
 * after every block tag and after a <br>. Left alone they were serialised as
 * real line breaks, so the markdown grew a blank line on each save.
 */
function collapseWhitespace(text) {
  return text.replace(/[\t\n\r ]+/g, ' ');
}

function serialiseInlineNode(node) {
  if (node.nodeType === Node.TEXT_NODE) {
    return escapeInline(collapseWhitespace(node.textContent ?? ''));
  }

  if (node.nodeType !== Node.ELEMENT_NODE) return '';

  const element = /** @type {HTMLElement} */ (node);
  const tag = element.tagName.toLowerCase();

  // Decorative markup the renderer adds and the author never typed: the
  // permalink beside a heading, the arrow back from a footnote. Serialising them
  // wrote links into the source that then multiplied on every save.
  if (element.getAttribute('aria-hidden') === 'true' || element.classList.contains('mtl-footnote-back')) {
    return '';
  }

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
