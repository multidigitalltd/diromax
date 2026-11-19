/**
 * First-visit privacy notice (Multi Digital standard 15.5).
 *
 * Shows the notice when this browser has not acknowledged the current policy
 * version in the last 12 months. The choice lives in localStorage only. The
 * footer's "privacy settings" button reopens it with focus inside; Escape or
 * the button closes it and returns focus to where it was.
 */
( function () {
	'use strict';

	var KEY = 'dx_privacy';
	var YEAR = 365 * 24 * 60 * 60 * 1000;
	var box = document.querySelector( '[data-dx-privacy]' );

	if ( ! box ) {
		return;
	}

	var version = box.getAttribute( 'data-dx-privacy' );
	var ok = box.querySelector( '[data-dx-privacy-ok]' );
	var opener = null;

	function stored() {
		try {
			var value = JSON.parse( window.localStorage.getItem( KEY ) || 'null' );
			return value && value.v === version && Date.now() - value.t < YEAR;
		} catch ( e ) {
			return false;
		}
	}

	function remember() {
		try {
			window.localStorage.setItem( KEY, JSON.stringify( { v: version, t: Date.now() } ) );
		} catch ( e ) {
			// Private mode: the notice will simply show again next time.
		}
	}

	function open( focus ) {
		box.hidden = false;
		if ( focus ) {
			opener = document.activeElement;
			box.focus();
		}
	}

	function close() {
		remember();
		box.hidden = true;
		if ( opener && opener.focus ) {
			opener.focus();
		}
		opener = null;
	}

	ok.addEventListener( 'click', close );
	box.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key ) {
			close();
		}
	} );

	Array.prototype.slice.call( document.querySelectorAll( '[data-dx-privacy-open]' ) ).forEach( function ( button ) {
		button.hidden = false;
		button.addEventListener( 'click', function () {
			open( true );
		} );
	} );

	if ( ! stored() ) {
		open( false );
	}
}() );
