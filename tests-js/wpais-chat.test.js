/**
 * WP AI Suite — Tests fuer die reinen, DOM-freien Funktionen aus assets/js/wpais-chat.js.
 *
 * Bewusst node:test + node:assert statt Jest/Vitest: keine npm-Dependency noetig (Node >= 18
 * bringt beide fest mit), konsistent mit der wpais-chat.js selbst zugrunde liegenden Philosophie
 * "kein Build-Schritt, kein npm-Paket" (siehe dortiger Kopfkommentar). Ausfuehren mit:
 *   node --test tests-js/
 *
 * Deckt nur ab, was wpais-chat.js selbst am Ende exportiert (module.exports) — initChat() und
 * alles DOM-Abhaengige bleibt bewusst ungetestet hier (bräuchte jsdom o.ae., siehe FORTSETZUNG.md
 * "Bekannte Einschraenkungen" fuer die Begruendung, das nicht einzufuehren).
 *
 * Node erkennt "tests-js" nicht als Standard-Testordner (nur exakt "test"/"tests"), deshalb
 * IMMER mit explizitem Pfad/Glob aufrufen, nicht nur "node --test tests-js/":
 *   node --test tests-js/*.test.js
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const {
	escapeHtml,
	unescapeHtmlEntities,
	sanitizeUrl,
	renderMarkdown,
	inline,
	parseSseEvent,
} = require(path.join(__dirname, '..', 'assets', 'js', 'wpais-chat.js'));

test('escapeHtml escapes all five HTML-relevant characters', () => {
	assert.equal(escapeHtml('<script>alert("x") & \'y\'</script>'), '&lt;script&gt;alert(&quot;x&quot;) &amp; &#039;y&#039;&lt;/script&gt;');
});

test('unescapeHtmlEntities reverses escapeHtml for the same character set', () => {
	const original = '<b>Tom & "Jerry" it\'s</b>';
	assert.equal(unescapeHtmlEntities(escapeHtml(original)), original);
});

test('sanitizeUrl allows http/https/mailto', () => {
	assert.equal(sanitizeUrl('https://example.com/page'), 'https://example.com/page');
	assert.equal(sanitizeUrl('http://example.com'), 'http://example.com/');
	assert.equal(sanitizeUrl('mailto:test@example.com'), 'mailto:test@example.com');
});

test('sanitizeUrl rejects javascript: and other non-allowlisted schemes', () => {
	assert.equal(sanitizeUrl('javascript:alert(1)'), '#');
	assert.equal(sanitizeUrl('data:text/html,<script>alert(1)</script>'), '#');
});

test('sanitizeUrl falls back to # when URL parsing itself throws', () => {
	assert.equal(sanitizeUrl('http://['), '#');
});

test('inline handles bold, italic, inline code, and links', () => {
	assert.equal(inline('**bold**'), '<strong>bold</strong>');
	assert.equal(inline('*italic*'), '<em>italic</em>');
	assert.equal(inline('`code`'), '<code>code</code>');
	assert.match(inline('[Anthropic](https://anthropic.com)'), /^<a href="https:\/\/anthropic\.com\/?" target="_blank" rel="noopener noreferrer">Anthropic<\/a>$/);
});

test('inline sanitizes a javascript: link href', () => {
	assert.match(inline('[click](javascript:alert(1))'), /href="#"/);
});

test('renderMarkdown escapes raw HTML in the input before applying its own markup', () => {
	const html = renderMarkdown('<img src=x onerror=alert(1)>');
	assert.ok(!html.includes('<img'));
	assert.ok(html.includes('&lt;img'));
});

test('renderMarkdown renders headings, lists, and a fenced code block', () => {
	const md = '# Titel\n\n- eins\n- zwei\n\n```js\nconst x = 1;\n```';
	const html = renderMarkdown(md);

	assert.ok(html.includes('<h1>Titel</h1>'));
	assert.ok(html.includes('<ul><li>eins</li><li>zwei</li></ul>'));
	assert.ok(html.includes('<pre><code class="language-js">const x = 1;</code></pre>'));
});

test('renderMarkdown groups consecutive plain lines into one paragraph with <br>', () => {
	const html = renderMarkdown('Zeile eins\nZeile zwei');
	assert.equal(html, '<p>Zeile eins<br>Zeile zwei</p>');
});

test('parseSseEvent parses an event+data pair', () => {
	const parsed = parseSseEvent('event: token\ndata: {"delta":"Hi"}');
	assert.deepEqual(parsed, { event: 'token', data: { delta: 'Hi' } });
});

test('parseSseEvent defaults to event "message" when no event line is present', () => {
	const parsed = parseSseEvent('data: {"ok":true}');
	assert.equal(parsed.event, 'message');
});

test('parseSseEvent returns null when there is no data line', () => {
	assert.equal(parseSseEvent('event: ping'), null);
});

test('parseSseEvent returns null for malformed JSON in the data line', () => {
	assert.equal(parseSseEvent('data: {not json'), null);
});
