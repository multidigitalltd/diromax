/**
 * Diromax — lead form enhancement.
 *
 * Progressive: without this file the form is a normal POST that redirects back
 * with a message. With it, the form validates in place, submits over AJAX and
 * swaps in the confirmation panel without a reload.
 *
 * The nonce is fetched at submit time rather than read from the markup, so a
 * page served from a full-page cache can never submit a stale one.
 *
 * Expects window.dxForms = { submit, nonce, strings }.
 */
(function () {
	'use strict';

	var cfg = window.dxForms;
	if (!cfg) {
		return;
	}

	var nonce = null;
	var nonceRequest = null;

	/**
	 * Fetch a nonce once per page, reusing the in-flight promise.
	 *
	 * @return {Promise<string>}
	 */
	function getNonce() {
		if (nonce) {
			return Promise.resolve(nonce);
		}
		if (!nonceRequest) {
			nonceRequest = fetch(cfg.nonce, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					nonce = (data && data.nonce) || '';
					return nonce;
				})
				.catch(function () { return ''; });
		}
		return nonceRequest;
	}

	/**
	 * Attach or clear a field-level message, keeping aria-describedby intact.
	 *
	 * @param {HTMLElement} field   The input.
	 * @param {string}      message Empty string clears the error.
	 */
	function setFieldError(field, message) {
		var id = field.id + '-err';
		var node = document.getElementById(id);
		var described = (field.getAttribute('aria-describedby') || '')
			.split(' ')
			.filter(function (token) { return token && token !== id; });

		if (!message) {
			if (node) {
				node.remove();
			}
			field.removeAttribute('aria-invalid');
			if (described.length) {
				field.setAttribute('aria-describedby', described.join(' '));
			} else {
				field.removeAttribute('aria-describedby');
			}
			return;
		}

		if (!node) {
			node = document.createElement('span');
			node.className = 'dx-field-error';
			node.id = id;
			field.parentNode.appendChild(node);
		}
		node.textContent = message;
		field.setAttribute('aria-invalid', 'true');
		described.push(id);
		field.setAttribute('aria-describedby', described.join(' '));
	}

	/**
	 * Client-side mirror of the server rules. The server still decides.
	 *
	 * @param {HTMLFormElement} form Form element.
	 * @return {HTMLElement|null} First invalid field, if any.
	 */
	function validate(form) {
		var first = null;
		var name = form.querySelector('[name="dx_name"]');
		var phone = form.querySelector('[name="dx_phone"]');

		if (name) {
			if (!name.value.trim()) {
				setFieldError(name, 'צריך למלא שם מלא.');
				first = first || name;
			} else {
				setFieldError(name, '');
			}
		}

		if (phone) {
			var digits = phone.value.replace(/[^0-9]/g, '');
			if (!digits) {
				setFieldError(phone, 'צריך למלא מספר טלפון.');
				first = first || phone;
			} else if (digits.length < 9 || digits.length > 15) {
				setFieldError(phone, 'מספר הטלפון לא נראה תקין.');
				first = first || phone;
			} else {
				setFieldError(phone, '');
			}
		}

		return first;
	}

	/**
	 * Build the confirmation panel that replaces the form.
	 *
	 * @param {boolean} blue Blue card variant.
	 * @return {HTMLElement}
	 */
	function successPanel(blue) {
		var wrap = document.createElement('div');
		var heading = document.createElement(blue ? 'h2' : 'h3');
		var text = document.createElement('p');
		var rule = document.createElement('div');

		rule.className = 'dx-rule';
		rule.setAttribute('aria-hidden', 'true');
		heading.textContent = blue ? cfg.strings.thanksTtl2 : cfg.strings.thanksTtl;
		text.textContent = blue ? cfg.strings.thanksTxt2 : cfg.strings.thanksTxt;

		wrap.className = 'dx-sent';
		wrap.setAttribute('role', 'status');
		wrap.setAttribute('tabindex', '-1');
		wrap.appendChild(rule);
		wrap.appendChild(heading);
		wrap.appendChild(text);

		return wrap;
	}

	/**
	 * Show a form-level message and move focus to it.
	 *
	 * @param {HTMLFormElement} form    Form element.
	 * @param {string}          message Message text.
	 */
	function showAlert(form, message) {
		var alert = form.querySelector('[data-dx-alert]');
		if (!alert) {
			return;
		}
		alert.textContent = message || cfg.strings.error;
		alert.focus();
	}

	// Warm the nonce as soon as someone engages with a form — never on page load.
	document.addEventListener(
		'focusin',
		function (e) {
			if (e.target.closest && e.target.closest('.dx-lead-form')) {
				getNonce();
			}
		},
		{ once: true }
	);

	document.addEventListener('submit', function (e) {
		var form = e.target.closest ? e.target.closest('.dx-lead-form') : null;
		if (!form) {
			return;
		}

		e.preventDefault();

		var invalid = validate(form);
		if (invalid) {
			showAlert(form, 'יש שדה אחד שצריך תיקון לפני השליחה.');
			invalid.focus();
			return;
		}

		var button = form.querySelector('button[type=submit]');
		var label = button ? button.textContent : '';
		if (button) {
			button.disabled = true;
			button.textContent = cfg.strings.sending;
		}

		getNonce().then(function (token) {
			var body = new FormData(form);
			body.set('action', 'dx_lead');
			body.set('nonce', token || body.get('nonce') || '');

			return fetch(cfg.submit, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.success) {
						var card = form.closest('.dx-form-card');
						var panel = successPanel(!!(card && card.classList.contains('dx-form-card--blue')));
						form.replaceWith(panel);
						panel.focus();
						return;
					}

					if (button) {
						button.disabled = false;
						button.textContent = label;
					}
					showAlert(form, res && res.data && res.data.message);
				});
		}).catch(function () {
			if (button) {
				button.disabled = false;
				button.textContent = label;
			}
			showAlert(form, cfg.strings.error);
		});
	});

	// Clear a field error as soon as the visitor starts fixing it.
	document.addEventListener('input', function (e) {
		var field = e.target;
		if (field.matches && field.matches('.dx-lead-form .dx-input[aria-invalid]')) {
			setFieldError(field, '');
		}
	});
})();
