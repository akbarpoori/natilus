/**
 * Natilus — TinyMCE toolbar bridge (classic editor).
 *
 * Adds a "Natilus" button to toolbar row 2 that scrolls the author to the
 * compose widget below the editor and dispatches a "kic:focus" event on
 * it. ES5-safe on purpose: no arrows, no let/const, no template literals.
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return; // Non-browser context guard.
	}

	var tmc = window.tinymce;

	if ( ! tmc || ! tmc.PluginManager ) {
		return; // TinyMCE core not loaded.
	}

	tmc.PluginManager.add( 'kic', function ( editor ) {

		editor.addButton( 'kic_ai', {
			text: 'Natilus',
			tooltip: 'Natilus',
			onclick: function () {
				var root = document.getElementById( 'kic-compose-root' );

				if ( root ) {
					if ( typeof root.scrollIntoView === 'function' ) {
						root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					} else {
						window.scrollTo( 0, root.offsetTop ); // Legacy fallback.
					}
				}

				if ( typeof window.CustomEvent === 'function' ) {
					window.dispatchEvent( new window.CustomEvent( 'kic:focus' ) );
				}
			}
		} );

		return this;
	} );
}() );
