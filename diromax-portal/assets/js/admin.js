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

	Array.prototype.forEach.call( document.querySelectorAll( '[data-dxa-confirm]' ), function ( button ) {
		button.addEventListener( 'click', function ( event ) {
			if ( ! window.confirm( button.getAttribute( 'data-dxa-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );
}() );
