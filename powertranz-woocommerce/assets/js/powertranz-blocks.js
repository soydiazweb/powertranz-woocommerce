/**
 * Registro de las pasarelas PowerTranz en el checkout por bloques.
 *
 * @package PowerTranz_WooCommerce
 */
( function ( window, document ) {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settings = window.wc && window.wc.wcSettings;
	var element = window.wp && window.wp.element;

	if ( ! registry || ! settings || ! element ) {
		return;
	}

	var el = element.createElement;
	var useEffect = element.useEffect;
	var useRef = element.useRef;
	var useState = element.useState;
	var Fragment = element.Fragment;
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function ( value ) {
		return value;
	};

	/* ------------------------------------------------------------------
	 * Tarjetas
	 * --------------------------------------------------------------- */

	var ccData = settings.getSetting( 'powertranz_cc_data', {} );

	var BRANDS = {
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
	 * Detecta la marca.
	 *
	 * @param {string} pan PAN.
	 * @return {string} Marca.
	 */
	function detectBrand( pan ) {
		var keys = Object.keys( BRANDS );

		for ( var i = 0; i < keys.length; i++ ) {
			if ( BRANDS[ keys[ i ] ].test( pan ) ) {
				return keys[ i ];
			}
		}
		return '';
	}

	/**
	 * Luhn.
	 *
	 * @param {string} pan PAN.
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
		if ( /^0+$/.test( cvv ) || /^9+$/.test( cvv ) ) {
			return 'sequence';
		}
		return '';
	}

	/**
	 * Fila de logos de las marcas aceptadas.
	 *
	 * @param {Array}  brands   Slugs de marca.
	 * @param {string} baseUrl  URL base de los logos.
	 * @param {Object} labels   Etiquetas por marca.
	 * @return {Object|null} Elemento.
	 */
	function brandRow( brands, baseUrl, labels ) {
		if ( ! brands || ! brands.length || ! baseUrl ) {
			return null;
		}

		return el( 'ul', { className: 'powertranz-brands', key: 'brands' }, brands.map( function ( brand ) {
			return el( 'li', {
				key: brand,
				className: 'powertranz-brand powertranz-brand--' + brand,
				'data-brand': brand
			}, el( 'img', {
				src: baseUrl + brand + '.svg',
				alt: ( labels && labels[ brand ] ) || brand,
				width: 52,
				height: 32,
				loading: 'lazy'
			} ) );
		} ) );
	}

	/**
	 * Fila de sellos del adquirente y de la pasarela.
	 *
	 * @param {Array} seals Sellos {url, alt}.
	 * @return {Object|null} Elemento.
	 */
	function sealRow( seals ) {
		if ( ! seals || ! seals.length ) {
			return null;
		}

		return el( 'ul', { className: 'powertranz-seals', key: 'seals' }, seals.map( function ( seal, index ) {
			return el( 'li', { key: index }, el( 'img', {
				src: seal.url,
				alt: seal.alt,
				height: 32,
				loading: 'lazy'
			} ) );
		} ) );
	}

	/**
	 * Formulario de tarjeta.
	 *
	 * @param {Object} props Props del bloque.
	 * @return {Object} Elemento.
	 */
	function CardForm( props ) {
		var onPaymentSetup = props.eventRegistration.onPaymentSetup;
		var responseTypes = props.emitResponse.responseTypes;
		var noticeContexts = props.emitResponse.noticeContexts;

		var i18n = ccData.i18n || {};
		var accepted = ccData.accepted_cards || [];
		var options = ccData.installment_options || {};
		var optionKeys = Object.keys( options );

		var nameRef = useRef( null );
		var numberRef = useRef( null );
		var expiryRef = useRef( null );
		var cvcRef = useRef( null );
		var planRef = useRef( null );

		var state = useState( '' );
		var brand = state[ 0 ];
		var setBrand = state[ 1 ];

		useEffect( function () {
			var unsubscribe = onPaymentSetup( function () {
				var name = ( nameRef.current ? nameRef.current.value : '' ).trim();
				var pan = ( numberRef.current ? numberRef.current.value : '' ).replace( /\D/g, '' );
				var expiry = ( expiryRef.current ? expiryRef.current.value : '' ).replace( /\D/g, '' );
				var cvc = ( cvcRef.current ? cvcRef.current.value : '' ).replace( /\D/g, '' );
				var plan = planRef.current ? planRef.current.value : 'single';
				var detected = detectBrand( pan );

				var error = function ( message ) {
					return {
						type: responseTypes.ERROR,
						message: message,
						messageContext: noticeContexts.PAYMENTS
					};
				};

				if ( name.length < 2 ) {
					return error( i18n.invalid_name );
				}
				// En pruebas no se aplica Luhn: varias tarjetas del sandbox
				// de First Atlantic Commerce no cumplen el digito de control.
				var panValid = ccData.is_test
					? ( pan.length >= 12 && pan.length <= 19 )
					: luhn( pan );

				if ( ! panValid || ! detected ) {
					return error( i18n.invalid_number );
				}
				if ( accepted.length && -1 === accepted.indexOf( detected ) ) {
					return error( i18n.brand_rejected );
				}
				if ( 4 !== expiry.length ) {
					return error( i18n.invalid_expiry );
				}

				var month = parseInt( expiry.slice( 0, 2 ), 10 );
				var year = 2000 + parseInt( expiry.slice( 2 ), 10 );
				var now = new Date();

				if ( month < 1 || month > 12 || year < now.getFullYear() ||
					( year === now.getFullYear() && month < now.getMonth() + 1 ) ) {
					return error( i18n.invalid_expiry );
				}

				var cvvError = validateCvv( cvc, detected );

				if ( 'empty' === cvvError ) {
					return error( i18n.required_cvc || i18n.invalid_cvc );
				}
				if ( 'sequence' === cvvError ) {
					return error( i18n.sequence_cvc || i18n.invalid_cvc );
				}
				if ( cvvError ) {
					return error( i18n.invalid_cvc );
				}

				return {
					type: responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							'powertranz-card-name': name,
							'powertranz-card-number': pan,
							'powertranz-card-expiry': expiry,
							'powertranz-card-cvc': cvc,
							'powertranz-installments': plan
						}
					}
				};
			} );

			return unsubscribe;
		}, [ onPaymentSetup, responseTypes, noticeContexts, i18n, accepted ] );

		var onNumberInput = function ( event ) {
			var raw = event.target.value.replace( /\D/g, '' ).slice( 0, 19 );
			var detected = detectBrand( raw );

			event.target.value = 'amex' === detected
				? raw.replace( /^(\d{0,4})(\d{0,6})(\d{0,5}).*/, function ( match, a, b, c ) {
					return [ a, b, c ].filter( Boolean ).join( ' ' );
				} )
				: raw.replace( /(\d{4})(?=\d)/g, '$1 ' );

			setBrand( detected );
		};

		var onExpiryInput = function ( event ) {
			var raw = event.target.value.replace( /\D/g, '' ).slice( 0, 4 );

			if ( raw.length >= 3 ) {
				event.target.value = raw.slice( 0, 2 ) + ' / ' + raw.slice( 2 );
			} else if ( 2 === raw.length ) {
				event.target.value = raw + ' / ';
			} else {
				event.target.value = raw;
			}
		};

		var onCvcInput = function ( event ) {
			event.target.value = event.target.value.replace( /\D/g, '' );
		};

		var children = [];

		if ( ccData.description ) {
			children.push( el( 'p', { key: 'desc' }, decode( ccData.description ) ) );
		}
		if ( ccData.is_test ) {
			children.push( el( 'p', { key: 'test' }, el( 'strong', null, 'MODO DE PRUEBAS: no se realizaran cargos reales.' ) ) );
		}

		if ( optionKeys.length > 1 ) {
			children.push(
				el( 'div', { key: 'plan', className: 'powertranz-blocks-row' }, [
					el( 'label', { key: 'l', htmlFor: 'powertranz-blocks-installments' }, ccData.installments_label || 'Cuotas' ),
					el( 'select', {
						key: 's',
						id: 'powertranz-blocks-installments',
						ref: planRef,
						className: 'powertranz-installments'
					}, optionKeys.map( function ( value ) {
						return el( 'option', { key: value, value: value }, options[ value ] );
					} ) )
				] )
			);
		}

		children.push(
			el( 'div', { key: 'name', className: 'powertranz-blocks-row' }, [
				el( 'label', { key: 'l', htmlFor: 'powertranz-blocks-name' }, 'Nombre en la tarjeta' ),
				el( 'input', {
					key: 'i',
					id: 'powertranz-blocks-name',
					ref: nameRef,
					type: 'text',
					autoComplete: 'cc-name',
					maxLength: 45,
					spellCheck: false,
					required: true,
					'aria-required': 'true'
				} )
			] ),
			el( 'div', { key: 'number', className: 'powertranz-blocks-row' }, [
				el( 'label', { key: 'l', htmlFor: 'powertranz-blocks-number' }, 'Numero de tarjeta' ),
				el( 'input', {
					key: 'i',
					id: 'powertranz-blocks-number',
					ref: numberRef,
					type: 'text',
					inputMode: 'numeric',
					autoComplete: 'cc-number',
					maxLength: 23,
					spellCheck: false,
					required: true,
					'aria-required': 'true',
					'data-brand': brand,
					onInput: onNumberInput
				} )
			] ),
			el( 'div', { key: 'dates', className: 'powertranz-blocks-grid' }, [
				el( 'div', { key: 'exp', className: 'powertranz-blocks-row' }, [
					el( 'label', { key: 'l', htmlFor: 'powertranz-blocks-expiry' }, 'Caducidad (MM/AA)' ),
					el( 'input', {
						key: 'i',
						id: 'powertranz-blocks-expiry',
						ref: expiryRef,
						type: 'text',
						inputMode: 'numeric',
						autoComplete: 'cc-exp',
						maxLength: 7,
						required: true,
						'aria-required': 'true',
						placeholder: 'MM / AA',
						onInput: onExpiryInput
					} )
				] ),
				el( 'div', { key: 'cvc', className: 'powertranz-blocks-row' }, [
					el( 'label', { key: 'l', htmlFor: 'powertranz-blocks-cvc' }, 'Codigo de seguridad' ),
					el( 'input', {
						key: 'i',
						id: 'powertranz-blocks-cvc',
						ref: cvcRef,
						// Enmascarado: FAC exige no mostrar el CVV en claro.
						type: 'password',
						inputMode: 'numeric',
						autoComplete: 'cc-csc',
						maxLength: 'amex' === brand ? 4 : 3,
						required: true,
						'aria-required': 'true',
						placeholder: '\u2022\u2022\u2022',
						onInput: onCvcInput
					} )
				] )
			] )
		);

		// Los logos van debajo de los campos, segun las marcas activas.
		if ( ccData.strip_html ) {
			children.push( el( 'div', {
				key: 'strip',
				dangerouslySetInnerHTML: { __html: ccData.strip_html }
			} ) );
		} else {
			var brands = brandRow( accepted, ccData.logo_base_url, ccData.brand_labels );
			if ( brands ) {
				children.push( brands );
			}
		}

		var seals = sealRow( ccData.seals );
		if ( seals ) {
			children.push( seals );
		}
		if ( ccData.support_html ) {
			children.push( el( 'div', {
				key: 'support',
				className: 'powertranz-support-wrap',
				dangerouslySetInnerHTML: { __html: ccData.support_html }
			} ) );
		}

		return el( 'div', { className: 'powertranz-blocks-form' }, children );
	}

	/**
	 * Etiqueta del metodo.
	 *
	 * @param {Object} data Datos del metodo.
	 * @return {Function} Componente.
	 */
	function makeLabel( data ) {
		return function () {
			return el( 'span', { className: 'powertranz-blocks-label' }, decode( data.title || '' ) );
		};
	}

	if ( ccData.gateway_id ) {
		registry.registerPaymentMethod( {
			name: 'powertranz_cc',
			label: el( makeLabel( ccData ), null ),
			ariaLabel: decode( ccData.title || 'PowerTranz' ),
			content: el( CardForm, null ),
			edit: el( 'div', null, decode( ccData.title || 'PowerTranz' ) ),
			canMakePayment: function () {
				return true;
			},
			supports: {
				features: ccData.supports || [ 'products' ]
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * Apple Pay
	 * --------------------------------------------------------------- */

	var apData = settings.getSetting( 'powertranz_applepay_data', {} );

	if ( apData.gateway_id ) {
		// El nucleo compartido lee sus parametros de este global.
		window.powertranz_applepay_params = apData;
	}

	var authorizedPayment = null;

	/**
	 * Localiza el boton "Realizar el pedido" del bloque.
	 *
	 * @return {Element|null} Boton.
	 */
	function findPlaceOrderButton() {
		return document.querySelector( '.wc-block-components-checkout-place-order-button' ) ||
			document.querySelector( '.wc-block-checkout__actions button[type="submit"]' );
	}

	/**
	 * Contenido del metodo Apple Pay.
	 *
	 * @param {Object} props Props.
	 * @return {Object} Elemento.
	 */
	function ApplePayContent( props ) {
		var onPaymentSetup = props.eventRegistration.onPaymentSetup;
		var responseTypes = props.emitResponse.responseTypes;
		var noticeContexts = props.emitResponse.noticeContexts;

		var i18n = apData.i18n || {};
		var buttonRef = useRef( null );

		var messageState = useState( '' );
		var message = messageState[ 0 ];
		var setMessage = messageState[ 1 ];

		useEffect( function () {
			if ( buttonRef.current && window.PowerTranzApplePay ) {
				window.PowerTranzApplePay.styleButton( buttonRef.current );
			}
		}, [] );

		useEffect( function () {
			var unsubscribe = onPaymentSetup( function () {
				if ( ! authorizedPayment ) {
					return {
						type: responseTypes.ERROR,
						message: i18n.authorize_first || 'Pulse el boton de Apple Pay para autorizar el pago.',
						messageContext: noticeContexts.PAYMENTS
					};
				}

				var payload = JSON.stringify( authorizedPayment );
				authorizedPayment = null;

				return {
					type: responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							powertranz_applepay_token: payload
						}
					}
				};
			} );

			return unsubscribe;
		}, [ onPaymentSetup, responseTypes, noticeContexts, i18n ] );

		var authorize = function () {
			if ( ! window.PowerTranzApplePay ) {
				return;
			}

			setMessage( i18n.authorizing || '' );

			window.PowerTranzApplePay.start( {
				onAuthorized: function ( payment ) {
					authorizedPayment = payment;
					setMessage( '' );

					var button = findPlaceOrderButton();
					if ( button ) {
						button.click();
					}
				},
				onCancel: function () {
					authorizedPayment = null;
					setMessage( i18n.cancelled || '' );
				},
				onError: function ( text ) {
					authorizedPayment = null;
					setMessage( text || i18n.error || '' );
				}
			} );
		};

		var children = [];

		if ( apData.description ) {
			children.push( el( 'p', { key: 'desc' }, decode( apData.description ) ) );
		}

		children.push(
			el( 'div', {
				key: 'button',
				ref: buttonRef,
				className: 'powertranz-applepay-button',
				role: 'button',
				tabIndex: 0,
				'data-style': apData.button_style || 'black',
				'data-type': apData.button_type || 'buy',
				onClick: authorize,
				onKeyDown: function ( event ) {
					if ( 'Enter' === event.key || ' ' === event.key ) {
						event.preventDefault();
						authorize();
					}
				}
			} )
		);

		if ( message ) {
			children.push( el( 'p', { key: 'msg', className: 'powertranz-applepay-message' }, message ) );
		}

		if ( apData.strip_html ) {
			children.push( el( 'div', {
				key: 'strip',
				dangerouslySetInnerHTML: { __html: apData.strip_html }
			} ) );
		}

		var apSeals = sealRow( apData.seals );
		if ( apSeals ) {
			children.push( apSeals );
		}
		if ( apData.support_html ) {
			children.push( el( 'div', {
				key: 'support',
				className: 'powertranz-support-wrap',
				dangerouslySetInnerHTML: { __html: apData.support_html }
			} ) );
		}

		return el( 'div', { className: 'powertranz-applepay-wrap' }, children );
	}

	if ( apData.gateway_id ) {
		registry.registerPaymentMethod( {
			name: 'powertranz_applepay',
			label: el( makeLabel( apData ), null ),
			ariaLabel: decode( apData.title || 'Apple Pay' ),
			content: el( ApplePayContent, null ),
			edit: el( 'div', null, decode( apData.title || 'Apple Pay' ) ),
			canMakePayment: function () {
				return !! ( window.PowerTranzApplePay && window.PowerTranzApplePay.isAvailable() );
			},
			supports: {
				features: apData.supports || [ 'products' ]
			}
		} );
	}
} )( window, document );
