/**
 * Nucleo de Apple Pay: crea la sesion, valida el comercio y entrega el objeto
 * de pago autorizado. Lo comparten el checkout clasico y el de bloques.
 *
 * @package PowerTranz_WooCommerce
 */
( function ( window, document ) {
	'use strict';

	var PowerTranzApplePay = {

		/**
		 * Parametros publicados por PHP.
		 *
		 * @return {Object} Parametros.
		 */
		params: function () {
			return window.powertranz_applepay_params || {};
		},

		/**
		 * Indica si el dispositivo puede pagar con Apple Pay.
		 *
		 * @return {boolean} Disponible.
		 */
		isAvailable: function () {
			if ( ! window.ApplePaySession ) {
				return false;
			}
			if ( ! window.ApplePaySession.supportsVersion || ! window.ApplePaySession.supportsVersion( 3 ) ) {
				return false;
			}
			try {
				return window.ApplePaySession.canMakePayments();
			} catch ( e ) {
				return false;
			}
		},

		/**
		 * Peticion a admin-ajax.
		 *
		 * @param {string} action Accion.
		 * @param {Object} data   Datos.
		 * @return {Promise} Respuesta JSON.
		 */
		post: function ( action, data ) {
			var params = this.params();
			var body = new window.URLSearchParams();

			body.append( 'action', action );
			body.append( 'nonce', params.nonce || '' );

			Object.keys( data || {} ).forEach( function ( key ) {
				body.append( key, data[ key ] );
			} );

			return window.fetch( params.ajax_url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} ).then( function ( response ) {
				return response.json();
			} );
		},

		/**
		 * Aplica el aspecto oficial del boton de Apple Pay.
		 *
		 * @param {Element} element Contenedor.
		 */
		styleButton: function ( element ) {
			if ( ! element ) {
				return;
			}

			var style = element.getAttribute( 'data-style' ) || 'black';
			var type = element.getAttribute( 'data-type' ) || 'buy';
			var locale = document.documentElement.lang || 'en-US';

			// El SDK de Apple registra el elemento <apple-pay-button>, que es la
			// unica forma admitida de presentar el boton oficial.
			var hasElement = window.customElements &&
				'function' === typeof window.customElements.get &&
				!! window.customElements.get( 'apple-pay-button' );

			if ( hasElement ) {
				var button = document.createElement( 'apple-pay-button' );

				button.setAttribute( 'buttonstyle', style );
				button.setAttribute( 'type', type );
				button.setAttribute( 'locale', locale );

				element.textContent = '';
				element.classList.remove( 'powertranz-applepay-button--css' );
				element.appendChild( button );
				return;
			}

			// Respaldo si el SDK no pudo cargarse.
			element.classList.add( 'powertranz-applepay-button--css' );
			element.setAttribute( 'data-applied-style', style );
		},

		/**
		 * Abre la hoja de pago de Apple Pay.
		 *
		 * @param {Object} callbacks onAuthorized, onError, onCancel.
		 */
		start: function ( callbacks ) {
			var self = this;
			var params = this.params();
			var i18n = params.i18n || {};

			callbacks = callbacks || {};

			var fail = function ( message ) {
				if ( callbacks.onError ) {
					callbacks.onError( message || i18n.error );
				}
			};

			if ( ! this.isAvailable() ) {
				fail( i18n.unavailable );
				return;
			}

			var request = {};
			if ( params.order_id ) {
				request.order_id = params.order_id;
				request.order_key = params.order_key || '';
			}

			this.post( 'powertranz_applepay_data', request ).then( function ( response ) {
				if ( ! response || ! response.success || ! response.data ) {
					fail( ( response && response.data && response.data.message ) || i18n.error );
					return;
				}

				var totals = response.data;

				var paymentRequest = {
					countryCode: totals.countryCode,
					currencyCode: totals.currencyCode,
					supportedNetworks: params.supported_networks || [ 'visa', 'masterCard', 'amex' ],
					merchantCapabilities: params.merchant_capabilities || [ 'supports3DS' ],
					total: {
						label: params.display_name || document.title,
						amount: totals.total,
						type: 'final'
					},
					lineItems: totals.lineItems || []
				};

				if ( params.request_billing ) {
					paymentRequest.requiredBillingContactFields = [ 'postalAddress', 'name' ];
				}

				var session;

				try {
					session = new window.ApplePaySession( 3, paymentRequest );
				} catch ( e ) {
					fail( i18n.error );
					return;
				}

				session.onvalidatemerchant = function ( event ) {
					self.post( 'powertranz_applepay_validate', { validationURL: event.validationURL } )
						.then( function ( validation ) {
							if ( validation && validation.success && validation.data ) {
								try {
									session.completeMerchantValidation( validation.data );
								} catch ( e ) {
									fail( i18n.error );
								}
								return;
							}

							fail( ( validation && validation.data && validation.data.message ) || i18n.error );
							try {
								session.abort();
							} catch ( e ) {}
						} )
						.catch( function () {
							fail( i18n.error );
							try {
								session.abort();
							} catch ( e ) {}
						} );
				};

				session.onpaymentauthorized = function ( event ) {
					// El cargo se realiza cuando WooCommerce procesa el pedido:
					// aqui solo se confirma que se recibio la autorizacion del
					// dispositivo, dentro del limite de 30 segundos de Apple.
					try {
						session.completePayment( window.ApplePaySession.STATUS_SUCCESS );
					} catch ( e ) {}

					if ( callbacks.onAuthorized ) {
						callbacks.onAuthorized( event.payment );
					}
				};

				session.oncancel = function () {
					if ( callbacks.onCancel ) {
						callbacks.onCancel();
					}
				};

				try {
					session.begin();
				} catch ( e ) {
					fail( i18n.error );
				}
			} ).catch( function () {
				fail( i18n.error );
			} );
		}
	};

	window.PowerTranzApplePay = PowerTranzApplePay;
} )( window, document );
