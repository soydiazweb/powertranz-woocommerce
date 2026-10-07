/**
 * Apple Pay en el checkout clasico de WooCommerce.
 *
 * @package PowerTranz_WooCommerce
 */
( function ( $, window, document ) {
	'use strict';

	var GATEWAY = 'powertranz_applepay';

	var Classic = {

		init: function () {
			this.bind();
			this.refresh();

			$( document.body ).on( 'updated_checkout payment_method_selected', this.refresh.bind( this ) );
		},

		params: function () {
			return window.powertranz_applepay_params || {};
		},

		i18n: function ( key ) {
			var i18n = this.params().i18n || {};
			return i18n[ key ] || '';
		},

		wrapper: function () {
			return $( '[data-powertranz-applepay]' );
		},

		/**
		 * Oculta el metodo si el dispositivo no soporta Apple Pay.
		 */
		refresh: function () {
			var $input = $( '#payment_method_' + GATEWAY );

			if ( ! $input.length ) {
				return;
			}

			var available = window.PowerTranzApplePay && window.PowerTranzApplePay.isAvailable();

			if ( ! available ) {
				var $li = $input.closest( 'li' );
				$li.hide();

				if ( $input.is( ':checked' ) ) {
					$input.prop( 'checked', false );
					$( 'input[name="payment_method"]:visible' ).first().prop( 'checked', true ).trigger( 'change' );
				}
				return;
			}

			$input.closest( 'li' ).show();

			var button = document.getElementById( 'powertranz-applepay-button' );
			if ( button && ! button.getAttribute( 'data-ready' ) ) {
				window.PowerTranzApplePay.styleButton( button );
				button.setAttribute( 'data-ready', '1' );
			}
		},

		message: function ( text, isError ) {
			var $message = this.wrapper().find( '.powertranz-applepay-message' );
			$message.text( text || '' ).toggleClass( 'powertranz-error', !! isError );
		},

		bind: function () {
			var self = this;

			$( document.body ).on( 'click keydown', '#powertranz-applepay-button', function ( event ) {
				if ( 'keydown' === event.type && ' ' !== event.key && 'Enter' !== event.key ) {
					return;
				}
				event.preventDefault();
				self.authorize();
			} );

			// Si el cliente pulsa "Realizar el pedido" sin autorizar todavia,
			// se abre la hoja de Apple Pay en lugar de enviar el formulario.
			$( document.body ).on( 'click', 'form.checkout #place_order', function ( event ) {
				if ( GATEWAY !== $( 'input[name="payment_method"]:checked' ).val() ) {
					return;
				}
				if ( $( '#powertranz_applepay_token' ).val() ) {
					return;
				}

				event.preventDefault();
				self.authorize();
			} );
		},

		authorize: function () {
			var self = this;

			if ( ! window.PowerTranzApplePay ) {
				return;
			}

			this.message( this.i18n( 'authorizing' ) );

			window.PowerTranzApplePay.start( {
				onAuthorized: function ( payment ) {
					$( '#powertranz_applepay_token' ).val( JSON.stringify( payment ) );
					self.message( '' );
					$( 'form.checkout' ).trigger( 'submit' );
				},
				onCancel: function () {
					$( '#powertranz_applepay_token' ).val( '' );
					self.message( self.i18n( 'cancelled' ) );
				},
				onError: function ( message ) {
					$( '#powertranz_applepay_token' ).val( '' );
					self.message( message, true );
				}
			} );
		}
	};

	$( function () {
		Classic.init();
	} );
} )( jQuery, window, document );
