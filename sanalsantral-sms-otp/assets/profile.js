/* Profile: prove the mobile number by SMS before two-step login relies on it. */
( function () {
	'use strict';

	var cfg = window.ssSmsOtp;
	if ( ! cfg ) {
		return;
	}

	function $( id ) {
		return document.getElementById( id );
	}

	function post( action, data, done ) {
		var body = new URLSearchParams( data );
		body.append( 'action', action );
		body.append( '_ajax_nonce', cfg.nonce );
		fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( done )
			.catch( function () {
				done( { success: false, data: { message: 'Network error' } } );
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var send = $( 'ss-sms-verify-send' ),
			check = $( 'ss-sms-verify-check' ),
			box = $( 'ss-sms-verify' );
		if ( ! send || ! box ) {
			return;
		}
		var result = box.querySelector( '.ss-sms-verify-result' ),
			step2 = box.querySelector( '.ss-sms-verify-step2' );

		function show( res ) {
			result.textContent = res.data && res.data.message ? res.data.message : '';
			result.style.color = res.success ? '#008a20' : '#b32d2e';
		}

		send.addEventListener( 'click', function () {
			send.disabled = true;
			result.textContent = cfg.sending;
			result.style.color = '';
			post( 'ss_sms_otp_phone_send', { phone: $( 'ss_sms_phone' ).value }, function ( res ) {
				send.disabled = false;
				show( res );
				if ( res.success ) {
					step2.style.display = '';
					$( 'ss-sms-verify-code' ).focus();
				}
			} );
		} );

		check.addEventListener( 'click', function () {
			check.disabled = true;
			result.textContent = cfg.checking;
			result.style.color = '';
			post(
				'ss_sms_otp_phone_check',
				{ phone: $( 'ss_sms_phone' ).value, code: $( 'ss-sms-verify-code' ).value },
				function ( res ) {
					check.disabled = false;
					show( res );
					if ( res.success ) {
						step2.style.display = 'none';
						// Reload so the status rows reflect the verified number.
						setTimeout( function () {
							window.location.hash = 'ss-sms';
							window.location.reload();
						}, 800 );
					}
				}
			);
		} );

		// Enter in the code field verifies instead of submitting the profile form.
		$( 'ss-sms-verify-code' ).addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				check.click();
			}
		} );
	} );
}() );
