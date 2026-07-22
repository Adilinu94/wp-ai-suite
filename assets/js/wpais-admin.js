/**
 * Umbauplan Post-MVP Punkt 4: Nur auf der Einstellungsseite eingebunden (siehe
 * ProviderSettingsPage/Plugin.php enqueue-Bedingung), deshalb bewusst kein Aufwand fuer
 * Mehrfachinstanzen o.ae. wie in wpais-chat.js — es gibt genau eine Instanz jedes Elements pro
 * Seitenaufruf.
 */
(function () {
    'use strict';

    function onReady(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function initCopyButton() {
        var button = document.querySelector('[data-wpais-copy]');
        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            var text = button.getAttribute('data-copy-text') || '';
            var original = button.textContent;

            var done = function (ok) {
                button.textContent = ok ? (button.getAttribute('data-copied-label') || 'Kopiert!') : original;
                window.setTimeout(function () {
                    button.textContent = original;
                }, 2000);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () {
                    done(true);
                }, function () {
                    done(false);
                });
                return;
            }

            // Fallback fuer nicht-sicheren Kontext (z.B. http:// lokale Entwicklung ohne HTTPS).
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            var ok = false;
            try {
                ok = document.execCommand('copy');
            } catch (e) {
                ok = false;
            }
            document.body.removeChild(textarea);
            done(ok);
        });
    }

    function runConnectionTest(button) {
        var type = button.getAttribute('data-wpais-test');
        var statusEl = document.querySelector('[data-wpais-test-status="' + type + '"]');
        var config = window.wpaisAdmin || {};

        if (!statusEl || !config.restUrl || !config.nonce) {
            return;
        }

        button.disabled = true;
        statusEl.textContent = config.testingLabel || 'Teste...';
        statusEl.className = 'wpais-test-status';

        fetch(config.restUrl + 'admin/connection-test', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce,
            },
            body: JSON.stringify({ type: type }),
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data.ok) {
                    statusEl.textContent = data.error || (config.errorLabel || 'Fehlgeschlagen.');
                    statusEl.className = 'wpais-test-status wpais-test-status--error';
                    return;
                }

                if (data.fallback) {
                    statusEl.textContent = data.message || '';
                    statusEl.className = 'wpais-test-status wpais-test-status--warning';
                    return;
                }

                var parts = [];
                if (data.provider) {
                    parts.push(data.provider);
                }
                if (typeof data.latency_ms === 'number') {
                    parts.push(data.latency_ms + ' ms');
                }
                if (typeof data.dimensions === 'number') {
                    parts.push(data.dimensions + ' Dimensionen');
                }
                if (data.reply_preview) {
                    parts.push('"' + data.reply_preview + '"');
                }

                statusEl.textContent = (config.successLabel || 'OK') + ' — ' + parts.join(', ');
                statusEl.className = 'wpais-test-status wpais-test-status--success';
            })
            .catch(function () {
                statusEl.textContent = config.errorLabel || 'Fehlgeschlagen.';
                statusEl.className = 'wpais-test-status wpais-test-status--error';
            })
            .finally(function () {
                button.disabled = false;
            });
    }

    function initConnectionTests() {
        var buttons = document.querySelectorAll('[data-wpais-test]');
        buttons.forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                runConnectionTest(button);
            });
        });
    }

    onReady(function () {
        initCopyButton();
        initConnectionTests();
    });
})();
