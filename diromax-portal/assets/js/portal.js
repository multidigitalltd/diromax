/**
 * Diromax client portal — progressive enhancements only.
 * Everything works without this file; it adds table filters, a print button
 * and a "copy payment details" button. No innerHTML, no dependencies.
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

	setupFilters();
	setupPrint();
	setupCopy();
}() );
