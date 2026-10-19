/* Character and SMS-part counter for textareas marked data-ss-sms-counter. */
( function () {
	'use strict';

	var cfg = window.ssSmsAdmin || { encoding: 0, counter: '%1$d / %2$d' };
	// GSM 03.38 basic set plus the Turkish single-shift letters the gateway
	// accepts in GSM mode; anything else forces Unicode billing.
	var GSM = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüàğĞıİşŞçÇ';
	var GSM_EXT = '^{}\\[~]|€';

	function count( text, encoding ) {
		var chars = 0, unicode = encoding === 1;
		for ( var i = 0; i < text.length; i++ ) {
			var c = text.charAt( i );
			if ( GSM_EXT.indexOf( c ) !== -1 ) {
				chars += 2;
			} else {
				if ( GSM.indexOf( c ) === -1 ) {
					unicode = true;
				}
				chars += 1;
			}
		}
		if ( unicode ) {
			chars = text.length;
		}
		var single = unicode ? 70 : 160, multi = unicode ? 67 : 153;
		return { chars: chars, parts: chars <= single ? 1 : Math.ceil( chars / multi ) };
	}

	function attach( el ) {
		var out = document.createElement( 'p' );
		out.className = 'description ss-sms-counter';
		el.parentNode.insertBefore( out, el.nextSibling );
		var update = function () {
			var r = count( el.value, cfg.encoding );
			out.textContent = cfg.counter.replace( '%1$d', r.chars ).replace( '%2$d', r.parts );
		};
		el.addEventListener( 'input', update );
		update();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var els = document.querySelectorAll( 'textarea[data-ss-sms-counter]' );
		for ( var i = 0; i < els.length; i++ ) {
			attach( els[ i ] );
		}
	} );
}() );
