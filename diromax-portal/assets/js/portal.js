/**
 * Diromax client portal — progressive enhancements only.
 * Everything works without this file; it adds table filters, a print button,
 * a "copy payment details" button and the signature drawing pad (without it,
 * the "cannot draw" box signs with the typed name). No innerHTML, no
 * dependencies.
 */
( function () {
	'use strict';

	function setupFilters() {
		var box = document.querySelector( '[data-dxp-filters]' );
		var rows = Array.prototype.slice.call( document.querySelectorAll( '.dxp-table tbody tr' ) );
		var status = document.querySelector( '[data-dxp-count]' );

		if ( ! box || ! rows.length ) {
			return;
		}

		var selects = Array.prototype.slice.call( box.querySelectorAll( '[data-dxp-filter]' ) );

		function apply() {
			var shown = 0;

			rows.forEach( function ( row ) {
				var match = selects.every( function ( select ) {
					var value = select.value;
					return '' === value || row.getAttribute( 'data-' + select.getAttribute( 'data-dxp-filter' ) ) === value;
				} );

				row.hidden = ! match;
				shown += match ? 1 : 0;
			} );

			if ( status ) {
				status.textContent = shown === rows.length ? '' : shown + ' / ' + rows.length;
			}
		}

		selects.forEach( function ( select ) {
			select.addEventListener( 'change', apply );
		} );
		box.hidden = false;
	}

	function setupPrint() {
		var button = document.querySelector( '[data-dxp-print]' );

		if ( button && window.print ) {
			button.hidden = false;
			button.addEventListener( 'click', function () {
				window.print();
			} );
		}
	}

	function setupCopy() {
		var button = document.querySelector( '[data-dxp-copy]' );

		if ( ! button || ! navigator.clipboard ) {
			return;
		}

		var source = document.getElementById( button.getAttribute( 'data-dxp-copy' ) );
		var label = button.textContent;

		button.hidden = false;
		button.addEventListener( 'click', function () {
			navigator.clipboard.writeText( source ? source.innerText : '' ).then( function () {
				button.textContent = button.getAttribute( 'data-dxp-copied' ) || label;
				window.setTimeout( function () {
					button.textContent = label;
				}, 1800 );
			} ).catch( function () {} );
		} );
	}

	// "Show password" (standard 4.5.5): the button toggles the field's type.
	function setupReveal() {
		Array.prototype.slice.call( document.querySelectorAll( '[data-dxp-reveal]' ) ).forEach( function ( button ) {
			var field = document.getElementById( button.getAttribute( 'data-dxp-reveal' ) );

			if ( ! field ) {
				return;
			}

			button.hidden = false;
			button.addEventListener( 'click', function () {
				var show = 'password' === field.type;

				field.type = show ? 'text' : 'password';
				button.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
				button.textContent = button.getAttribute( show ? 'data-dxp-hide' : 'data-dxp-show' );
			} );
		} );
	}

	// Signature pad: draw with a finger, a pen or a mouse; the PNG goes into the hidden field.
	function setupPads() {
		Array.prototype.slice.call( document.querySelectorAll( '[data-dxp-pad]' ) ).forEach( function ( pad ) {
			var canvas = pad.querySelector( 'canvas' );
			var value = pad.querySelector( '[data-dxp-pad-value]' );
			var clear = pad.querySelector( '[data-dxp-pad-clear]' );
			var context = canvas && canvas.getContext ? canvas.getContext( '2d' ) : null;
			var drawing = false;
			var drawn = false;
			var last = null;

			if ( ! context || ! value || ! canvas.toDataURL ) {
				return;
			}

			function point( event ) {
				var box = canvas.getBoundingClientRect();
				return {
					x: ( event.clientX - box.left ) * ( canvas.width / box.width ),
					y: ( event.clientY - box.top ) * ( canvas.height / box.height )
				};
			}

			function reset() {
				context.clearRect( 0, 0, canvas.width, canvas.height );
				value.value = '';
				drawn = false;
			}

			context.lineWidth = 3;
			context.lineCap = 'round';
			context.lineJoin = 'round';
			context.strokeStyle = '#15171c';

			canvas.addEventListener( 'pointerdown', function ( event ) {
				drawing = true;
				last = point( event );
				canvas.setPointerCapture( event.pointerId );
				event.preventDefault();
			} );
			canvas.addEventListener( 'pointermove', function ( event ) {
				var next;

				if ( ! drawing ) {
					return;
				}

				next = point( event );
				context.beginPath();
				context.moveTo( last.x, last.y );
				context.lineTo( next.x, next.y );
				context.stroke();
				last = next;
				drawn = true;
			} );
			[ 'pointerup', 'pointercancel' ].forEach( function ( type ) {
				canvas.addEventListener( type, function () {
					if ( drawing && drawn ) {
						value.value = canvas.toDataURL( 'image/png' );
					}
					drawing = false;
				} );
			} );

			if ( clear ) {
				clear.addEventListener( 'click', reset );
			}

			pad.hidden = false;
		} );
	}

	setupFilters();
	setupPrint();
	setupCopy();
	setupReveal();
	setupPads();
}() );
