/**
 * Nemvo Share – admin screens.
 *
 * Uploads files in chunks through the REST API while the share is being written, with
 * a progress bar per file, then submits the form with the finished upload IDs. Also:
 * password generator, copy buttons, expiry picker and confirmations.
 */
( function () {
	'use strict';

	var cfg = window.nemvoShare || {};
	var i18n = window.wp && window.wp.i18n;
	var __ = i18n ? i18n.__ : function ( s ) {
		return s;
	};
	var sprintf = i18n ? i18n.sprintf : function ( s ) {
		return s;
	};

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	function $( sel, root ) {
		return ( root || document ).querySelector( sel );
	}

	function $$( sel, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) );
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function formatSize( bytes ) {
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		var n = bytes;
		while ( n >= 1024 && i < units.length - 1 ) {
			n /= 1024;
			i++;
		}
		return ( i === 0 ? n : n.toFixed( n >= 100 ? 0 : 1 ) ) + ' ' + units[ i ];
	}

	function formatDuration( seconds ) {
		seconds = Math.max( 1, Math.round( seconds ) );
		if ( seconds < 60 ) {
			/* translators: %d: seconds. */
			return sprintf( __( '%d s left', 'nemvo-share' ), seconds );
		}
		if ( seconds < 3600 ) {
			/* translators: %d: minutes. */
			return sprintf( __( '%d min left', 'nemvo-share' ), Math.ceil( seconds / 60 ) );
		}
		/* translators: %s: hours. */
		return sprintf( __( '%s h left', 'nemvo-share' ), ( seconds / 3600 ).toFixed( 1 ) );
	}

	function randomPassword() {
		var alphabet = cfg.alphabet || 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		var out = '';
		var limit = 256 - ( 256 % alphabet.length );
		var buf = new Uint8Array( 1 );
		while ( out.length < 16 ) {
			window.crypto.getRandomValues( buf );
			if ( buf[ 0 ] < limit ) {
				out += alphabet[ buf[ 0 ] % alphabet.length ];
			}
		}
		return out.match( /.{4}/g ).join( '-' );
	}

	/* ------------------------------------------------------------------ */
	/* Copy buttons and confirmations (all screens)                       */
	/* ------------------------------------------------------------------ */

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var area = el( 'textarea' );
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

	function copied( button ) {
		var label = $( '.screen-reader-text', button ) || button;
		if ( ! button.dataset.label ) {
			button.dataset.label = label.textContent;
		}
		label.textContent = __( 'Copied!', 'nemvo-share' );
		button.classList.add( 'is-copied' );
		clearTimeout( button._nvsTimer );
		button._nvsTimer = setTimeout( function () {
			label.textContent = button.dataset.label;
			button.classList.remove( 'is-copied' );
		}, 1800 );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-nvs-copy], [data-nvs-copy-from]' );
		if ( button ) {
			event.preventDefault();
			var text = button.getAttribute( 'data-nvs-copy' );
			if ( text === null ) {
				var source = document.getElementById( button.getAttribute( 'data-nvs-copy-from' ) );
				text = source ? source.value : '';
				if ( source && source.select ) {
					source.select();
				}
			}
			if ( text ) {
				copyText( text ).then( function () {
					copied( button );
				} );
			}
			return;
		}
		var confirmLink = event.target.closest( '[data-nvs-confirm]' );
		if ( confirmLink && ! window.confirm( confirmLink.getAttribute( 'data-nvs-confirm' ) ) ) {
			event.preventDefault();
		}
	} );

	// Clicking a read-only link field selects it, ready to copy by hand.
	$$( '.nvs-linkpanel input[readonly], .nvs-copyrow input[readonly]' ).forEach( function ( input ) {
		input.addEventListener( 'focus', function () {
			input.select();
		} );
	} );

	// Colour picker on the settings screen.
	if ( window.jQuery && window.jQuery.fn.wpColorPicker ) {
		window.jQuery( '.nvs-color' ).wpColorPicker();
	}

	/* ------------------------------------------------------------------ */
	/* Editor                                                             */
	/* ------------------------------------------------------------------ */

	var form = document.getElementById( 'nvs-editor' );
	if ( ! form ) {
		return;
	}
	form.classList.add( 'nvs-js' );

	var submit = document.getElementById( 'nvs-submit' );
	var submitLabel = submit ? submit.textContent.trim() : '';
	var submitStatus = document.getElementById( 'nvs-submit-status' );
	var submitting = false;

	function setStatus( text, isError ) {
		if ( submitStatus ) {
			submitStatus.textContent = text || '';
			submitStatus.classList.toggle( 'is-error', !! isError );
		}
	}

	/* ---------- Access and password ---------- */

	var passwordField = document.getElementById( 'nvs-password-field' );
	var passwordInput = document.getElementById( 'nvs-password' );
	var strength = document.getElementById( 'nvs-strength' );

	function checkStrength() {
		if ( ! strength || ! passwordInput ) {
			return;
		}
		var value = passwordInput.value;
		var weak = value.length > 0 && value.length < 10;
		strength.classList.toggle( 'is-weak', weak );
		strength.textContent = weak
			? __( 'Short passwords are easy to guess. Use the generate button for a strong one.', 'nemvo-share' )
			: '';
	}

	function syncAccess() {
		var checked = $( 'input[name="access"]:checked', form );
		var usePassword = checked && checked.value === 'password';
		if ( passwordField ) {
			passwordField.hidden = ! usePassword;
		}
		if ( usePassword && passwordInput && ! passwordInput.value && ! passwordInput.disabled ) {
			passwordInput.value = randomPassword();
		}
		checkStrength();
	}

	$$( 'input[name="access"]', form ).forEach( function ( radio ) {
		radio.addEventListener( 'change', syncAccess );
	} );
	if ( passwordInput ) {
		passwordInput.addEventListener( 'input', checkStrength );
	}
	var generate = document.getElementById( 'nvs-generate' );
	if ( generate ) {
		generate.addEventListener( 'click', function () {
			passwordInput.value = randomPassword();
			checkStrength();
			passwordInput.focus();
			passwordInput.select();
		} );
	}
	syncAccess();

	/* ---------- Expiry ---------- */

	var expiry = document.getElementById( 'nvs-expiry' );
	var expiryWrap = document.getElementById( 'nvs-expiry-date-wrap' );
	var expiryDate = document.getElementById( 'nvs-expiry-date' );

	function syncExpiry() {
		if ( expiry && expiryWrap ) {
			expiryWrap.hidden = expiry.value !== 'custom';
		}
	}
	if ( expiry ) {
		expiry.addEventListener( 'change', function () {
			syncExpiry();
			if ( expiry.value === 'custom' && expiryDate ) {
				expiryDate.focus();
			}
		} );
	}
	syncExpiry();

	/* ---------- Existing files ---------- */

	$$( '.nvs-remove-toggle input', form ).forEach( function ( box ) {
		box.addEventListener( 'change', function () {
			var item = box.closest( '.nvs-fileitem' );
			item.classList.toggle( 'is-marked', box.checked );
			$( 'span', box.parentNode ).textContent = box.checked ? __( 'Keep', 'nemvo-share' ) : __( 'Remove', 'nemvo-share' );
		} );
	} );

	/* ---------- Uploads ---------- */

	var dropzone = document.getElementById( 'nvs-dropzone' );
	var input = document.getElementById( 'nvs-file-input' );
	var list = document.getElementById( 'nvs-uploads' );
	var items = [];
	var active = null;

	if ( input ) {
		// Files go up through the REST API, not with the form.
		input.removeAttribute( 'name' );
		input.setAttribute( 'tabindex', '-1' );
		var chooser = $( '.nvs-dropzone-button', dropzone );
		if ( chooser ) {
			chooser.setAttribute( 'tabindex', '0' );
			chooser.setAttribute( 'role', 'button' );
			chooser.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' || event.key === ' ' ) {
					event.preventDefault();
					input.click();
				}
			} );
		}
		input.addEventListener( 'change', function () {
			addFiles( input.files );
			input.value = '';
		} );
	}

	function pending() {
		return items.filter( function ( item ) {
			return item.state === 'waiting' || item.state === 'uploading';
		} );
	}

	function addFiles( fileList ) {
		Array.prototype.forEach.call( fileList || [], function ( file ) {
			var item = {
				file: file,
				state: 'waiting',
				sent: 0,
				id: null,
				chunk: 0,
				xhr: null,
				hidden: null,
				attempts: 0,
			};
			item.row = renderRow( item );
			list.appendChild( item.row );
			items.push( item );

			if ( cfg.maxBytes > 0 && file.size > cfg.maxBytes ) {
				/* translators: %s: size limit. */
				fail( item, sprintf( __( 'Larger than the limit of %s per file.', 'nemvo-share' ), formatSize( cfg.maxBytes ) ) );
			} else if ( cfg.free !== null && cfg.free !== undefined && file.size > cfg.free ) {
				fail( item, __( 'There is not enough free disk space on the server for this file.', 'nemvo-share' ) );
			}
		} );
		pump();
	}

	function renderRow( item ) {
		var row = el( 'li', 'nvs-fileitem is-waiting' );
		var icon = el( 'span', 'dashicons dashicons-media-default' );
		icon.setAttribute( 'aria-hidden', 'true' );
		item.nameEl = el( 'span', 'nvs-fileitem-name', item.file.name );
		item.nameEl.title = item.file.name;
		item.metaEl = el( 'span', 'nvs-fileitem-meta' );
		var cancel = el( 'button', 'nvs-fileitem-cancel', __( 'Remove', 'nemvo-share' ) );
		cancel.type = 'button';
		/* translators: %s: file name. */
		cancel.setAttribute( 'aria-label', sprintf( __( 'Remove %s', 'nemvo-share' ), item.file.name ) );
		cancel.addEventListener( 'click', function () {
			remove( item );
		} );
		var progress = el( 'span', 'nvs-progress' );
		progress.setAttribute( 'role', 'progressbar' );
		progress.setAttribute( 'aria-valuemin', '0' );
		progress.setAttribute( 'aria-valuemax', '100' );
		/* translators: %s: file name. */
		progress.setAttribute( 'aria-label', sprintf( __( 'Uploading %s', 'nemvo-share' ), item.file.name ) );
		item.bar = el( 'span', 'nvs-progress-bar' );
		item.progressEl = progress;
		progress.appendChild( item.bar );
		row.appendChild( icon );
		row.appendChild( item.nameEl );
		row.appendChild( item.metaEl );
		row.appendChild( cancel );
		row.appendChild( progress );
		item.metaEl.textContent = formatSize( item.file.size ) + ' · ' + __( 'Waiting…', 'nemvo-share' );
		return row;
	}

	function setProgress( item, sent ) {
		item.sent = sent;
		var size = item.file.size || 1;
		var pct = Math.min( 100, Math.floor( ( sent / size ) * 100 ) );
		item.bar.style.width = pct + '%';
		item.progressEl.setAttribute( 'aria-valuenow', String( pct ) );
		var parts = [ sprintf( /* translators: 1: uploaded size, 2: total size. */ __( '%1$s of %2$s', 'nemvo-share' ), formatSize( sent ), formatSize( item.file.size ) ) ];
		var elapsed = ( Date.now() - item.started ) / 1000;
		if ( elapsed > 1 && sent > 0 ) {
			var speed = sent / elapsed;
			parts.push( formatSize( speed ) + '/s' );
			parts.push( formatDuration( ( item.file.size - sent ) / speed ) );
		}
		item.metaEl.textContent = parts.join( ' · ' );
		updateSubmit();
	}

	function fail( item, message ) {
		item.state = 'error';
		item.row.classList.remove( 'is-waiting', 'is-uploading' );
		item.row.classList.add( 'is-error' );
		item.metaEl.textContent = message;
		if ( item.id ) {
			request( 'DELETE', cfg.rest + '/' + item.id );
		}
		if ( active === item ) {
			active = null;
		}
		updateSubmit();
		pump();
	}

	function done( item ) {
		item.state = 'done';
		item.row.classList.remove( 'is-waiting', 'is-uploading' );
		item.row.classList.add( 'is-done' );
		item.bar.style.width = '100%';
		item.metaEl.textContent = formatSize( item.file.size ) + ' · ' + __( 'Uploaded', 'nemvo-share' );
		var icon = $( '.dashicons', item.row );
		icon.className = 'dashicons dashicons-yes-alt';
		item.hidden = el( 'input' );
		item.hidden.type = 'hidden';
		item.hidden.name = 'upload_ids[]';
		item.hidden.value = item.id;
		form.appendChild( item.hidden );
		active = null;
		updateSubmit();
		pump();
	}

	function remove( item ) {
		if ( item.xhr ) {
			item.xhr.abort();
		}
		if ( item.id ) {
			request( 'DELETE', cfg.rest + '/' + item.id );
		}
		if ( item.hidden ) {
			item.hidden.remove();
		}
		item.state = 'removed';
		item.row.remove();
		items = items.filter( function ( other ) {
			return other !== item;
		} );
		if ( active === item ) {
			active = null;
		}
		updateSubmit();
		pump();
	}

	function request( method, url, body, onDone ) {
		var xhr = new XMLHttpRequest();
		xhr.open( method, url );
		xhr.setRequestHeader( 'X-WP-Nonce', cfg.nonce );
		if ( body && ! ( body instanceof FormData ) ) {
			xhr.setRequestHeader( 'Content-Type', 'application/json' );
			body = JSON.stringify( body );
		}
		xhr.onload = function () {
			var data = null;
			try {
				data = JSON.parse( xhr.responseText );
			} catch ( e ) {}
			if ( onDone ) {
				onDone( xhr.status, data );
			}
		};
		xhr.onerror = function () {
			if ( onDone ) {
				onDone( 0, null );
			}
		};
		xhr.send( body || null );
		return xhr;
	}

	function errorMessage( status, data ) {
		if ( data && data.message ) {
			return data.message;
		}
		if ( status === 413 ) {
			return __( 'The server refused a piece of the file as too large.', 'nemvo-share' );
		}
		if ( status === 0 ) {
			return __( 'The connection was lost.', 'nemvo-share' );
		}
		/* translators: %d: HTTP status code. */
		return sprintf( __( 'The upload failed (error %d).', 'nemvo-share' ), status );
	}

	function pump() {
		if ( active ) {
			return;
		}
		var next = items.filter( function ( item ) {
			return item.state === 'waiting';
		} )[ 0 ];
		if ( ! next ) {
			updateSubmit();
			return;
		}
		active = next;
		next.state = 'uploading';
		next.started = Date.now();
		next.row.classList.remove( 'is-waiting' );
		next.row.classList.add( 'is-uploading' );
		next.metaEl.textContent = __( 'Starting…', 'nemvo-share' );
		next.xhr = request( 'POST', cfg.rest, { name: next.file.name, size: next.file.size, type: next.file.type || '' }, function ( status, data ) {
			if ( next.state !== 'uploading' ) {
				return;
			}
			if ( status !== 200 || ! data || ! data.id ) {
				fail( next, errorMessage( status, data ) );
				return;
			}
			next.id = data.id;
			next.chunk = data.chunk;
			if ( data.complete ) {
				done( next );
				return;
			}
			sendChunk( next, 0 );
		} );
	}

	function sendChunk( item, index ) {
		var start = index * item.chunk;
		var end = Math.min( start + item.chunk, item.file.size );
		var body = new FormData();
		body.append( 'index', String( index ) );
		body.append( 'chunk', item.file.slice( start, end ), 'chunk' );

		var xhr = new XMLHttpRequest();
		item.xhr = xhr;
		xhr.open( 'POST', cfg.rest + '/' + item.id );
		xhr.setRequestHeader( 'X-WP-Nonce', cfg.nonce );
		xhr.upload.onprogress = function ( event ) {
			if ( event.lengthComputable ) {
				// The request carries a little form overhead; never show more than this chunk.
				setProgress( item, start + Math.min( end - start, event.loaded ) );
			}
		};
		xhr.onload = function () {
			var data = null;
			try {
				data = JSON.parse( xhr.responseText );
			} catch ( e ) {}
			if ( item.state !== 'uploading' ) {
				return;
			}
			if ( xhr.status === 200 && data ) {
				item.attempts = 0;
				setProgress( item, data.received );
				if ( data.complete ) {
					done( item );
				} else {
					sendChunk( item, Math.floor( data.received / item.chunk ) );
				}
				return;
			}
			retry( item, index, xhr.status, data );
		};
		xhr.onerror = function () {
			if ( item.state === 'uploading' ) {
				retry( item, index, 0, null );
			}
		};
		xhr.send( body );
	}

	function retry( item, index, status, data ) {
		// Network trouble and server hiccups are retried; refusals are not.
		var permanent = status >= 400 && status < 500 && status !== 408 && status !== 429;
		if ( permanent || item.attempts >= 4 ) {
			fail( item, errorMessage( status, data ) );
			return;
		}
		item.attempts++;
		item.metaEl.textContent = __( 'Connection problem, trying again…', 'nemvo-share' );
		setTimeout( function () {
			if ( item.state === 'uploading' ) {
				sendChunk( item, index );
			}
		}, 1000 * item.attempts * item.attempts );
	}

	function updateSubmit() {
		if ( ! submit ) {
			return;
		}
		var busy = pending();
		if ( busy.length ) {
			var total = 0;
			var sent = 0;
			busy.forEach( function ( item ) {
				total += item.file.size;
				sent += item.sent;
			} );
			var pct = total ? Math.floor( ( sent / total ) * 100 ) : 0;
			submit.disabled = true;
			/* translators: %d: percentage. */
			submit.textContent = sprintf( __( 'Uploading… %d%%', 'nemvo-share' ), pct );
			setStatus( __( 'The share can be saved when the uploads are finished.', 'nemvo-share' ) );
		} else {
			submit.disabled = false;
			submit.textContent = submitLabel;
			if ( submitStatus && ! submitStatus.classList.contains( 'is-error' ) ) {
				setStatus( '' );
			}
		}
	}

	if ( dropzone ) {
		var depth = 0;
		var hasFiles = function ( event ) {
			return event.dataTransfer && Array.prototype.indexOf.call( event.dataTransfer.types || [], 'Files' ) !== -1;
		};
		// Files can be dropped anywhere on the screen, not just on the zone.
		document.addEventListener( 'dragenter', function ( event ) {
			if ( hasFiles( event ) ) {
				depth++;
				dropzone.classList.add( 'is-dragover' );
			}
		} );
		document.addEventListener( 'dragleave', function ( event ) {
			if ( hasFiles( event ) && --depth <= 0 ) {
				depth = 0;
				dropzone.classList.remove( 'is-dragover' );
			}
		} );
		document.addEventListener( 'dragover', function ( event ) {
			if ( hasFiles( event ) ) {
				event.preventDefault();
				event.dataTransfer.dropEffect = 'copy';
			}
		} );
		document.addEventListener( 'drop', function ( event ) {
			if ( hasFiles( event ) ) {
				event.preventDefault();
				depth = 0;
				dropzone.classList.remove( 'is-dragover' );
				addFiles( event.dataTransfer.files );
			}
		} );
	}

	/* ---------- Saving ---------- */

	form.addEventListener( 'submit', function ( event ) {
		if ( pending().length ) {
			event.preventDefault();
			return;
		}
		var note = document.getElementById( 'nvs-note' );
		var hasNote = note && note.value.trim() !== '';
		var uploaded = items.some( function ( item ) {
			return item.state === 'done';
		} );
		var kept = $$( '.nvs-filelist--existing .nvs-fileitem', form ).some( function ( row ) {
			var box = $( 'input[type="checkbox"]', row );
			return box && ! box.checked;
		} );
		if ( ! hasNote && ! uploaded && ! kept ) {
			event.preventDefault();
			setStatus( __( 'Add a note or at least one file.', 'nemvo-share' ), true );
			if ( note ) {
				note.focus();
			}
			return;
		}
		if ( expiry && expiry.value === 'custom' && expiryDate ) {
			var when = new Date( expiryDate.value );
			if ( ! expiryDate.value || isNaN( when.getTime() ) ) {
				event.preventDefault();
				setStatus( __( 'Pick the date and time when the share should expire.', 'nemvo-share' ), true );
				expiryDate.focus();
				return;
			}
		}
		submitting = true;
		submit.disabled = true;
		setStatus( __( 'Saving…', 'nemvo-share' ) );
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		var unsaved = items.some( function ( item ) {
			return item.state === 'uploading' || item.state === 'waiting' || item.state === 'done';
		} );
		if ( unsaved && ! submitting ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
} )();
