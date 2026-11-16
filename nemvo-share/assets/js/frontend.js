/**
 * Nemvo Share – the share page: copy the note, show the password while typing.
 * The page works without JavaScript; this only adds conveniences.
 */
( function () {
	'use strict';

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			try {
				document.execCommand( 'copy' );
				resolve();
			} catch ( e ) {
				reject( e );
			}
			document.body.removeChild( area );
		} );
	}

	document.querySelectorAll( '.nvs-share [data-nvs-copy]' ).forEach( function ( button ) {
		var source = document.getElementById( button.getAttribute( 'data-nvs-copy' ) );
		if ( ! source ) {
			return;
		}
		var label = button.querySelector( 'span' );
		var original = label ? label.textContent : '';
		button.hidden = false;
		button.addEventListener( 'click', function () {
			copyText( source.textContent ).then( function () {
				button.classList.add( 'is-copied' );
				if ( label ) {
					label.textContent = button.getAttribute( 'data-copied' ) || original;
				}
				clearTimeout( button._nvsTimer );
				button._nvsTimer = setTimeout( function () {
					button.classList.remove( 'is-copied' );
					if ( label ) {
						label.textContent = original;
					}
				}, 2000 );
			} );
		} );
	} );

	document.querySelectorAll( '.nvs-share [data-nvs-reveal]' ).forEach( function ( button ) {
		var input = document.getElementById( button.getAttribute( 'data-nvs-reveal' ) );
		if ( ! input ) {
			return;
		}
		var showText = button.textContent;
		var hideText = button.getAttribute( 'data-hide' ) || showText;
		button.hidden = false;
		button.addEventListener( 'click', function () {
			var show = input.type === 'password';
			input.type = show ? 'text' : 'password';
			button.textContent = show ? hideText : showText;
			button.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
			input.focus();
		} );
	} );
} )();
