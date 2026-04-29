import { Marked } from "marked";
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

/** @param {string} markdown @returns {string} */
export function parse(markdown) {
    if (!markdown) return '';
    return String(marked.parse(markdown));
}
