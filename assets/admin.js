/**
 * Tools > Leftovers and the Plugins and Themes screens: the in-place confirm
 * step (with typed names for items whose owner is not certain), select all,
 * busy states, spoken results, the deactivation warning, the notice after an
 * AJAX delete, and the wp.hooks extension points.
 *
 * Filters (namespace axleLeftovers):
 * - axleLeftovers.confirmMessage( message, { items } ): the text of the trash confirmation.
 * Actions:
 * - axleLeftovers.trashed( { batch, items } ) after a batch was moved to the trash.
 * - axleLeftovers.restored( { restored, failed } ) after items were restored.
 *
 * wp.hooks is read when each hook runs, so scripts loaded later can still hook in.
 */
( function () {
	'use strict';

	const data = window.axleLeftoversData || { i18n: {} };
	const i18n = data.i18n || {};

	function hooks() {
		return window.wp && window.wp.hooks ? window.wp.hooks : null;
	}

	function applyFilters( name, value, ...args ) {
		const h = hooks();
		return h ? h.applyFilters( name, value, ...args ) : value;
	}

	function doAction( name, ...args ) {
		const h = hooks();
		if ( h ) {
			h.doAction( name, ...args );
		}
	}

	/** Announces a result to screen readers, when wp.a11y is loaded. */
	function speak( message, politeness ) {
		if ( message && window.wp && window.wp.a11y && 'function' === typeof window.wp.a11y.speak ) {
			window.wp.a11y.speak( message, politeness || 'polite' );
		}
	}

	function format( text, ...values ) {
		let index = 0;
		return String( text || '' ).replace( /%(\d+\$)?[ds]/g, ( match, position ) => {
			const value = position ? values[ parseInt( position, 10 ) - 1 ] : values[ index++ ];
			return undefined === value ? match : String( value );
		} );
	}

	function element( tag, attributes = {}, text = '' ) {
		const node = document.createElement( tag );
		Object.keys( attributes ).forEach( ( key ) => node.setAttribute( key, attributes[ key ] ) );
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	/** Select all, per table. */
	function selectAll() {
		document.querySelectorAll( '.alo-check-all' ).forEach( ( toggle ) => {
			toggle.addEventListener( 'change', () => {
				const table = toggle.closest( 'table' );
				table.querySelectorAll( 'tbody input[type="checkbox"]:not(:disabled)' ).forEach( ( box ) => {
					box.checked = toggle.checked;
				} );
			} );
		} );
	}

	/**
	 * The confirm step, shown in place: one panel that moves to sit under the
	 * button that asked, says what will change and whether it can be undone,
	 * and takes focus. Escape or Cancel puts focus back on the button.
	 */
	function confirmPanel() {
		const panel = document.getElementById( 'alo-confirm' );
		if ( ! panel ) {
			return null;
		}
		const title = panel.querySelector( '#alo-confirm-title' );
		const message = panel.querySelector( '#alo-confirm-message' );
		const list = panel.querySelector( '.alo-confirm-list' );
		const typed = panel.querySelector( '.alo-confirm-typed' );
		const error = panel.querySelector( '.alo-confirm-error' );
		const ok = panel.querySelector( '.alo-confirm-ok' );
		let current = null;

		function close( restoreFocus ) {
			panel.hidden = true;
			if ( current && restoreFocus && current.opener ) {
				current.opener.focus();
			}
			current = null;
		}

		/** Shows the panel under `anchor`. options: opener, title, message, items, okLabel, danger, onConfirm. */
		function open( anchor, options ) {
			current = options;
			anchor.insertAdjacentElement( 'afterend', panel );
			title.textContent = options.title || '';
			message.textContent = options.message || '';
			list.textContent = '';
			typed.textContent = '';
			error.textContent = '';
			( options.items || [] ).slice( 0, 50 ).forEach( ( item ) => list.appendChild( element( 'li', {}, item.name ) ) );
			list.hidden = ! ( options.items || [] ).length;
			( options.items || [] ).filter( ( item ) => item.typed ).forEach( ( item, index ) => {
				if ( ! index ) {
					typed.appendChild( element( 'p', {}, i18n.typeName || '' ) );
				}
				const id = `alo-type-${ index }`;
				const row = element( 'p' );
				row.appendChild( element( 'label', { for: id }, format( i18n.typeLabel, item.name ) ) );
				row.appendChild( document.createElement( 'br' ) );
				row.appendChild( element( 'input', { type: 'text', id, class: 'regular-text alo-typed-name', 'data-expected': item.name, autocomplete: 'off', spellcheck: 'false' } ) );
				typed.appendChild( row );
			} );
			ok.textContent = options.okLabel || '';
			ok.hidden = ! options.onConfirm;
			ok.className = 'button alo-confirm-ok ' + ( options.danger ? 'axle-button--danger' : 'button-primary' );
			panel.classList.toggle( 'axle-confirm--danger', !! options.danger );
			panel.hidden = false;
			speak( options.message || options.title );
			( typed.querySelector( 'input' ) || ( options.onConfirm ? ok : title ) ).focus();
		}

		ok.addEventListener( 'click', () => {
			if ( ! current ) {
				return;
			}
			const inputs = Array.from( typed.querySelectorAll( '.alo-typed-name' ) );
			const wrong = inputs.find( ( input ) => input.value.trim() !== input.dataset.expected );
			if ( wrong ) {
				error.textContent = i18n.mismatch || '';
				wrong.setAttribute( 'aria-invalid', 'true' );
				wrong.focus();
				return;
			}
			const done = current.onConfirm;
			const names = inputs.map( ( input ) => input.value.trim() );
			close( false );
			done( names );
		} );
		panel.querySelector( '.alo-confirm-cancel' ).addEventListener( 'click', () => close( true ) );
		panel.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				close( true );
			}
		} );

		return { open, close };
	}

	/** The trash confirmation: lists the items, asks for typed names, says it can be undone. */
	function trashConfirmation( panel ) {
		document.querySelectorAll( '.alo-trash-form' ).forEach( ( form ) => {
			form.addEventListener( 'submit', ( event ) => {
				if ( form.dataset.aloConfirmed ) {
					return;
				}
				event.preventDefault();
				if ( ! panel ) {
					return;
				}
				const boxes = Array.from( form.querySelectorAll( 'input[name="items[]"]:checked' ) );
				const items = boxes.map( ( box ) => ( { id: box.value, name: box.dataset.name, typed: '1' === box.dataset.typed } ) );
				const opener = form.querySelector( '.alo-trash-button' );
				const anchor = opener.closest( 'p' ) || opener;

				if ( ! items.length ) {
					panel.open( anchor, { opener, title: i18n.nothing, message: '', items: [] } );
					return;
				}
				const text = 1 === items.length ? i18n.confirmOne : format( i18n.confirm, items.length );
				panel.open( anchor, {
					opener,
					title: i18n.trashTitle,
					message: applyFilters( 'axleLeftovers.confirmMessage', text, { items } ),
					items,
					okLabel: i18n.trashOk,
					onConfirm( names ) {
						const holder = form.querySelector( '.alo-confirm-names' );
						holder.textContent = '';
						names.forEach( ( name ) => holder.appendChild( element( 'input', { type: 'hidden', name: 'confirm_names[]', value: name } ) ) );
						form.dataset.aloConfirmed = '1';
						form.requestSubmit ? form.requestSubmit() : form.submit();
					},
				} );
			} );
		} );
	}

	/** Buttons that delete for good ask first, in place. */
	function confirmButtons( panel ) {
		document.querySelectorAll( '[data-alo-confirm]' ).forEach( ( button ) => {
			button.addEventListener( 'click', ( event ) => {
				if ( button.dataset.aloConfirmed || ! panel ) {
					return;
				}
				event.preventDefault();
				const form = button.closest( 'form' );
				panel.open( button.closest( '.axle-actions, p' ) || button, {
					opener: button,
					title: button.getAttribute( 'data-alo-confirm-title' ) || i18n.deleteTitle,
					message: button.getAttribute( 'data-alo-confirm' ),
					okLabel: button.getAttribute( 'data-alo-confirm-label' ) || button.textContent.trim(),
					danger: true,
					onConfirm() {
						button.dataset.aloConfirmed = '1';
						form.requestSubmit ? form.requestSubmit( button ) : form.submit();
					},
				} );
			} );
		} );
	}

	/** After a submit that takes a while: a spinner, aria-busy, a spoken note, and no double submit. */
	function busyForms() {
		document.querySelectorAll( '.alo form' ).forEach( ( form ) => {
			form.addEventListener( 'submit', ( event ) => {
				if ( event.defaultPrevented ) {
					return;
				}
				if ( form.dataset.aloBusy ) {
					event.preventDefault();
					return;
				}
				form.dataset.aloBusy = '1';
				const button = event.submitter || form.querySelector( 'button[type="submit"], input[type="submit"]' );
				if ( button ) {
					button.setAttribute( 'aria-busy', 'true' );
					button.insertAdjacentElement( 'afterend', element( 'span', { class: 'spinner is-active alo-spinner', 'aria-hidden': 'true' } ) );
				}
				speak( i18n.working );
			} );
		} );
		// The browser can bring this page back from its cache: forget the busy state.
		window.addEventListener( 'pageshow', () => {
			document.querySelectorAll( '.alo form' ).forEach( ( form ) => {
				delete form.dataset.aloBusy;
				delete form.dataset.aloConfirmed;
				form.querySelectorAll( '.alo-spinner' ).forEach( ( spinner ) => spinner.remove() );
				form.querySelectorAll( '[aria-busy]' ).forEach( ( button ) => button.removeAttribute( 'aria-busy' ) );
			} );
		} );
	}

	/** Fires axleLeftovers.trashed and axleLeftovers.restored after the screen reloads, and speaks the notice. */
	function events() {
		document.querySelectorAll( '.alo-notice' ).forEach( ( notice ) => {
			speak( notice.textContent.replace( /\s+/g, ' ' ).trim(), 'error' === notice.dataset.aloTone ? 'assertive' : 'polite' );
			if ( ! notice.hasAttribute( 'data-alo-event' ) ) {
				return;
			}
			let payload = {};
			try {
				payload = JSON.parse( notice.getAttribute( 'data-alo-payload' ) || '{}' );
			} catch ( e ) {
				payload = {};
			}
			doAction( `axleLeftovers.${ notice.getAttribute( 'data-alo-event' ) }`, payload );
		} );
	}

	/** Plugins screen: ask before deactivating while the trash holds items, in a row under the plugin. */
	function deactivation() {
		const panel = document.getElementById( 'alo-deactivate' );
		if ( ! panel ) {
			return;
		}
		const row = document.querySelector( `tr[data-plugin="${ panel.getAttribute( 'data-plugin' ) }"]` );
		const link = row ? row.querySelector( '.deactivate a' ) : null;
		if ( ! link ) {
			return;
		}
		let holder = null;

		function close() {
			panel.hidden = true;
			// Back to the page footer, so the next click finds it again.
			document.body.appendChild( panel );
			if ( holder ) {
				holder.remove();
				holder = null;
			}
			link.focus();
		}

		link.addEventListener( 'click', ( event ) => {
			event.preventDefault();
			panel.querySelector( '.alo-deactivate-anyway' ).setAttribute( 'href', link.getAttribute( 'href' ) );
			holder = element( 'tr', { class: 'alo-confirm-row' } );
			const cell = element( 'td', { colspan: String( row.children.length ), class: 'axle-ui' } );
			holder.appendChild( cell );
			cell.appendChild( panel );
			row.insertAdjacentElement( 'afterend', holder );
			panel.hidden = false;
			speak( panel.querySelector( 'p' ).textContent );
			panel.querySelector( '#alo-deactivate-title' ).focus();
		} );
		panel.querySelector( '.alo-confirm-cancel' ).addEventListener( 'click', close );
		panel.querySelector( '.alo-deactivate-restore' ).addEventListener( 'click', ( event ) => {
			const form = document.getElementById( event.currentTarget.getAttribute( 'data-form' ) );
			if ( form ) {
				event.currentTarget.disabled = true;
				form.submit();
			}
		} );
		panel.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				close();
			}
		} );
	}

	/** Plugins and Themes screens: show what a plugin or theme deleted over AJAX left behind. */
	function ajaxDeletes() {
		if ( ! window.jQuery || ! window.wp || ! window.wp.apiFetch || ! data.noticeRoute ) {
			return;
		}
		window.jQuery( document ).on( 'wp-plugin-delete-success wp-theme-delete-success', () => {
			const anchor = document.querySelector( '.wp-header-end' ) || document.querySelector( '.wrap h1' );
			window.wp
				.apiFetch( { path: data.noticeRoute } )
				.then( ( response ) => {
					( response.html || [] ).forEach( ( html ) => {
						const holder = document.createElement( 'div' );
						holder.innerHTML = html;
						if ( anchor && holder.firstElementChild ) {
							anchor.parentNode.insertBefore( holder.firstElementChild, anchor.nextSibling );
							speak( holder.textContent );
						}
					} );
				} )
				.catch( () => {
					// Say that the look failed and what to do next, instead of showing nothing.
					if ( anchor ) {
						const notice = element( 'div', { class: 'notice notice-error is-dismissible alo-deleted-notice', role: 'alert' } );
						notice.appendChild( element( 'p', {}, i18n.deleteError || '' ) );
						anchor.parentNode.insertBefore( notice, anchor.nextSibling );
						speak( i18n.deleteError, 'assertive' );
					}
				} );
		} );
	}

	function init() {
		const panel = confirmPanel();
		selectAll();
		trashConfirmation( panel );
		confirmButtons( panel );
		busyForms();
		events();
		deactivation();
		ajaxDeletes();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
