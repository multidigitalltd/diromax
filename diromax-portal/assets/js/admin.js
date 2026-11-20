/**
 * Diromax portal admin — progressive enhancements only.
 * Shows the entry fields that apply to the chosen type, and asks for
 * confirmation before destructive buttons. Forms work without it.
 */
( function () {
	'use strict';

	function syncEntry( container, type ) {
		Array.prototype.forEach.call( container.querySelectorAll( '[data-dxa-for]' ), function ( field ) {
			var types = field.getAttribute( 'data-dxa-for' ).split( ' ' );
			field.hidden = types.indexOf( type ) === -1;
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '[data-dxa-entry]' ), function ( container ) {
		var select = container.querySelector( '[data-dxa-type]' );

		syncEntry( container, select ? select.value : container.getAttribute( 'data-dxa-entry' ) );

		if ( select ) {
			select.addEventListener( 'change', function () {
				syncEntry( container, select.value );
			} );
		}
	} );

	// Wide tables scroll sideways on a small screen instead of widening the page.
	Array.prototype.forEach.call( document.querySelectorAll( '.dxa table.widefat' ), function ( table ) {
		var box;

		if ( table.parentNode.classList.contains( 'dxa-scroll' ) ) {
			return;
		}

		box           = document.createElement( 'div' );
		box.className = 'dxa-scroll';
		table.parentNode.insertBefore( box, table );
		box.appendChild( table );

		if ( box.scrollWidth > box.clientWidth ) {
			box.tabIndex = 0;
		}
	} );

	// Filter the ledger by type and text, without reloading.
	Array.prototype.forEach.call( document.querySelectorAll( '[data-dxa-filter]' ), function ( bar ) {
		var table = document.getElementById( bar.getAttribute( 'data-dxa-filter' ) );
		var type  = bar.querySelector( '[data-dxa-filter-type]' );
		var text  = bar.querySelector( '[data-dxa-filter-text]' );
		var count = bar.querySelector( '[data-dxa-filter-count]' );
		var rows;

		if ( ! table || ! type || ! text || ! count ) {
			return;
		}

		rows = Array.prototype.slice.call( table.querySelectorAll( 'tbody > tr[data-dxa-row]' ) );

		function rowText( row ) {
			// Every cell but the last, which holds the action forms.
			return Array.prototype.slice.call( row.cells, 0, -1 ).map( function ( cell ) {
				return cell.textContent;
			} ).join( ' ' ).toLowerCase();
		}

		function apply() {
			var wanted = type.value;
			var words  = text.value.trim().toLowerCase();
			var shown  = 0;

			rows.forEach( function ( row ) {
				var match = ( '' === wanted || row.getAttribute( 'data-dxa-row' ) === wanted ) && ( '' === words || -1 !== rowText( row ).indexOf( words ) );

				row.hidden = ! match;
				shown     += match ? 1 : 0;
			} );

			count.textContent = '' === wanted && '' === words ? '' : bar.getAttribute( 'data-dxa-count' ).replace( '%1$d', String( shown ) ).replace( '%2$d', String( rows.length ) );
		}

		bar.hidden = false;
		type.addEventListener( 'change', apply );
		text.addEventListener( 'input', apply );
	} );

	// "/" jumps to the case search, as on many web apps; not while typing.
	document.addEventListener( 'keydown', function ( event ) {
		var search = document.getElementById( 'dxa-top-search' );
		var target = event.target;

		if ( '/' !== event.key || ! search || event.ctrlKey || event.metaKey || event.altKey ) {
			return;
		}

		if ( target && ( target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test( target.tagName ) ) ) {
			return;
		}

		event.preventDefault();
		search.focus();
	} );

	Array.prototype.forEach.call( document.querySelectorAll( '[data-dxa-confirm]' ), function ( button ) {
		button.addEventListener( 'click', function ( event ) {
			if ( ! window.confirm( button.getAttribute( 'data-dxa-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );

	// A quick-add button or a "mark as paid" link opened this form: start
	// typing in its first field. Last, because moving a table into its scroll
	// box above would drop the focus from a field inside it.
	( function () {
		var box   = document.querySelector( 'details[data-dxa-focus][open]' );
		var field = box ? box.querySelector( 'input:not([type="hidden"]), select, textarea' ) : null;

		if ( field ) {
			field.focus();
		}
	}() );
}() );
