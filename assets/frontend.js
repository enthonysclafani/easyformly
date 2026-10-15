/** Native form controls, conditional visibility, and recoverable submission state. */
( () => {
	'use strict';
	const states = new WeakMap();
	// Match PHP trim() so whitespace cannot change a conditional rule between browser and server.
	const trim = ( value ) => value.replace( /^[ \t\r\n\0\v]+|[ \t\r\n\0\v]+$/g, '' );
	const numeric = ( value ) => /^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/.test( trim( value ) ) && Number.isFinite( Number( value ) );
	// Cached markup from the previous version still has native fields without this attribute.
	const primaryControl = ( wrapper ) => wrapper.querySelector( '[data-easyformly-control]' ) || wrapper.querySelector( 'input, select, textarea' );

	function matches( actual, operator, expected ) {
		switch ( operator ) {
			case 'equals': return actual === expected;
			case 'not_equals': return actual !== expected;
			case 'contains': return actual.includes( expected );
			case 'empty': return actual === '';
			case 'not_empty': return actual !== '';
			case 'greater': return numeric( actual ) && Number( actual ) > Number( expected );
			case 'less': return numeric( actual ) && Number( actual ) < Number( expected );
			default: return false;
		}
	}

	function updateConditions( form ) {
		const state = states.get( form );
		const visible = {};
		state.config.conditions.order.forEach( ( name ) => {
			const field = state.fields.get( name );
			const wrapper = field?.wrapper;
			if ( ! wrapper ) {
				return;
			}
			const results = state.rules.get( name ).map( ( rule ) => {
				const source = state.fields.get( rule.source )?.control;
				let value = '';
				if ( source ) {
					value = trim( source.value );
					if ( source.type === 'checkbox' ) {
						value = source.checked ? '1' : '';
					}
				}
				return !! visible[ rule.source ] && matches( value, rule.operator, rule.value );
			} );
			visible[ name ] = ! results.length || ( state.config.mode === 'any' ? results.some( Boolean ) : results.every( Boolean ) );
			wrapper.hidden = ! visible[ name ];
			wrapper.querySelectorAll( 'input, select, textarea' ).forEach( ( control ) => {
				control.disabled = ! visible[ name ] || state.busy || state.pending !== null;
			} );
		} );
	}

	function mountCaptcha( form ) {
		const state = states.get( form );
		const widget = form.querySelector( '[data-easyformly-captcha]' );
		if ( ! widget || ! window.turnstile || state.widget !== undefined ) {
			return;
		}
		const response = form.elements.namedItem( 'captcha' );
		try {
			state.widget = window.turnstile.render( widget, {
				sitekey: widget.dataset.sitekey,
				action: widget.dataset.action,
				'response-field': false,
				callback: ( token ) => {
					response.value = token;
				},
				'expired-callback': () => {
					response.value = '';
				},
				'error-callback': () => {
					response.value = '';
				},
			} );
		} catch {
			form.querySelector( '.easyformly-result' ).textContent = state.config.messages.captchaUnavailable;
		}
	}

	window.easyformlyTurnstileReady = () => document.querySelectorAll( '.easyformly-form[data-easyformly]' ).forEach( ( form ) => {
		if ( states.has( form ) ) {
			mountCaptcha( form );
		}
	} );

	async function request( url, options = {}, timeout = 12000 ) {
		const controller = new AbortController();
		let timer;
		try {
			timer = setTimeout( () => controller.abort(), timeout );
			const response = await fetch( url, { ...options, signal: controller.signal, credentials: 'same-origin', cache: 'no-store' } );
			return { response, data: await response.json() };
		} finally {
			clearTimeout( timer );
		}
	}

	async function refreshToken( form ) {
		const state = states.get( form );
		const url = new URL( form.dataset.tokenUrl, document.baseURI );
		if ( state.clockReady ) {
			url.searchParams.set( 'clock', form.elements.namedItem( 'clock' ).value );
		}
		const { response, data } = await request( url, { headers: { Accept: 'application/json' } } );
		if ( ! response.ok || typeof data.nonce !== 'string' || typeof data.ticket !== 'string' || typeof data.clock !== 'string' || ! /^contact_[a-f0-9]{12}$/.test( data.honeypot ) || ! Number.isFinite( data.expires ) ) {
			throw new Error( 'token' );
		}
		form.elements.namedItem( 'nonce' ).value = data.nonce;
		form.elements.namedItem( 'ticket' ).value = data.ticket;
		form.elements.namedItem( 'clock' ).value = data.clock;
		state.clockReady = true;
		const trap = form.querySelector( '[data-easyformly-trap]' );
		trap.name = `honeypot[${ data.honeypot }]`;
		trap.dataset.easyformlyTrap = data.honeypot;
		// Use the server's lifetime and local elapsed time; do not depend on the visitor's clock matching the server.
		states.get( form ).refreshAt = Date.now() + ( Math.max( 0, data.expires - Number( data.ticket.split( '.' )[ 0 ] ) - 60 ) * 1000 );
	}

	function clearErrors( form ) {
		form.querySelectorAll( '.easyformly-error' ).forEach( ( element ) => {
			element.textContent = '';
		} );
		form.querySelectorAll( '[aria-invalid="true"]' ).forEach( ( element ) => element.removeAttribute( 'aria-invalid' ) );
	}

	function fieldErrors( form, errors ) {
		let first;
		Object.entries( errors || {} ).forEach( ( [ name, message ] ) => {
			const wrapper = [ ...form.querySelectorAll( '[data-field]' ) ].find( ( element ) => element.dataset.field === name );
			if ( ! wrapper ) {
				return;
			}
			const control = primaryControl( wrapper );
			wrapper.querySelector( '.easyformly-error' ).textContent = String( message );
			control.setAttribute( 'aria-invalid', 'true' );
			if ( ! wrapper.hidden && ! first ) {
				first = control;
			}
		} );
		return first || form.querySelector( '.easyformly-result' );
	}

	function resetCaptcha( form, state ) {
		if ( state.widget !== undefined && window.turnstile ) {
			form.elements.namedItem( 'captcha' ).value = '';
			window.turnstile.reset( state.widget );
		}
	}

	function mountPhone( field, locale ) {
		const select = field.wrapper.querySelector( '[data-easyformly-country]' );
		const search = field.wrapper.querySelector( '[data-easyformly-country-search]' );
		if ( ! select || ! search ) {
			return;
		}
		const initial = select.value;
		let names;
		try {
			names = new Intl.DisplayNames( [ locale ], { type: 'region' } );
		} catch {
			// Server-rendered country labels remain available in older browsers.
		}
		const empty = select.options[ 0 ].cloneNode( true );
		const options = [ ...select.options ].slice( 1 ).map( ( option ) => {
			const copy = option.cloneNode( true );
			if ( names ) {
				copy.textContent = `${ names.of( copy.value ) } (+${ copy.dataset.dial })`;
			}
			return copy;
		} ).sort( ( first, second ) => first.textContent.localeCompare( second.textContent ) );
		const rebuild = ( query = '', value = select.value ) => {
			const filtered = options.filter( ( option ) => option.value === value || `${ option.textContent } ${ option.value }`.toLocaleLowerCase().includes( query.toLocaleLowerCase() ) );
			select.replaceChildren( empty.cloneNode( true ), ...filtered.map( ( option ) => option.cloneNode( true ) ) );
			select.value = value;
		};
		rebuild();
		search.hidden = false;
		search.addEventListener( 'input', () => rebuild( search.value ) );
		select.addEventListener( 'change', () => {
			search.value = '';
			rebuild();
		} );
		const sync = () => {
			let value = field.control.value.replace( /[\s().-]/g, '' );
			if ( value.startsWith( '00' ) ) {
				value = `+${ value.slice( 2 ) }`;
			}
			if ( ! value.startsWith( '+' ) ) {
				return;
			}
			const candidates = options.filter( ( option ) => value.startsWith( `+${ option.dataset.dial }` ) );
			if ( ! candidates.length ) {
				return;
			}
			const longest = Math.max( ...candidates.map( ( option ) => option.dataset.dial.length ) );
			const matching = candidates.filter( ( option ) => option.dataset.dial.length === longest );
			const current = matching.find( ( option ) => option.value === select.value );
			const preferred = matching.find( ( option ) => [ 'US', 'GB', 'RU', 'FI', 'AU', 'FR', 'NO' ].includes( option.value ) );
			const country = current || preferred || matching[ 0 ];
			search.value = '';
			rebuild( '', country.value );
		};
		field.control.addEventListener( 'input', sync );
		field.control.addEventListener( 'change', sync );
		field.wrapper.closest( 'form' ).addEventListener( 'reset', () => {
			setTimeout( () => {
				search.value = '';
				rebuild( '', initial );
			}, 0 );
		} );
	}

	function initialize( form ) {
		if ( states.has( form ) ) {
			return;
		}
		let config;
		try {
			config = JSON.parse( form.dataset.easyformly );
		} catch {
			return;
		}
		const state = { config, busy: false, ready: null, refreshAt: 0, pending: null, uncertain: false, stopped: false, clockReady: false };
		state.fields = new Map( [ ...form.querySelectorAll( '[data-field]' ) ].map( ( wrapper ) => [ wrapper.dataset.field, { wrapper, control: primaryControl( wrapper ) } ] ) );
		state.rules = new Map( config.conditions.order.map( ( name ) => [ name, config.conditions.rules.filter( ( rule ) => rule.target === name ) ] ) );
		states.set( form, state );
		state.fields.forEach( ( field ) => mountPhone( field, config.locale ) );
		updateConditions( form );
		form.addEventListener( 'input', () => updateConditions( form ) );
		form.addEventListener( 'change', () => updateConditions( form ) );
		if ( ! config.preview ) {
			state.ready = refreshToken( form ).then( () => true, () => false );
			mountCaptcha( form );
		}
		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			if ( state.busy || state.stopped || config.preview ) {
				return;
			}
			clearErrors( form );
			updateConditions( form );
			if ( state.pending === null && ! form.reportValidity() ) {
				return;
			}
			const result = form.querySelector( '.easyformly-result' );

			if ( state.pending === null && form.querySelector( '[data-easyformly-captcha]' ) && ! form.elements.namedItem( 'captcha' ).value ) {
				result.textContent = config.messages.captcha;
				result.focus();
				return;
			}
			const button = form.querySelector( 'button[type="submit"]' );
			const fields = {};
			const countries = {};
			let focus;
			if ( state.pending === null ) {
				form.querySelectorAll( '[data-field]' ).forEach( ( wrapper ) => {
					const control = primaryControl( wrapper );
					if ( ! control.disabled ) {
						fields[ wrapper.dataset.field ] = control.value;
						if ( control.type === 'checkbox' ) {
							fields[ wrapper.dataset.field ] = control.checked ? '1' : '';
						}
						const country = wrapper.querySelector( '[data-easyformly-country]' );
						if ( country ) {
							countries[ wrapper.dataset.field ] = country.value;
						}
					}
				} );
			}
			state.busy = true;
			button.disabled = true;
			updateConditions( form );
			form.setAttribute( 'aria-busy', 'true' );
			result.textContent = config.messages.sending;
			try {
				await state.ready;
				if ( state.pending === null && Date.now() >= state.refreshAt ) {
					state.ready = refreshToken( form ).then( () => true, () => false );
					if ( ! await state.ready ) {
						throw new Error( 'token' );
					}
				}
				state.pending = state.pending || { fields, countries };
				const trap = form.querySelector( '[data-easyformly-trap]' );
				const { response, data } = await request( form.dataset.submitUrl, {
					method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
					body: JSON.stringify( { fields: state.pending.fields, countries: state.pending.countries, nonce: form.elements.namedItem( 'nonce' ).value, ticket: form.elements.namedItem( 'ticket' ).value, clock: form.elements.namedItem( 'clock' ).value, honeypot: { [ trap.dataset.easyformlyTrap ]: trap.value }, captcha: form.elements.namedItem( 'captcha' )?.value || '' } ),
				}, 55000 );
				if ( response.ok && data.success ) {
					state.pending = null;
					state.uncertain = false;
					form.reset();
					result.textContent = data.message;
					state.clockReady = false;
					state.ready = refreshToken( form ).then( () => true, () => false );
				} else {
					if ( [ 'validation', 'captcha', 'invalid', 'spam', 'size' ].includes( data.code ) || ( data.code === 'rate' && ! state.uncertain ) ) {
						state.pending = null;
						state.ready = refreshToken( form ).then( () => true, () => false );
					}
					if ( data.code === 'expired' && ! state.uncertain ) {
						state.pending = null;
						state.ready = refreshToken( form ).then( () => true, () => false );
					}
					if ( data.code === 'busy' ) {
						state.uncertain = true;
					}
					if ( [ 'delivery_uncertain', 'used', 'changed' ].includes( data.code ) || ( data.code === 'expired' && state.uncertain ) ) {
						state.stopped = true;
					}
					updateConditions( form );
					result.textContent = data.code === 'expired' && state.uncertain ? config.messages.uncertain : ( data.message || config.messages.error );
					focus = fieldErrors( form, data.data?.fields );
				}
				resetCaptcha( form, state );
			} catch {
				// Preserve the original ticket and values after an ambiguous network failure.
				state.uncertain = state.pending !== null;
				result.textContent = config.messages.network;
				focus = result;
				resetCaptcha( form, state );
			} finally {
				state.busy = false;
				button.disabled = state.stopped;
				updateConditions( form );
				form.removeAttribute( 'aria-busy' );
				if ( focus ) {
					focus.focus();
				}
			}
		} );
	}

	function boot() {
		document.querySelectorAll( '.easyformly-form[data-easyformly]' ).forEach( initialize );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
