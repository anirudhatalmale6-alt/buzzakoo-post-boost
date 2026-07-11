/**
 * Buzzakoo Post Boost — front-end.
 *
 * Buttons render disabled and label-less-of-state on the server. This script fetches
 * their real state on load, which is what keeps them correct on a fully cached page.
 */
( function () {
	'use strict';

	if ( typeof window.BZK_BOOST === 'undefined' ) {
		return;
	}

	var cfg = window.BZK_BOOST;
	var nonce = cfg.nonce;

	function buttons() {
		return Array.prototype.slice.call( document.querySelectorAll( '.bzk-boost-btn' ) );
	}

	function key( btn ) {
		return btn.getAttribute( 'data-bzk-type' ) + ':' + btn.getAttribute( 'data-bzk-id' );
	}

	function message( btn, text, isError ) {
		var wrap = btn.closest( '.bzk-boost-wrap' );
		if ( ! wrap ) {
			return;
		}
		var msg = wrap.querySelector( '.bzk-boost-msg' );
		if ( ! msg ) {
			return;
		}
		msg.textContent = text || '';
		msg.classList.toggle( 'is-error', !! isError );

		if ( text ) {
			window.setTimeout( function () {
				if ( msg.textContent === text ) {
					msg.textContent = '';
					msg.classList.remove( 'is-error' );
				}
			}, 5000 );
		}
	}

	/**
	 * Paint one button from a state object returned by the API.
	 */
	function paint( btn, state ) {
		var label = btn.querySelector( '.bzk-boost-label' );
		var count = btn.querySelector( '.bzk-boost-count' );

		btn.classList.toggle( 'is-boosted', !! state.boosted );

		// Paid mode: the button carries a price and goes to checkout, not to /boost.
		btn.dataset.bzkPay = state.requires_payment ? '1' : '';
		btn.dataset.bzkCheckout = state.checkout_url || '';
		btn.classList.toggle( 'is-paid', !! state.requires_payment );

		if ( label ) {
			if ( state.boosted ) {
				label.textContent = cfg.i18n.boosted;
			} else if ( state.requires_payment && state.price_label ) {
				label.textContent = state.price_label;
			} else {
				label.textContent = cfg.i18n.boost;
			}
		}

		if ( count ) {
			if ( cfg.showCount && state.count > 0 ) {
				count.textContent = state.count;
				count.hidden = false;
			} else {
				count.hidden = true;
			}
		}

		// A boosted item can still be re-boosted later; only block while it genuinely can't.
		btn.disabled = ! state.can_boost;
		btn.title = state.can_boost ? '' : ( state.reason || '' );
		btn.setAttribute( 'aria-disabled', state.can_boost ? 'false' : 'true' );
	}

	function loadState() {
		var all = buttons();
		if ( ! all.length ) {
			return;
		}

		var items = all.map( key ).filter( function ( v, i, a ) {
			return a.indexOf( v ) === i;
		} );

		var url = cfg.root + '/state?items=' + encodeURIComponent( items.join( ',' ) );

		fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': nonce }
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				if ( ! data || ! data.items ) {
					return;
				}
				// Replace the possibly-cached page nonce with the fresh one.
				if ( data.nonce ) {
					nonce = data.nonce;
				}
				buttons().forEach( function ( btn ) {
					var state = data.items[ key( btn ) ];
					if ( state ) {
						paint( btn, state );
					}
				} );
			} )
			.catch( function () {
				/* Leave the buttons disabled if state can't be read. */
			} );
	}

	function boost( btn ) {
		if ( btn.disabled || btn.dataset.bzkBusy === '1' ) {
			return;
		}

		// Paid boost: straight to checkout. Nothing is boosted until the money lands.
		if ( btn.dataset.bzkPay === '1' && btn.dataset.bzkCheckout ) {
			window.location.href = btn.dataset.bzkCheckout;
			return;
		}

		btn.dataset.bzkBusy = '1';
		btn.disabled = true;

		var label = btn.querySelector( '.bzk-boost-label' );
		var previous = label ? label.textContent : '';
		if ( label ) {
			label.textContent = cfg.i18n.working;
		}

		fetch( cfg.root + '/boost', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce
			},
			body: JSON.stringify( {
				type: btn.getAttribute( 'data-bzk-type' ),
				id: parseInt( btn.getAttribute( 'data-bzk-id' ), 10 )
			} )
		} )
			.then( function ( r ) {
				return r.json().then( function ( body ) {
					return { ok: r.ok, body: body };
				} );
			} )
			.then( function ( res ) {
				btn.dataset.bzkBusy = '';

				/*
				 * Paid mode turned on (or the package changed) after this page was rendered
				 * or cached. The server tells us so; send them to checkout rather than
				 * showing a confusing error.
				 */
				if ( res.body && res.body.requires_payment && res.body.checkout_url ) {
					window.location.href = res.body.checkout_url;
					return;
				}

				if ( ! res.ok || ! res.body || ! res.body.success ) {
					if ( label ) {
						label.textContent = previous;
					}
					var reason = res.body && res.body.message ? res.body.message : cfg.i18n.error;
					message( btn, reason, true );
					btn.disabled = true;
					return;
				}

				paint( btn, res.body.state );
				message( btn, cfg.i18n.bumped, false );
				btn.classList.add( 'bzk-just-boosted' );
				window.setTimeout( function () {
					btn.classList.remove( 'bzk-just-boosted' );
				}, 900 );
			} )
			.catch( function () {
				btn.dataset.bzkBusy = '';
				btn.disabled = false;
				if ( label ) {
					label.textContent = previous;
				}
				message( btn, cfg.i18n.error, true );
			} );
	}

	// Delegated, so buttons added by BuddyPress's AJAX feed refresh keep working.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.bzk-boost-btn' ) : null;
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		boost( btn );
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', loadState );
	} else {
		loadState();
	}

	/*
	 * BuddyPress re-renders the activity stream over AJAX (filtering, "load more",
	 * posting an update). Those new buttons need their state too.
	 */
	if ( typeof jQuery !== 'undefined' ) {
		jQuery( document ).on( 'bp_ajax_request bp_activity_ajax_complete', function () {
			window.setTimeout( loadState, 150 );
		} );
	}
} )();
