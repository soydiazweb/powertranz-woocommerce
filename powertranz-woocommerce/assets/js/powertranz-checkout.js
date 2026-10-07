/**
 * Formateo y validacion del formulario de tarjeta en el checkout clasico.
 *
 * @package PowerTranz_WooCommerce
 */
( function ( $, window, document ) {
	'use strict';

	var params = window.powertranz_cc_params || {};

	var Brands = {
		visa: /^4/,
		mastercard: /^(5[1-5]|222[1-9]|22[3-9]|2[3-6]|27[01]|2720)/,
		amex: /^3[47]/,
		discover: /^6(011|5|4[4-9]|22)/,
		diners: /^3(0[0-5]|[68])/,
		jcb: /^(2131|1800|35)/,
		unionpay: /^62/,
		maestro: /^(5018|5020|5038|6304|6759|676[1-3])/
	};

	/**
	 * Detecta la marca de un PAN.
	 *
	 * @param {string} pan Numero.
	 * @return {string} Slug o cadena vacia.
	 */
	function detectBrand( pan ) {
		var keys = Object.keys( Brands );

		for ( var i = 0; i < keys.length; i++ ) {
			if ( Brands[ keys[ i ] ].test( pan ) ) {
				return keys[ i ];
			}
		}
		return '';
	}

	/**
	 * Validacion Luhn.
	 *
	 * @param {string} pan Numero.
	 * @return {boolean} Valido.
	 */
	function luhn( pan ) {
		if ( pan.length < 12 || pan.length > 19 ) {
			return false;
		}

		var sum = 0;

		for ( var i = 0; i < pan.length; i++ ) {
			var digit = parseInt( pan.charAt( pan.length - 1 - i ), 10 );

			if ( i % 2 ) {
				digit *= 2;
				if ( digit > 9 ) {
					digit -= 9;
				}
			}
			sum += digit;
		}

		return 0 === sum % 10;
	}

	/**
	 * Valida el codigo de seguridad segun las reglas de FAC.
	 *
	 * @param {string} cvv   Codigo.
	 * @param {string} brand Marca.
	 * @return {string} '' si es valido, o 'empty' | 'length' | 'sequence'.
	 */
	function validateCvv( cvv, brand ) {
		cvv = ( cvv || '' ).replace( /\D/g, '' );

		if ( ! cvv.length ) {
			return 'empty';
		}
		if ( cvv.length !== ( 'amex' === brand ? 4 : 3 ) ) {
			return 'length';
		}
		// FAC no admite secuencias de ceros ni de nueves.
		if ( /^0+$/.test( cvv ) || /^9+$/.test( cvv ) ) {
			return 'sequence';
		}
		return '';
	}

	/**
	 * Agrupa el PAN para lectura.
	 *
	 * @param {string} pan   Numero.
	 * @param {string} brand Marca.
	 * @return {string} Numero formateado.
	 */
	function groupPan( pan, brand ) {
		if ( 'amex' === brand ) {
			return pan.replace( /^(\d{0,4})(\d{0,6})(\d{0,5}).*/, function ( match, a, b, c ) {
				return [ a, b, c ].filter( Boolean ).join( ' ' );
			} );
		}
		return pan.replace( /(\d{4})(?=\d)/g, '$1 ' );
	}

	var Form = {

		init: function () {
			this.bind();
		},

		field: function ( name ) {
			return $( '#powertranz-card-' + name );
		},

		bind: function () {
			var self = this;

			$( document.body ).on( 'input', '#powertranz-card-number', function () {
				var raw = this.value.replace( /\D/g, '' ).slice( 0, 19 );
				var brand = detectBrand( raw );

				this.value = groupPan( raw, brand );

				self.field( 'cvc' ).attr( 'maxlength', 'amex' === brand ? 4 : 3 );
				self.showBrand( brand, raw );
				self.filterPlans( raw );
			} );

			$( document.body ).on( 'input', '#powertranz-card-expiry', function () {
				var raw = this.value.replace( /\D/g, '' ).slice( 0, 4 );

				if ( raw.length >= 3 ) {
					this.value = raw.slice( 0, 2 ) + ' / ' + raw.slice( 2 );
				} else if ( 2 === raw.length ) {
					this.value = raw + ' / ';
				} else {
					this.value = raw;
				}
			} );

			$( document.body ).on( 'input', '#powertranz-card-cvc', function () {
				this.value = this.value.replace( /\D/g, '' );
			} );

			$( document.body ).on( 'checkout_place_order_powertranz_cc', function () {
				return self.validate();
			} );
		},

		/**
		 * Marca visualmente la marca detectada.
		 *
		 * @param {string} brand Marca.
		 * @param {string} raw   PAN.
		 */
		showBrand: function ( brand, raw ) {
			var $list = $( '.powertranz-brands' );

			$list.find( '.powertranz-brand' ).removeClass( 'is-active' );

			// Con menos de 6 digitos no se sabe la marca: se muestran todas.
			if ( ! brand || raw.length < 6 ) {
				$list.removeClass( 'has-detected' );
				return;
			}

			var accepted = params.accepted_cards || [];

			if ( accepted.length && -1 === accepted.indexOf( brand ) ) {
				$list.removeClass( 'has-detected' );
				return;
			}

			$list.addClass( 'has-detected' );
			$list.find( '.powertranz-brand--' + brand ).addClass( 'is-active' );
		},

		/**
		 * Deja solo los planes de cuotas compatibles con el BIN escrito.
		 *
		 * @param {string} raw PAN.
		 */
		filterPlans: function ( raw ) {
			if ( ! params.installments_on ) {
				return;
			}

			var $select = $( '#powertranz-installments' );

			if ( ! $select.length ) {
				return;
			}

			var plans = params.installments || [];
			var current = $select.val();
			var hasCurrent = false;

			plans.forEach( function ( plan ) {
				var $option = $select.find( 'option[value="' + plan.id + '"]' );

				if ( ! $option.length ) {
					return;
				}

				var allowed = true;

				if ( plan.bins && plan.bins.length && raw.length >= 6 ) {
					allowed = plan.bins.some( function ( bin ) {
						return 0 === raw.indexOf( bin );
					} );
				}

				$option.prop( 'disabled', ! allowed ).toggle( allowed );

				if ( allowed && plan.id === current ) {
					hasCurrent = true;
				}
			} );

			if ( ! hasCurrent && current ) {
				$select.val( 'single' );
			}
		},

		/**
		 * Validacion previa al envio del formulario.
		 *
		 * @return {boolean} Si se puede enviar.
		 */
		validate: function () {
			var i18n = params.i18n || {};
			var errors = [];

			var name = $.trim( this.field( 'name' ).val() || '' );
			var pan = ( this.field( 'number' ).val() || '' ).replace( /\D/g, '' );
			var expiry = ( this.field( 'expiry' ).val() || '' ).replace( /\D/g, '' );
			var cvc = ( this.field( 'cvc' ).val() || '' ).replace( /\D/g, '' );
			var brand = detectBrand( pan );

			if ( name.length < 2 ) {
				errors.push( i18n.invalid_name );
			}
			// En pruebas no se aplica Luhn: varias tarjetas del sandbox de
			// First Atlantic Commerce no cumplen el digito de control.
			var panValid = params.is_test
				? ( pan.length >= 12 && pan.length <= 19 )
				: luhn( pan );

			if ( ! panValid || ! brand ) {
				errors.push( i18n.invalid_number );
			}

			var accepted = params.accepted_cards || [];
			if ( brand && accepted.length && -1 === accepted.indexOf( brand ) ) {
				errors.push( i18n.brand_rejected );
			}

			if ( 4 !== expiry.length ) {
				errors.push( i18n.invalid_expiry );
			} else {
				var month = parseInt( expiry.slice( 0, 2 ), 10 );
				var year = 2000 + parseInt( expiry.slice( 2 ), 10 );
				var now = new Date();

				if ( month < 1 || month > 12 || year < now.getFullYear() ||
					( year === now.getFullYear() && month < now.getMonth() + 1 ) ) {
					errors.push( i18n.invalid_expiry );
				}
			}

			var cvvError = validateCvv( cvc, brand );

			if ( 'empty' === cvvError ) {
				errors.push( i18n.required_cvc || i18n.invalid_cvc );
			} else if ( 'sequence' === cvvError ) {
				errors.push( i18n.sequence_cvc || i18n.invalid_cvc );
			} else if ( cvvError ) {
				errors.push( i18n.invalid_cvc );
			}

			if ( ! errors.length ) {
				return true;
			}

			this.showErrors( errors );
			return false;
		},

		/**
		 * Pinta los errores con el mismo formato que WooCommerce.
		 *
		 * @param {Array} errors Mensajes.
		 */
		showErrors: function ( errors ) {
			var $form = $( 'form.checkout' );

			$( '.woocommerce-NoticeGroup-checkout, .powertranz-notices' ).remove();

			var html = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout powertranz-notices">' +
				'<ul class="woocommerce-error" role="alert">';

			errors.forEach( function ( error ) {
				html += '<li>' + error + '</li>';
			} );

			html += '</ul></div>';

			$form.prepend( html );
			$( 'html, body' ).animate( {
				scrollTop: $form.offset().top - 100
			}, 400 );

			$form.removeClass( 'processing' ).unblock();
		}
	};

	$( function () {
		Form.init();
	} );
} )( jQuery, window, document );
