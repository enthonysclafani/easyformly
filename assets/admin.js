( () => {
	'use strict';
	const editor = document.getElementById( 'easyformly-template' );
	document.querySelectorAll( '[data-easyformly-insert]' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			if ( ! editor ) {
				return;
			}
			const type = button.dataset.easyformlyInsert;
			let suffix = 1;
			while ( editor.value.includes( `${ type }_${ suffix } ` ) ) {
				suffix++;
			}
			const required = [ 'checkbox', 'number' ].includes( type ) ? '' : '*';
			let options = '';
			if ( type === 'select' ) {
				options = ` "${ window.easyformlyAdmin.optionOne }" "${ window.easyformlyAdmin.optionTwo }"`;
			}
			if ( type === 'number' ) {
				options = ' min:0 step:1';
			}
			const tag = `[${ type }${ required } ${ type }_${ suffix } "${ button.textContent.trim() }"${ options }]`;
			const end = editor.value.indexOf( '\n', editor.selectionEnd );
			const position = end < 0 ? editor.value.length : end + 1;
			editor.setRangeText( ( position && editor.value[ position - 1 ] !== '\n' ? '\n' : '' ) + tag + '\n', position, position, 'end' );
			editor.focus();
			editor.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
	} );
	const rules = document.getElementById( 'easyformly-rules' );
	let index = Date.now();
	document.getElementById( 'easyformly-add-rule' )?.addEventListener( 'click', () => {
		const template = document.getElementById( 'easyformly-rule-template' );
		if ( ! template || ! rules || rules.children.length >= 80 ) {
			return;
		}
		const row = template.content.firstElementChild.cloneNode( true );
		row.querySelectorAll( '[name]' ).forEach( ( control ) => {
			control.name = control.name.replaceAll( '__INDEX__', String( index ) );
		} );
		index++;
		rules.appendChild( row );
		row.querySelector( 'select' ).focus();
	} );
	rules?.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-easyformly-remove]' );
		if ( button ) {
			button.closest( 'tr' ).remove();
		}
	} );
	document.querySelectorAll( 'input[readonly][aria-label="Shortcode"]' ).forEach( ( input ) => input.addEventListener( 'focus', () => input.select() ) );
} )();
