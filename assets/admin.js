/**
 * Tools > Leftovers and the Plugins screen: the trash confirmation with typed
 * names, select all, the deactivation warning, the notice after an AJAX
 * delete, and the wp.hooks extension points.
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

	/** The trash confirmation, with a typed name for items whose owner is not certain. */
	function trashConfirmation() {
		const dialog = document.getElementById( 'alo-confirm' );
		if ( ! dialog || 'function' !== typeof dialog.showModal ) {
			return;
		}
		const message = dialog.querySelector( '#alo-confirm-message' );
		const list = dialog.querySelector( '.alo-confirm-list' );
		const typed = dialog.querySelector( '.alo-confirm-typed' );
		const error = dialog.querySelector( '.alo-confirm-error' );
		let form = null;

		document.querySelectorAll( '.alo-trash-form' ).forEach( ( candidate ) => {
			candidate.addEventListener( 'submit', ( event ) => {
				if ( candidate.dataset.aloConfirmed ) {
					return;
				}
				event.preventDefault();
				form = candidate;
				const boxes = Array.from( candidate.querySelectorAll( 'input[name="items[]"]:checked' ) );
				const items = boxes.map( ( box ) => ( { id: box.value, name: box.dataset.name, typed: '1' === box.dataset.typed } ) );

				list.textContent = '';
				typed.textContent = '';
				error.textContent = '';
				if ( ! items.length ) {
					message.textContent = i18n.nothing || '';
					dialog.querySelector( '.alo-confirm-ok' ).hidden = true;
					dialog.showModal();
					return;
				}
				dialog.querySelector( '.alo-confirm-ok' ).hidden = false;

				const text = 1 === items.length ? i18n.confirmOne : format( i18n.confirm, items.length );
				message.textContent = applyFilters( 'axleLeftovers.confirmMessage', text, { items } );
				items.slice( 0, 50 ).forEach( ( item ) => list.appendChild( element( 'li', {}, item.name ) ) );

				const needTyping = items.filter( ( item ) => item.typed );
				if ( needTyping.length ) {
					typed.appendChild( element( 'p', {}, i18n.typeName || '' ) );
					needTyping.forEach( ( item, index ) => {
						const id = `alo-type-${ index }`;
						const row = element( 'p' );
						row.appendChild( element( 'label', { for: id }, format( i18n.typeLabel, item.name ) ) );
						row.appendChild( document.createElement( 'br' ) );
						row.appendChild( element( 'input', { type: 'text', id, class: 'regular-text alo-typed-name', 'data-expected': item.name, autocomplete: 'off', spellcheck: 'false' } ) );
						typed.appendChild( row );
					} );
				}
				dialog.showModal();
				const first = typed.querySelector( 'input' );
				( first || dialog.querySelector( '.alo-confirm-ok' ) ).focus();
			} );
		} );

		dialog.querySelector( '.alo-confirm-ok' ).addEventListener( 'click', () => {
			if ( ! form ) {
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
			const holder = form.querySelector( '.alo-confirm-names' );
			holder.textContent = '';
			inputs.forEach( ( input ) => holder.appendChild( element( 'input', { type: 'hidden', name: 'confirm_names[]', value: input.value.trim() } ) ) );
			form.dataset.aloConfirmed = '1';
			dialog.close();
			form.submit();
		} );

		dialog.querySelector( '.alo-confirm-cancel' ).addEventListener( 'click', () => dialog.close() );
	}

	/** Buttons that delete for good ask first. */
	function confirmButtons() {
		document.querySelectorAll( '[data-alo-confirm]' ).forEach( ( button ) => {
			button.addEventListener( 'click', ( event ) => {
				// eslint-disable-next-line no-alert
				if ( ! window.confirm( button.getAttribute( 'data-alo-confirm' ) ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	/** Fires axleLeftovers.trashed and axleLeftovers.restored after the screen reloads. */
	function events() {
		document.querySelectorAll( '.alo-notice[data-alo-event]' ).forEach( ( notice ) => {
			let payload = {};
			try {
				payload = JSON.parse( notice.getAttribute( 'data-alo-payload' ) || '{}' );
			} catch ( e ) {
				payload = {};
			}
			doAction( `axleLeftovers.${ notice.getAttribute( 'data-alo-event' ) }`, payload );
		} );
	}

	/** Plugins screen: ask before deactivating while the trash holds items. */
	function deactivation() {
		const dialog = document.getElementById( 'alo-deactivate' );
		if ( ! dialog || 'function' !== typeof dialog.showModal ) {
			return;
		}
		const row = document.querySelector( `tr[data-plugin="${ dialog.getAttribute( 'data-plugin' ) }"]` );
		const link = row ? row.querySelector( '.deactivate a' ) : null;
		if ( ! link ) {
			return;
		}
		link.addEventListener( 'click', ( event ) => {
			event.preventDefault();
			dialog.querySelector( '.alo-deactivate-anyway' ).setAttribute( 'href', link.getAttribute( 'href' ) );
			dialog.showModal();
		} );
		dialog.querySelector( '.alo-confirm-cancel' ).addEventListener( 'click', () => dialog.close() );
	}

	/** Plugins screen: show what a plugin deleted over AJAX left behind. */
	function ajaxDeletes() {
		if ( ! window.jQuery || ! window.wp || ! window.wp.apiFetch || ! data.noticeRoute ) {
			return;
		}
		window.jQuery( document ).on( 'wp-plugin-delete-success', () => {
			window.wp
				.apiFetch( { path: data.noticeRoute } )
				.then( ( response ) => {
					const anchor = document.querySelector( '.wp-header-end' ) || document.querySelector( '.wrap h1' );
					( response.html || [] ).forEach( ( html ) => {
						const holder = document.createElement( 'div' );
						holder.innerHTML = html;
						if ( anchor && holder.firstElementChild ) {
							anchor.parentNode.insertBefore( holder.firstElementChild, anchor.nextSibling );
						}
					} );
				} )
				.catch( () => null );
		} );
	}

	function init() {
		selectAll();
		trashConfirmation();
		confirmButtons();
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
