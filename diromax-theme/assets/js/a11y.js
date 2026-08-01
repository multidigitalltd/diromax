/**
 * Diromax — accessibility toolbar.
 *
 * Toggles document-level classes and a text-scale custom property, and stores
 * the choice in localStorage so it survives navigation and return visits. The
 * saved state is re-applied before paint by a small inline script in <head>;
 * this file only handles interaction.
 *
 * Everything here is keyboard operable: the panel is a modal dialog with a
 * focus trap, ESC closes it and focus returns to the launcher.
 */
(function () {
	'use strict';

	var STORE = 'dxA11y';
	var MIN = 1;
	var MAX = 1.6;
	var STEP = 0.1;

	var root = document.documentElement;
	var container = document.querySelector('[data-dx-a11y-root]');
	if (!container) {
		return;
	}

	var launcher = container.querySelector('[data-dx-a11y-launch]');
	var panel = container.querySelector('#dx-a11y-panel');
	var closeBtn = container.querySelector('[data-dx-a11y-close]');
	var resetBtn = container.querySelector('[data-dx-a11y-reset]');
	var valueOut = container.querySelector('[data-dx-a11y-value]');
	var guide = container.querySelector('[data-dx-a11y-guide]');
	var toggles = Array.prototype.slice.call(container.querySelectorAll('[data-dx-a11y-toggle]'));

	var state = { scale: 1, flags: [] };
	var lastFocused = null;

	/* ------------------------------------------------------------------ */
	/* State                                                               */
	/* ------------------------------------------------------------------ */

	function load() {
		try {
			var raw = localStorage.getItem(STORE);
			if (!raw) {
				return;
			}
			var saved = JSON.parse(raw);
			state.scale = parseFloat(saved.scale) || 1;
			state.flags = Array.isArray(saved.flags) ? saved.flags : [];
		} catch (e) {
			state = { scale: 1, flags: [] };
		}
	}

	function save() {
		try {
			localStorage.setItem(STORE, JSON.stringify({
				scale: String(state.scale),
				flags: state.flags
			}));
		} catch (e) {
			/* Storage disabled or full — the session still works, it just will not persist. */
		}
	}

	function apply() {
		root.style.setProperty('--dx-a11y-scale', String(state.scale));

		toggles.forEach(function (button) {
			var flag = button.getAttribute('data-dx-a11y-toggle');
			var on = state.flags.indexOf(flag) !== -1;
			button.setAttribute('aria-pressed', on ? 'true' : 'false');
			root.classList.toggle('dx-a11y-' + flag, on);
		});

		if (valueOut) {
			valueOut.textContent = Math.round(state.scale * 100) + '%';
		}

		bindGuide(state.flags.indexOf('guide') !== -1);
	}

	/* ------------------------------------------------------------------ */
	/* Reading guide                                                       */
	/* ------------------------------------------------------------------ */

	function moveGuide(y) {
		if (guide) {
			guide.style.transform = 'translateY(' + Math.round(y) + 'px)';
		}
	}

	function onPointer(e) {
		moveGuide(e.clientY);
	}

	function onFocusMove(e) {
		if (e.target && e.target.getBoundingClientRect) {
			var box = e.target.getBoundingClientRect();
			moveGuide(box.top + box.height / 2);
		}
	}

	var guideBound = false;

	/**
	 * Listeners exist only while the guide is on, so the pointer handler costs
	 * nothing for everyone else.
	 *
	 * @param {boolean} on Whether the guide is active.
	 */
	function bindGuide(on) {
		if (on === guideBound) {
			return;
		}
		guideBound = on;

		if (on) {
			document.addEventListener('pointermove', onPointer, { passive: true });
			document.addEventListener('focusin', onFocusMove);
		} else {
			document.removeEventListener('pointermove', onPointer);
			document.removeEventListener('focusin', onFocusMove);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Panel                                                               */
	/* ------------------------------------------------------------------ */

	function focusable() {
		return Array.prototype.slice
			.call(panel.querySelectorAll('button, a[href], input, select, textarea, [tabindex]:not([tabindex="-1"])'))
			.filter(function (el) { return !el.disabled && el.offsetParent !== null; });
	}

	function openPanel() {
		lastFocused = document.activeElement;
		panel.hidden = false;
		launcher.setAttribute('aria-expanded', 'true');
		container.classList.add('is-open');

		var first = focusable()[0];
		if (first) {
			first.focus();
		}
	}

	function closePanel() {
		panel.hidden = true;
		launcher.setAttribute('aria-expanded', 'false');
		container.classList.remove('is-open');

		if (lastFocused && lastFocused.focus) {
			lastFocused.focus();
		} else {
			launcher.focus();
		}
	}

	function isOpen() {
		return !panel.hidden;
	}

	launcher.addEventListener('click', function () {
		if (isOpen()) {
			closePanel();
		} else {
			openPanel();
		}
	});

	if (closeBtn) {
		closeBtn.addEventListener('click', closePanel);
	}

	document.addEventListener('keydown', function (e) {
		if (!isOpen()) {
			return;
		}

		if (e.key === 'Escape') {
			e.preventDefault();
			closePanel();
			return;
		}

		if (e.key !== 'Tab') {
			return;
		}

		// Focus trap: the dialog is modal, so Tab must not leave it.
		var items = focusable();
		if (!items.length) {
			return;
		}

		var first = items[0];
		var last = items[items.length - 1];

		if (e.shiftKey && document.activeElement === first) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault();
			first.focus();
		}
	});

	document.addEventListener('click', function (e) {
		if (isOpen() && !container.contains(e.target)) {
			closePanel();
		}
	});

	/* ------------------------------------------------------------------ */
	/* Controls                                                            */
	/* ------------------------------------------------------------------ */

	toggles.forEach(function (button) {
		button.addEventListener('click', function () {
			var flag = button.getAttribute('data-dx-a11y-toggle');
			var index = state.flags.indexOf(flag);

			if (index === -1) {
				state.flags.push(flag);
			} else {
				state.flags.splice(index, 1);
			}

			apply();
			save();
		});
	});

	container.querySelectorAll('[data-dx-a11y-step]').forEach(function (button) {
		button.addEventListener('click', function () {
			var direction = parseInt(button.getAttribute('data-dx-a11y-step'), 10);
			var next = Math.round((state.scale + direction * STEP) * 100) / 100;

			state.scale = Math.min(MAX, Math.max(MIN, next));
			apply();
			save();
		});
	});

	if (resetBtn) {
		resetBtn.addEventListener('click', function () {
			state.flags.slice().forEach(function (flag) {
				root.classList.remove('dx-a11y-' + flag);
			});
			state = { scale: 1, flags: [] };
			apply();
			save();
		});
	}

	load();
	apply();
})();
