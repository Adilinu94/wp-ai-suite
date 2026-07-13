/**
 * WP AI Suite — Chat-Widget-Frontend (M3).
 *
 * Bewusst ein einziges, abhaengigkeitsfreies Vanilla-JS-Bundle (kein Build-Schritt, kein
 * npm-Paket) — konsistent mit Bauplan-Regel 2 ("kleiner MVP, keine Architektur ueber den Bedarf
 * hinaus") und mit dem an anderer Stelle etablierten Muster, Abhaengigkeiten wo vertretbar zu
 * vermeiden. Initialisiert jedes `.wpais-chat`-Element auf der Seite (mehrere Instanzen moeglich).
 *
 * Konsumiert `POST /wpais/v1/chat` (SSE via fetch()+ReadableStream, NICHT das native
 * EventSource — das kann kein POST mit Custom-Headern) und `GET /wpais/v1/conversations/{token}`
 * aus M2. Konfiguration (REST-URLs, Nonce) kommt ueber window.wpaisChatConfig
 * (wp_localize_script, siehe AssetManager.php).
 */
(function (root) {
	'use strict';

	var hasWindow = typeof root !== 'undefined' && typeof root.document !== 'undefined';
	var i18n = hasWindow && root.wp && root.wp.i18n ? root.wp.i18n : null;

	function __(text) {
		return i18n ? i18n.__(text, 'wp-ai-suite') : text;
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function unescapeHtmlEntities(str) {
		return String(str)
			.replace(/&amp;/g, '&')
			.replace(/&lt;/g, '<')
			.replace(/&gt;/g, '>')
			.replace(/&quot;/g, '"')
			.replace(/&#039;/g, "'");
	}

	function sanitizeUrl(url) {
		try {
			var base = hasWindow ? root.location.href : 'http://localhost/';
			var parsed = new URL(url, base);
			if (['http:', 'https:', 'mailto:'].indexOf(parsed.protocol) !== -1) {
				return parsed.href;
			}
		} catch (err) {
			/* ungueltige URL, faellt durch zu '#' */
		}
		return '#';
	}

	/**
	 * Minimaler Markdown-Renderer — bewusst KEIN vollstaendiges CommonMark, keine externe
	 * Abhaengigkeit. Deckt ab, was LLM-Chat-Antworten typischerweise nutzen: Ueberschriften,
	 * fett/kursiv, Inline-Code, Codebloecke mit Sprachangabe, Links, Listen, Zitate, Absaetze.
	 * Escaped IMMER zuerst auf Rohtext-Ebene, bevor eigene (kontrollierte) HTML-Tags eingefuegt
	 * werden — verhindert, dass Modellausgaben als HTML/Script interpretiert werden.
	 */
	function renderMarkdown(raw) {
		var lines = escapeHtml(raw).split('\n');
		var blocks = [];
		var i = 0;

		function isBlockStart(line) {
			return (
				/^```/.test(line) ||
				/^#{1,6}\s+/.test(line) ||
				/^[-*]\s+/.test(line) ||
				/^\d+\.\s+/.test(line) ||
				/^&gt;\s?/.test(line)
			);
		}

		while (i < lines.length) {
			var line = lines[i];

			var fenceMatch = line.match(/^```(\w*)\s*$/);
			if (fenceMatch) {
				var lang = fenceMatch[1];
				var codeLines = [];
				i++;
				while (i < lines.length && !/^```\s*$/.test(lines[i])) {
					codeLines.push(lines[i]);
					i++;
				}
				i++;
				var langClass = lang ? ' class="language-' + lang + '"' : '';
				blocks.push('<pre><code' + langClass + '>' + codeLines.join('\n') + '</code></pre>');
				continue;
			}

			var headingMatch = line.match(/^(#{1,6})\s+(.*)$/);
			if (headingMatch) {
				var level = headingMatch[1].length;
				blocks.push('<h' + level + '>' + inline(headingMatch[2]) + '</h' + level + '>');
				i++;
				continue;
			}

			if (/^&gt;\s?/.test(line)) {
				var quoteLines = [];
				while (i < lines.length && /^&gt;\s?/.test(lines[i])) {
					quoteLines.push(lines[i].replace(/^&gt;\s?/, ''));
					i++;
				}
				blocks.push('<blockquote>' + inline(quoteLines.join(' ')) + '</blockquote>');
				continue;
			}

			if (/^[-*]\s+/.test(line)) {
				var ulItems = [];
				while (i < lines.length && /^[-*]\s+/.test(lines[i])) {
					ulItems.push('<li>' + inline(lines[i].replace(/^[-*]\s+/, '')) + '</li>');
					i++;
				}
				blocks.push('<ul>' + ulItems.join('') + '</ul>');
				continue;
			}

			if (/^\d+\.\s+/.test(line)) {
				var olItems = [];
				while (i < lines.length && /^\d+\.\s+/.test(lines[i])) {
					olItems.push('<li>' + inline(lines[i].replace(/^\d+\.\s+/, '')) + '</li>');
					i++;
				}
				blocks.push('<ol>' + olItems.join('') + '</ol>');
				continue;
			}

			if (line.trim() === '') {
				i++;
				continue;
			}

			var paraLines = [line];
			i++;
			while (i < lines.length && lines[i].trim() !== '' && !isBlockStart(lines[i])) {
				paraLines.push(lines[i]);
				i++;
			}
			blocks.push(
				'<p>' +
					paraLines
						.map(function (l) {
							return inline(l);
						})
						.join('<br>') +
					'</p>',
			);
		}

		return blocks.join('\n');
	}

	function inline(text) {
		return text
			.replace(/`([^`]+)`/g, '<code>$1</code>')
			.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
			.replace(/__([^_]+)__/g, '<strong>$1</strong>')
			.replace(/\*([^*]+)\*/g, '<em>$1</em>')
			.replace(/_([^_]+)_/g, '<em>$1</em>')
			.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function (match, label, url) {
				var safeHref = escapeHtml(sanitizeUrl(unescapeHtmlEntities(url)));
				return '<a href="' + safeHref + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
			});
	}

	function parseSseEvent(raw) {
		var eventType = 'message';
		var dataLine = null;

		raw.split('\n').forEach(function (line) {
			if (line.indexOf('event:') === 0) {
				eventType = line.slice(6).trim();
			} else if (line.indexOf('data:') === 0) {
				dataLine = line.slice(5).trim();
			}
		});

		if (dataLine === null) {
			return null;
		}

		try {
			return { event: eventType, data: JSON.parse(dataLine) };
		} catch (err) {
			return null;
		}
	}

	function initChat(container, widgetId) {
		var mode = container.dataset.mode || 'inline';
		var welcome = container.dataset.welcome || __('Hallo! Wie kann ich dir helfen?');
		var storageKey = 'wpaisSessionToken:' + widgetId;

		container.classList.add('wpais-chat--' + mode);
		container.innerHTML =
			'<div class="wpais-chat__messages" role="log" aria-live="polite"></div>' +
			'<form class="wpais-chat__form">' +
			'<textarea class="wpais-chat__input" rows="1" placeholder="' +
			escapeHtml(__('Nachricht eingeben…')) +
			'" aria-label="' +
			escapeHtml(__('Nachricht')) +
			'"></textarea>' +
			'<button type="submit" class="wpais-chat__send" aria-label="' +
			escapeHtml(__('Senden')) +
			'">&#8594;</button>' +
			'</form>';

		var messagesEl = container.querySelector('.wpais-chat__messages');
		var formEl = container.querySelector('.wpais-chat__form');
		var inputEl = container.querySelector('.wpais-chat__input');
		var sendBtn = container.querySelector('.wpais-chat__send');

		var sessionToken = null;
		try {
			sessionToken = window.sessionStorage.getItem(storageKey);
		} catch (err) {
			/* sessionStorage evtl. nicht verfuegbar (Privacy-Modus) — Konversation bleibt dann nur In-Memory. */
		}

		var sending = false;

		function persistToken(token) {
			sessionToken = token;
			try {
				window.sessionStorage.setItem(storageKey, token);
			} catch (err) {
				/* siehe oben */
			}
		}

		function addMessage(role, html, options) {
			options = options || {};
			var row = document.createElement('div');
			row.className = 'wpais-chat__row wpais-chat__row--' + role;

			var bubble = document.createElement('div');
			bubble.className = 'wpais-chat__bubble';
			if (options.streaming) {
				bubble.classList.add('wpais-chat__bubble--streaming');
			}
			bubble.innerHTML = html;

			row.appendChild(bubble);
			messagesEl.appendChild(row);
			messagesEl.scrollTop = messagesEl.scrollHeight;

			return bubble;
		}

		function showWelcome() {
			addMessage('assistant', renderMarkdown(welcome));
		}

		function restoreHistory() {
			if (!sessionToken) {
				showWelcome();
				return;
			}

			fetch(window.wpaisChatConfig.conversationsUrlBase + encodeURIComponent(sessionToken), {
				headers: { 'X-WP-Nonce': window.wpaisChatConfig.nonce },
			})
				.then(function (res) {
					return res.ok ? res.json() : Promise.reject(res);
				})
				.then(function (data) {
					if (!data.messages || !data.messages.length) {
						showWelcome();
						return;
					}
					data.messages.forEach(function (m) {
						addMessage(m.role, renderMarkdown(m.content));
					});
				})
				.catch(function () {
					sessionToken = null;
					try {
						window.sessionStorage.removeItem(storageKey);
					} catch (err) {
						/* siehe oben */
					}
					showWelcome();
				});
		}

		function setSending(isSending) {
			sending = isSending;
			sendBtn.disabled = isSending;
		}

		function sendMessage(text) {
			if (sending) {
				return;
			}
			setSending(true);

			addMessage('user', escapeHtml(text));
			var bubble = addMessage('assistant', '<span class="wpais-chat__cursor"></span>', { streaming: true });
			var rawContent = '';

			fetch(window.wpaisChatConfig.chatUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': window.wpaisChatConfig.nonce,
				},
				body: JSON.stringify({ message: text, session_token: sessionToken }),
			})
				.then(function (response) {
					if (!response.ok || !response.body) {
						return response
							.json()
							.catch(function () {
								return null;
							})
							.then(function (errorData) {
								throw new Error((errorData && errorData.message) || __('Verbindung fehlgeschlagen.'));
							});
					}

					var reader = response.body.getReader();
					var decoder = new TextDecoder();
					var buffer = '';

					function pump() {
						return reader.read().then(function (result) {
							if (result.done) {
								return;
							}

							buffer += decoder.decode(result.value, { stream: true });

							var boundary;
							while ((boundary = buffer.indexOf('\n\n')) !== -1) {
								var rawEvent = buffer.slice(0, boundary);
								buffer = buffer.slice(boundary + 2);
								var parsed = parseSseEvent(rawEvent);
								if (!parsed) {
									continue;
								}

								if (parsed.event === 'conversation' && parsed.data.session_token) {
									persistToken(parsed.data.session_token);
								} else if (parsed.event === 'token') {
									rawContent += parsed.data.delta;
									bubble.innerHTML = renderMarkdown(rawContent) + '<span class="wpais-chat__cursor"></span>';
									messagesEl.scrollTop = messagesEl.scrollHeight;
								} else if (parsed.event === 'final') {
									bubble.innerHTML = renderMarkdown(parsed.data.content);
									bubble.classList.remove('wpais-chat__bubble--streaming');
								} else if (parsed.event === 'error') {
									bubble.innerHTML = renderMarkdown(parsed.data.message || __('Etwas ist schiefgelaufen.'));
									bubble.classList.add('wpais-chat__bubble--error');
									bubble.classList.remove('wpais-chat__bubble--streaming');
								}
							}

							return pump();
						});
					}

					return pump();
				})
				.catch(function (err) {
					bubble.classList.add('wpais-chat__bubble--error');
					bubble.classList.remove('wpais-chat__bubble--streaming');
					bubble.textContent = (err && err.message) || __('Etwas ist schiefgelaufen. Bitte versuche es erneut.');
				})
				.then(function () {
					setSending(false);
					inputEl.value = '';
					inputEl.focus();
				});
		}

		formEl.addEventListener('submit', function (e) {
			e.preventDefault();
			var text = inputEl.value.trim();
			if (!text) {
				return;
			}
			sendMessage(text);
		});

		inputEl.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				if (typeof formEl.requestSubmit === 'function') {
					formEl.requestSubmit();
				} else {
					sendMessage(inputEl.value.trim());
				}
			}
		});

		restoreHistory();
	}

	function init() {
		var containers = document.querySelectorAll('.wpais-chat');
		containers.forEach(function (el, index) {
			initChat(el, String(index));
		});
	}

	if (hasWindow) {
		if (root.document.readyState === 'loading') {
			root.document.addEventListener('DOMContentLoaded', init);
		} else {
			init();
		}
	}

	// Nur fuer Tests (Node, siehe tests-js/): im Browser ist `module` undefiniert, dieser Block
	// ist dort ein no-op. Exportiert ausschliesslich die reinen, DOM-freien Funktionen.
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = {
			escapeHtml: escapeHtml,
			unescapeHtmlEntities: unescapeHtmlEntities,
			sanitizeUrl: sanitizeUrl,
			renderMarkdown: renderMarkdown,
			inline: inline,
			parseSseEvent: parseSseEvent,
		};
	}
})(typeof window !== 'undefined' ? window : typeof global !== 'undefined' ? global : this);
