/**
 * Admin behaviour for Demfilz SMTP Mailer.
 */
( function () {
	'use strict';

	var strings = window.dsmtpAdmin || {};

	function showPanels() {
		var selected = document.querySelector( 'input[data-dsmtp-mailer]:checked' );
		var panels = document.querySelectorAll( '[data-dsmtp-panel]' );
		var cards = document.querySelectorAll( '.dsmtp-mailer-card' );
		var value = selected ? selected.value : '';
		var i;

		for ( i = 0; i < panels.length; i++ ) {
			panels[ i ].hidden = panels[ i ].getAttribute( 'data-dsmtp-panel' ) !== value;
		}

		for ( i = 0; i < cards.length; i++ ) {
			var input = cards[ i ].querySelector( 'input[data-dsmtp-mailer]' );

			cards[ i ].classList.toggle( 'is-selected', !! ( input && input.checked ) );
		}
	}

	function applyDependencies() {
		var rows = document.querySelectorAll( '[data-dsmtp-depends]' );

		for ( var i = 0; i < rows.length; i++ ) {
			var row = rows[ i ];
			var panel = row.closest( '[data-dsmtp-panel]' );
			var depends;

			try {
				depends = JSON.parse( row.getAttribute( 'data-dsmtp-depends' ) );
			} catch ( error ) {
				continue;
			}

			if ( ! panel || ! depends ) {
				continue;
			}

			var visible = true;

			Object.keys( depends ).forEach( function ( key ) {
				var field = panel.querySelector( '[name$="[' + key + ']"]' );

				if ( ! field ) {
					return;
				}

				var current = 'checkbox' === field.type ? ( field.checked ? 1 : 0 ) : field.value;

				if ( String( current ) !== String( depends[ key ] ) ) {
					visible = false;
				}
			} );

			row.hidden = ! visible;
		}
	}

	function copyValue( button ) {
		var value = button.getAttribute( 'data-dsmtp-target' ) || '';
		var reset = function () {
			button.textContent = strings.copied || 'Copied';

			window.setTimeout( function () {
				button.textContent = strings.copy || 'Copy';
			}, 1500 );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( value ).then( reset, function () {} );

			return;
		}

		var field = document.createElement( 'textarea' );

		field.value = value;
		document.body.appendChild( field );
		field.select();

		try {
			document.execCommand( 'copy' );
			reset();
		} catch ( error ) {
			// Nothing more we can do, the value is on screen to copy by hand.
		}

		document.body.removeChild( field );
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.matches( 'input[data-dsmtp-mailer]' ) ) {
			showPanels();
			applyDependencies();
		}

		if ( event.target.closest( '[data-dsmtp-panel]' ) ) {
			applyDependencies();
		}

		if ( event.target.matches( '[data-dsmtp-check-all]' ) ) {
			var boxes = document.querySelectorAll( 'input[name="dsmtp_ids[]"]' );

			for ( var i = 0; i < boxes.length; i++ ) {
				boxes[ i ].checked = event.target.checked;
			}
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var copy = event.target.closest( '.dsmtp-copy-button' );

		if ( copy ) {
			event.preventDefault();
			copyValue( copy );

			return;
		}

		var confirmButton = event.target.closest( '[data-dsmtp-confirm]' );

		if ( confirmButton ) {
			var kind = confirmButton.getAttribute( 'data-dsmtp-confirm' );
			var message = strings.confirmDelete;

			if ( 'clear' === kind ) {
				message = strings.confirmClear;
			} else if ( 'token' === kind ) {
				message = strings.confirmToken;
			}

			if ( ! window.confirm( message ) ) {
				event.preventDefault();
			}
		}
	} );

	showPanels();
	applyDependencies();
}() );
