/**
 * Tabla repetible de planes de cuotas en la pantalla de ajustes.
 *
 * @package PowerTranz_WooCommerce
 */
( function ( $ ) {
	'use strict';

	var COLUMNS = [
		{ key: 'cuotas', type: 'number', attrs: 'min="2" max="99" step="1"' },
		{ key: 'label', type: 'text' },
		{ key: 'test_id', type: 'text', attrs: 'autocomplete="off"' },
		{ key: 'test_password', type: 'password', attrs: 'autocomplete="new-password"' },
		{ key: 'live_id', type: 'text', attrs: 'autocomplete="off"' },
		{ key: 'live_password', type: 'password', attrs: 'autocomplete="new-password"' },
		{ key: 'min_amount', type: 'text', attrs: 'placeholder="0"' },
		{ key: 'bins', type: 'text', attrs: 'placeholder="454617, 528745"' },
		{ key: 'extra', type: 'text', attrs: 'placeholder="{}"' }
	];

	$( function () {
		var $table = $( '#wc-powertranz-plans' );

		if ( ! $table.length ) {
			return;
		}

		$table.on( 'click', '.pt-add-plan', function () {
			var $button = $( this );
			var index = parseInt( $button.data( 'index' ), 10 ) || 0;
			var name = $button.data( 'name' );
			var cells = '';

			COLUMNS.forEach( function ( column ) {
				cells += '<td><input type="' + column.type + '" name="' + name + '[' + index + '][' +
					column.key + ']" value="" ' + ( column.attrs || '' ) + ' /></td>';
			} );

			cells += '<td><button type="button" class="button pt-remove-plan">&times;</button></td>';

			$table.find( 'tbody' ).append( '<tr>' + cells + '</tr>' );
			$button.data( 'index', index + 1 );
		} );

		$table.on( 'click', '.pt-remove-plan', function () {
			$( this ).closest( 'tr' ).remove();
		} );
	} );
} )( jQuery );
