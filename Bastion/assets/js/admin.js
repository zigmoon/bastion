/**
 * Bastion — settings screen.
 *
 * Tabs, plus one convenience: clicking a generated DNS record copies it. Both
 * are progressive enhancement — with JavaScript off the panels stay open, the
 * copies can be selected by hand, and the form still saves.
 */
(function () {
	'use strict';

	var root = document.querySelector('[data-bas-admin]');

	if (!root) {
		return;
	}

	root.classList.add('bas-admin--js');

	var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-bas-tab]'));
	var panels = Array.prototype.slice.call(root.querySelectorAll('[data-bas-panel]'));

	var activate = function (id, focus) {
		var found = false;

		tabs.forEach(function (tab) {
			var on = tab.getAttribute('data-bas-tab') === id;

			tab.setAttribute('aria-selected', on ? 'true' : 'false');
			tab.tabIndex = on ? 0 : -1;

			if (on && focus) {
				tab.focus();
			}
		});

		panels.forEach(function (panel) {
			var on = panel.getAttribute('data-bas-panel') === id;

			panel.hidden = !on;

			if (on) {
				found = true;
			}
		});

		if (!found) {
			activate(tabs[0].getAttribute('data-bas-tab'), focus);
			return;
		}

		if (window.history && window.history.replaceState) {
			try {
				window.history.replaceState(null, '', '#bas-panel-' + id);
			} catch (error) {
				// Ignore URL restrictions in sandboxed previews.
			}
		}
	};

	if (tabs.length && panels.length) {
		tabs.forEach(function (tab, index) {
			tab.addEventListener('click', function () {
				activate(tab.getAttribute('data-bas-tab'), false);
			});

			tab.addEventListener('keydown', function (event) {
				var next = null;

				if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
					next = tabs[(index + 1) % tabs.length];
				} else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
					next = tabs[(index - 1 + tabs.length) % tabs.length];
				} else if (event.key === 'Home') {
					next = tabs[0];
				} else if (event.key === 'End') {
					next = tabs[tabs.length - 1];
				}

				if (next) {
					event.preventDefault();
					activate(next.getAttribute('data-bas-tab'), true);
				}
			});
		});

		var initial = (window.location.hash || '').replace('#bas-panel-', '');

		activate(initial || tabs[0].getAttribute('data-bas-tab'), false);
	}

	// One click on a record selects and copies it: these strings are meant to be
	// pasted into a registrar, and a truncated copy would be a broken record.
	Array.prototype.slice.call(root.querySelectorAll('[data-bas-copy]')).forEach(function (node) {
		node.style.cursor = 'copy';
		node.title = 'Click to copy';

		node.addEventListener('click', function () {
			var text = node.textContent;

			if (!navigator.clipboard) {
				window.getSelection().selectAllChildren(node);
				return;
			}

			navigator.clipboard.writeText(text).then(function () {
				var previous = node.getAttribute('data-bas-copied');

				node.setAttribute('data-bas-copied', '1');
				node.style.borderLeftColor = '#0f7a53';

				window.setTimeout(function () {
					node.style.borderLeftColor = '';
					node.removeAttribute('data-bas-copied');
				}, 900);

				return previous;
			});
		});
	});
}());
