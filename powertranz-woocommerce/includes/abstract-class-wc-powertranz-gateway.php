<?php
/**
 * Base compartida por las pasarelas PowerTranz.
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PowerTranz_Gateway
 */
abstract class WC_PowerTranz_Gateway extends WC_Payment_Gateway {

	/**
	 * Gestor de cuotas.
	 *
	 * @var WC_PowerTranz_Installments
	 */
	public $installments;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->installments = new WC_PowerTranz_Installments( $this );
	}

	/* ---------------------------------------------------------------------
	 * Configuracion
	 * ------------------------------------------------------------------ */

	/**
	 * Entorno activo.
	 *
	 * @return string test|live
	 */
	public function get_environment() {
		return 'live' === $this->get_option( 'environment', 'test' ) ? 'live' : 'test';
	}

	/**
	 * Indica si el entorno es de pruebas.
	 *
	 * @return bool
	 */
	public function is_test() {
		return 'test' === $this->get_environment();
	}

	/**
	 * Modo de cobro: sale (autoriza y captura) o auth (solo autoriza).
	 *
	 * @return string
	 */
	public function get_capture_mode() {
		return 'auth' === $this->get_option( 'capture_mode', 'sale' ) ? 'auth' : 'sale';
	}

	/**
	 * Credenciales para el entorno activo, respetando el plan de cuotas.
	 *
	 * @param array|null $plan Plan de cuotas.
	 * @return array {id, password}
	 */
	public function get_credentials( $plan = null ) {
		$env = $this->get_environment();

		$id       = (string) $this->get_option( $env . '_powertranz_id', '' );
		$password = (string) $this->get_option( $env . '_powertranz_password', '' );

		if ( is_array( $plan ) && ! empty( $plan['id'] ) && 'single' !== $plan['id'] ) {
			$plan_id       = (string) ( $plan[ $env . '_id' ] ?? '' );
			$plan_password = (string) ( $plan[ $env . '_password' ] ?? '' );

			if ( '' !== $plan_id ) {
				$id = $plan_id;
			}
			if ( '' !== $plan_password ) {
				$password = $plan_password;
			}
		}

		/**
		 * Permite sobrescribir las credenciales usadas en una transaccion.
		 *
		 * @param array                 $credentials array( 'id', 'password' ).
		 * @param string                $environment test|live.
		 * @param array|null            $plan        Plan de cuotas.
		 * @param WC_PowerTranz_Gateway $gateway     Pasarela.
		 */
		return apply_filters(
			'powertranz_credentials',
			array(
				'id'       => trim( $id ),
				'password' => trim( $password ),
			),
			$env,
			$plan,
			$this
		);
	}

	/**
	 * Cliente API para el plan indicado.
	 *
	 * @param array|null $plan Plan de cuotas.
	 * @return WC_PowerTranz_API
	 */
	public function get_api( $plan = null ) {
		$credentials = $this->get_credentials( $plan );

		return new WC_PowerTranz_API(
			$credentials['id'],
			$credentials['password'],
			$this->get_environment(),
			(int) $this->get_option( 'request_timeout', 60 ),
			(string) $this->get_option( 'gateway_key', '' )
		);
	}

	/**
	 * Cliente API reconstruido a partir de los datos guardados en un pedido.
	 *
	 * Imprescindible para capturar, anular o reembolsar pedidos pagados en
	 * cuotas, que se transaccionaron con credenciales distintas.
	 *
	 * @param WC_Order $order Pedido.
	 * @return WC_PowerTranz_API
	 */
	public function get_api_for_order( $order ) {
		$plan_id = (string) $order->get_meta( '_powertranz_installment_plan_id' );
		$plan    = $plan_id ? $this->installments->get_plan( $plan_id ) : null;

		return $this->get_api( $plan );
	}

	/* ---------------------------------------------------------------------
	 * Construccion de solicitudes
	 * ------------------------------------------------------------------ */

	/**
	 * Campos comunes de una solicitud financiera.
	 *
	 * @param WC_Order   $order          Pedido.
	 * @param string     $transaction_id GUID de la transaccion.
	 * @param float|null $amount         Importe (por defecto el total del pedido).
	 * @return array
	 */
	protected function build_base_payload( $order, $transaction_id, $amount = null ) {
		$currency = $order->get_currency();
		$amount   = null === $amount ? $order->get_total() : $amount;

		$payload = array(
			'TransactionIdentifier' => $transaction_id,
			'TotalAmount'           => WC_PowerTranz_Helper::format_amount( $amount, $currency ),
			'CurrencyCode'          => WC_PowerTranz_Helper::get_numeric_currency( $currency ),
			'OrderIdentifier'       => $this->build_order_identifier( $order ),
			'ExternalIdentifier'    => WC_PowerTranz_Helper::limit( 'wc-' . $order->get_id(), 50 ),
			'AddressMatch'          => false,
			'FraudCheck'            => 'yes' === $this->get_option( 'fraud_check', 'no' ),
		);

		$tax = (float) $order->get_total_tax();
		if ( $tax > 0 ) {
			$payload['TaxAmount'] = WC_PowerTranz_Helper::format_amount( $tax, $currency );
		}

		$billing = $this->build_billing_address( $order );
		if ( ! empty( $billing ) ) {
			$payload['BillingAddress'] = $billing;
		}

		$shipping = $this->build_shipping_address( $order );
		if ( ! empty( $shipping ) ) {
			$payload['ShippingAddress'] = $shipping;
			$payload['AddressMatch']    = $this->addresses_match( $order );
		}

		return $payload;
	}

	/**
	 * Identificador de pedido enviado a PowerTranz.
	 *
	 * Se incluye un sufijo aleatorio para que los reintentos tras un rechazo no
	 * sean tratados como transacciones duplicadas por el host.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	protected function build_order_identifier( $order ) {
		$prefix = $this->get_option( 'order_prefix', '' );
		$prefix = WC_PowerTranz_Helper::sanitize_3ds_text( $prefix, 10 );

		$attempt = (int) $order->get_meta( '_powertranz_attempts' );

		$identifier = $prefix . $order->get_order_number();
		if ( $attempt > 1 ) {
			$identifier .= '-' . $attempt;
		}

		return WC_PowerTranz_Helper::limit( $identifier, 50 );
	}

		/**
	 * Primer valor no vacio de una lista de candidatos.
	 *
	 * @param array $candidates Valores en orden de preferencia.
	 * @return string
	 */
	protected function first_filled( $candidates ) {
		foreach ( (array) $candidates as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Lee un campo personalizado del checkout guardado en el pedido.
	 *
	 * @param WC_Order $order Pedido.
	 * @param string   $key   Clave del meta.
	 * @return string
	 */
	protected function get_order_field( $order, $key ) {
		$value = $order->get_meta( $key );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Nombre legible del estado/departamento, usado como respaldo de la ciudad.
	 *
	 * @param string $country Pais alpha-2.
	 * @param string $state   Codigo de estado tal cual lo guarda WooCommerce.
	 * @return string
	 */
	protected function get_state_label( $country, $state ) {
		if ( '' === (string) $state || ! function_exists( 'WC' ) || ! WC()->countries ) {
			return '';
		}

		$states = WC()->countries->get_states( strtoupper( (string) $country ) );

		return is_array( $states ) && isset( $states[ $state ] ) ? (string) $states[ $state ] : '';
	}

	/**
	 * Bloque geografico del domicilio en el formato de la API.
	 *
	 * EMV 3DS valida estos campos como un conjunto: billAddrLine1, billAddrCity y
	 * billAddrCountry son obligatorios en cuanto aparece cualquiera de ellos. Un
	 * grupo incompleto devuelve ISO 97 con el error 58 "Invalid 3DS field", asi
	 * que se envia entero o no se envia. El pais viaja en ISO 3166-1 numerico.
	 *
	 * @param string $line1    Linea 1.
	 * @param string $line2    Linea 2.
	 * @param string $city     Ciudad.
	 * @param string $state    Estado ISO 3166-2 sin prefijo de pais.
	 * @param string $postcode Codigo postal.
	 * @param string $country  Pais alpha-2.
	 * @return array
	 */
	protected function build_address_group( $line1, $line2, $city, $state, $postcode, $country ) {
		$group = array(
			'Line1'       => WC_PowerTranz_Helper::sanitize_3ds_text( $line1, 50 ),
			'Line2'       => WC_PowerTranz_Helper::sanitize_3ds_text( $line2, 50 ),
			'City'        => WC_PowerTranz_Helper::sanitize_3ds_text( $city, 50 ),
			'State'       => WC_PowerTranz_Helper::sanitize_3ds_text( $state, 50 ),
			'PostalCode'  => WC_PowerTranz_Helper::sanitize_3ds_text( $postcode, 20 ),
			'CountryCode' => WC_PowerTranz_Helper::get_numeric_country( $country ),
		);

		if ( '' === $group['Line1'] || '' === $group['City'] || '' === $group['CountryCode'] ) {
			return array();
		}

		return $group;
	}

	/**
	 * Domicilio de facturacion en el formato de la API.
	 *
	 * Se prefieren siempre los campos estandar de WooCommerce; solo si estan
	 * vacios se recurre a los campos personalizados del checkout y, como ultimo
	 * recurso, al domicilio de envio o a la direccion base de la tienda.
	 *
	 * Los campos se transliteran a ISO 8859 porque EMV 3DS rechaza tildes y enies.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array
	 */
	protected function build_billing_address( $order ) {
		$countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries : null;

		$country = $this->first_filled(
			array(
				$order->get_billing_country(),
				$order->get_shipping_country(),
				$countries ? $countries->get_base_country() : '',
			)
		);

		$line1 = $this->first_filled(
			array(
				$order->get_billing_address_1(),
				$this->get_order_field( $order, '_billing_direccion_de_facturacion' ),
				$order->get_shipping_address_1(),
			)
		);

		$line2 = $this->first_filled(
			array(
				$order->get_billing_address_2(),
				$order->get_shipping_address_2(),
			)
		);

		$state_raw = $this->first_filled(
			array(
				$order->get_billing_state(),
				$order->get_shipping_state(),
				$countries ? $countries->get_base_state() : '',
			)
		);

		$city = $this->first_filled(
			array(
				$order->get_billing_city(),
				$order->get_shipping_city(),
				$countries ? $countries->get_base_city() : '',
				$this->get_state_label( $country, $state_raw ),
			)
		);

		$postcode = $this->first_filled(
			array(
				$order->get_billing_postcode(),
				$order->get_shipping_postcode(),
			)
		);

		$first_name = $this->first_filled(
			array(
				$order->get_billing_first_name(),
				$this->get_order_field( $order, '_billing_factura_a_nombre_de' ),
			)
		);

		$address = array(
			'FirstName'    => WC_PowerTranz_Helper::sanitize_3ds_text( $first_name, 50 ),
			'LastName'     => WC_PowerTranz_Helper::sanitize_3ds_text( $order->get_billing_last_name(), 50 ),
			'EmailAddress' => WC_PowerTranz_Helper::limit( $order->get_billing_email(), 100 ),
			'PhoneNumber'  => WC_PowerTranz_Helper::sanitize_phone( $order->get_billing_phone() ),
		);

		$address = array_merge(
			$address,
			$this->build_address_group( $line1, $line2, $city, $state_raw ? $this->get_state_code( $country, $state_raw ) : '', $postcode, $country )
		);

		$address = array_filter(
			$address,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		/**
		 * Permite ajustar el domicilio de facturacion enviado a PowerTranz.
		 *
		 * @param array    $address Domicilio ya normalizado.
		 * @param WC_Order $order   Pedido.
		 */
		return (array) apply_filters( 'powertranz_billing_address', $address, $order );
	}

		/**
	 * Domicilio de envio en el formato de la API.
	 *
	 * Misma regla que en facturacion: campos estandar primero, personalizados
	 * despues, y el bloque geografico solo se envia si esta completo.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array
	 */
	protected function build_shipping_address( $order ) {
		if ( ! $order->get_shipping_address_1() && ! $order->get_shipping_city() ) {
			return array();
		}

		$countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries : null;

		$country = $this->first_filled(
			array(
				$order->get_shipping_country(),
				$order->get_billing_country(),
				$countries ? $countries->get_base_country() : '',
			)
		);

		$state_raw = $this->first_filled(
			array(
				$order->get_shipping_state(),
				$order->get_billing_state(),
				$countries ? $countries->get_base_state() : '',
			)
		);

		$city = $this->first_filled(
			array(
				$order->get_shipping_city(),
				$order->get_billing_city(),
				$countries ? $countries->get_base_city() : '',
				$this->get_state_label( $country, $state_raw ),
			)
		);

		$phone = $this->first_filled(
			array(
				$this->get_order_field( $order, '_shipping_telefono_de_quien_recibe' ),
				$order->get_billing_phone(),
			)
		);

		$address = array(
			'FirstName'   => WC_PowerTranz_Helper::sanitize_3ds_text( $order->get_shipping_first_name(), 50 ),
			'LastName'    => WC_PowerTranz_Helper::sanitize_3ds_text( $order->get_shipping_last_name(), 50 ),
			'PhoneNumber' => WC_PowerTranz_Helper::sanitize_phone( $phone ),
		);

		$address = array_merge(
			$address,
			$this->build_address_group(
				$this->first_filled( array( $order->get_shipping_address_1(), $order->get_billing_address_1() ) ),
				$order->get_shipping_address_2(),
				$city,
				$state_raw ? $this->get_state_code( $country, $state_raw ) : '',
				$this->first_filled( array( $order->get_shipping_postcode(), $order->get_billing_postcode() ) ),
				$country
			)
		);

		$address = array_filter(
			$address,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		/**
		 * Permite ajustar el domicilio de envio enviado a PowerTranz.
		 *
		 * @param array    $address Domicilio ya normalizado.
		 * @param WC_Order $order   Pedido.
		 */
		return (array) apply_filters( 'powertranz_shipping_address', $address, $order );
	}

	/**
	 * Normaliza el codigo de estado/provincia a ISO 3166-2 (solo la subdivision).
	 *
	 * @param string $country Pais.
	 * @param string $state   Estado.
	 * @return string
	 */
	protected function get_state_code( $country, $state ) {
		$state = (string) $state;
		if ( '' === $state ) {
			return '';
		}
		// WooCommerce ya guarda el codigo; se elimina el prefijo de pais si existe.
		if ( 0 === strpos( $state, strtoupper( (string) $country ) . '-' ) ) {
			$state = substr( $state, 3 );
		}
		return $state;
	}

	/**
	 * Indica si el domicilio de envio coincide con el de facturacion.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	protected function addresses_match( $order ) {
		$billing  = array(
			$order->get_billing_address_1(),
			$order->get_billing_city(),
			$order->get_billing_postcode(),
			$order->get_billing_country(),
		);
		$shipping = array(
			$order->get_shipping_address_1(),
			$order->get_shipping_city(),
			$order->get_shipping_postcode(),
			$order->get_shipping_country(),
		);

		return array_map( 'strtolower', $billing ) === array_map( 'strtolower', $shipping );
	}

	/**
	 * Bloque ExtendedData para una solicitud SPI con 3-D Secure.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array
	 */
	protected function build_extended_data( $order ) {
		return array(
			'ThreeDSecure'      => array(
				'ChallengeWindowSize' => (int) $this->get_option( 'challenge_window_size', 4 ),
				'ChallengeIndicator'  => (string) $this->get_option( 'challenge_indicator', '01' ),
			),
			'MerchantResponseUrl' => WC_PowerTranz_3DS_Handler::get_merchant_response_url( $order ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Registro en el pedido y en los logs
	 * ------------------------------------------------------------------ */

	/**
	 * Escribe en el log de WooCommerce.
	 *
	 * @param string $message Mensaje.
	 * @param string $level   Nivel.
	 * @param array  $context Contexto.
	 */
	public function log( $message, $level = 'info', $context = array() ) {
		WC_PowerTranz_Logger::log( '[' . $this->id . '] ' . $message, $level, $context );
	}

	/**
	 * Registra un paso de la transaccion: nota en el pedido, metadatos,
	 * historial auditable y linea en el log de WooCommerce.
	 *
	 * @param WC_Order               $order    Pedido.
	 * @param string                 $step     Paso ejecutado.
	 * @param WC_PowerTranz_Response $response Respuesta.
	 * @param array                  $extra    Datos adicionales para la nota.
	 */
	public function record_transaction( $order, $step, $response, $extra = array() ) {
		$iso      = $response->get_iso_code();
		$approved = $response->is_approved();
		$three_ds = $response->get_three_ds();

		$entry = array(
			'time'        => current_time( 'mysql' ),
			'step'        => $step,
			'environment' => $this->get_environment(),
			'iso'         => $iso,
			'message'     => $response->get_response_message(),
			'errors'      => $response->get_errors_text(),
			'txn'         => $response->get_transaction_id(),
			'auth_code'   => $response->get_auth_code(),
			'rrn'         => $response->get_rrn(),
			'amount'      => $response->get_amount(),
			'http'        => $response->get_http_code(),
			'approved'    => $approved ? 'yes' : 'no',
			'3ds_status'  => $three_ds['status'],
			'3ds_eci'     => $three_ds['eci'],
			'extra'       => $extra,
		);

		$audit   = $order->get_meta( '_powertranz_audit' );
		$audit   = is_array( $audit ) ? $audit : array();
		$audit[] = $entry;
		$order->update_meta_data( '_powertranz_audit', $audit );

		$this->save_response_meta( $order, $response );

		// Nota en el pedido.
		$note_lines = array( $this->get_step_label( $step ) );

		if ( $iso ) {
			$note_lines[] = sprintf(
				/* translators: 1: codigo ISO, 2: mensaje */
				__( 'Codigo ISO: %1$s (%2$s)', 'powertranz-woocommerce' ),
				$iso,
				WC_PowerTranz_Helper::get_iso_message( $iso, $response->get_response_message() )
			);
		}
		if ( $response->get_response_message() ) {
			$note_lines[] = __( 'Respuesta: ', 'powertranz-woocommerce' ) . $response->get_response_message();
		}
		if ( $response->get_errors_text() ) {
			$note_lines[] = __( 'Errores: ', 'powertranz-woocommerce' ) . $response->get_errors_text();
		}
		if ( $response->is_transport_error() ) {
			$note_lines[] = __( 'Error de comunicacion: ', 'powertranz-woocommerce' ) . $response->get_wp_error()->get_error_message();
		}
		if ( $response->get_transaction_id() ) {
			$note_lines[] = __( 'Transaccion: ', 'powertranz-woocommerce' ) . $response->get_transaction_id();
		}
		if ( $response->get_auth_code() ) {
			$note_lines[] = __( 'Autorizacion: ', 'powertranz-woocommerce' ) . $response->get_auth_code();
		}
		if ( $response->get_rrn() ) {
			$note_lines[] = __( 'RRN: ', 'powertranz-woocommerce' ) . $response->get_rrn();
		}
		if ( $three_ds['status'] ) {
			$note_lines[] = sprintf(
				/* translators: 1: estatus 3DS, 2: ECI, 3: version del protocolo */
				__( '3-D Secure: %1$s | ECI %2$s | version %3$s', 'powertranz-woocommerce' ),
				WC_PowerTranz_Helper::get_3ds_status_label( $three_ds['status'] ),
				$three_ds['eci'] ? $three_ds['eci'] : '-',
				$three_ds['version'] ? $three_ds['version'] : '-'
			);
			$note_lines[] = WC_PowerTranz_Helper::is_liability_shifted( $three_ds )
				? __( 'Transferencia de responsabilidad al emisor: si', 'powertranz-woocommerce' )
				: __( 'Transferencia de responsabilidad al emisor: no', 'powertranz-woocommerce' );
		}
		if ( $response->get_avs_response() ) {
			$note_lines[] = __( 'AVS: ', 'powertranz-woocommerce' ) . $response->get_avs_response();
		}
		if ( $response->get_cvv_response() ) {
			$note_lines[] = __( 'CVV: ', 'powertranz-woocommerce' ) . $response->get_cvv_response();
		}
		foreach ( $extra as $label => $value ) {
			if ( is_scalar( $value ) && '' !== $value ) {
				$note_lines[] = $label . ': ' . $value;
			}
		}
		$note_lines[] = sprintf(
			/* translators: %s: entorno */
			__( 'Entorno: %s', 'powertranz-woocommerce' ),
			$this->is_test() ? __( 'pruebas', 'powertranz-woocommerce' ) : __( 'produccion', 'powertranz-woocommerce' )
		);

		$order->add_order_note( implode( "\n", array_filter( $note_lines ) ) );
		$order->save();

		WC_PowerTranz_Logger::transaction(
			$order,
			$step,
			$response->get_log_message(),
			$response->to_array(),
			$approved || $response->requires_redirect() || in_array( $iso, WC_PowerTranz_Helper::get_success_stage_codes(), true ) ? 'info' : 'warning'
		);
	}

	/**
	 * Etiqueta legible de cada paso.
	 *
	 * @param string $step Paso.
	 * @return string
	 */
	protected function get_step_label( $step ) {
		$labels = array(
			'spi_auth'      => __( 'PowerTranz: preautorizacion con 3-D Secure iniciada.', 'powertranz-woocommerce' ),
			'spi_sale'      => __( 'PowerTranz: venta con 3-D Secure iniciada.', 'powertranz-woocommerce' ),
			'3ds_result'    => __( 'PowerTranz: resultado de la autenticacion 3-D Secure.', 'powertranz-woocommerce' ),
			'spi_payment'   => __( 'PowerTranz: finalizacion del cobro tras 3-D Secure.', 'powertranz-woocommerce' ),
			'auth'          => __( 'PowerTranz: preautorizacion sin 3-D Secure.', 'powertranz-woocommerce' ),
			'sale'          => __( 'PowerTranz: venta sin 3-D Secure.', 'powertranz-woocommerce' ),
			'applepay_sale' => __( 'PowerTranz: venta con Apple Pay.', 'powertranz-woocommerce' ),
			'applepay_auth' => __( 'PowerTranz: preautorizacion con Apple Pay.', 'powertranz-woocommerce' ),
			'capture'       => __( 'PowerTranz: captura.', 'powertranz-woocommerce' ),
			'void'          => __( 'PowerTranz: anulacion.', 'powertranz-woocommerce' ),
			'refund'        => __( 'PowerTranz: reembolso.', 'powertranz-woocommerce' ),
		);

		return $labels[ $step ] ?? sprintf(
			/* translators: %s: nombre del paso */
			__( 'PowerTranz: %s.', 'powertranz-woocommerce' ),
			$step
		);
	}

	/**
	 * Guarda en el pedido los datos utiles de una respuesta.
	 *
	 * @param WC_Order               $order    Pedido.
	 * @param WC_PowerTranz_Response $response Respuesta.
	 */
	protected function save_response_meta( $order, $response ) {
		$order->update_meta_data( '_powertranz_environment', $this->get_environment() );

		if ( $response->get_transaction_id() ) {
			$order->update_meta_data( '_powertranz_transaction_id', $response->get_transaction_id() );
		}
		if ( $response->get_iso_code() ) {
			$order->update_meta_data( '_powertranz_iso_code', $response->get_iso_code() );
		}
		if ( $response->get_response_message() ) {
			$order->update_meta_data( '_powertranz_response_message', $response->get_response_message() );
		}
		if ( $response->get_auth_code() ) {
			$order->update_meta_data( '_powertranz_auth_code', $response->get_auth_code() );
		}
		if ( $response->get_rrn() ) {
			$order->update_meta_data( '_powertranz_rrn', $response->get_rrn() );
		}
		if ( $response->get_card_brand() ) {
			$order->update_meta_data( '_powertranz_card_brand', $response->get_card_brand() );
		}
		if ( $response->get_pan_token() ) {
			$order->update_meta_data( '_powertranz_pan_token', $response->get_pan_token() );
		}
		if ( $response->get_avs_response() ) {
			$order->update_meta_data( '_powertranz_avs_response', $response->get_avs_response() );
		}
		if ( $response->get_cvv_response() ) {
			$order->update_meta_data( '_powertranz_cvv_response', $response->get_cvv_response() );
		}

		$three_ds = $response->get_three_ds();
		if ( $three_ds['status'] || $three_ds['eci'] ) {
			$order->update_meta_data( '_powertranz_3ds_status', $three_ds['status'] );
			$order->update_meta_data( '_powertranz_3ds_eci', $three_ds['eci'] );
			$order->update_meta_data( '_powertranz_3ds_version', $three_ds['version'] );
			$order->update_meta_data( '_powertranz_3ds_ds_trans_id', $three_ds['ds_trans_id'] );
			$order->update_meta_data( '_powertranz_3ds_cavv_present', $three_ds['cavv'] );
			$order->update_meta_data( '_powertranz_3ds_liability_shift', WC_PowerTranz_Helper::is_liability_shifted( $three_ds ) ? 'yes' : 'no' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Cierre del pedido
	 * ------------------------------------------------------------------ */

	/**
	 * Marca el pedido como pagado o autorizado segun el modo de cobro.
	 *
	 * @param WC_Order               $order    Pedido.
	 * @param WC_PowerTranz_Response $response Respuesta aprobada.
	 * @param string                 $mode     sale|auth.
	 */
	protected function complete_order( $order, $response, $mode = 'sale' ) {
		$transaction_id = $response->get_transaction_id();

		$order->delete_meta_data( '_powertranz_last_error' );

		if ( $transaction_id ) {
			$order->set_transaction_id( $transaction_id );
		}

		if ( 'auth' === $mode ) {
			$order->update_meta_data( '_powertranz_captured', 'no' );
			$order->update_meta_data( '_powertranz_authorized_amount', WC_PowerTranz_Helper::format_amount( $order->get_total(), $order->get_currency() ) );
			$order->save();

			$order->update_status(
				$this->get_option( 'authorized_order_status', 'on-hold' ),
				__( 'Fondos autorizados en PowerTranz. Pendiente de captura.', 'powertranz-woocommerce' )
			);

			wc_maybe_reduce_stock_levels( $order->get_id() );
		} else {
			$order->update_meta_data( '_powertranz_captured', 'yes' );
			$order->save();
			$order->payment_complete( $transaction_id );
		}

		// En el retorno de 3-D Secure la peticion llega sin cookies de sesion,
		// por lo que el carrito estaria vacio: se vacia solo si hay algo que
		// vaciar y el hook woocommerce_thankyou remata el caso restante.
		if ( WC()->cart && ! WC()->cart->is_empty() ) {
			WC()->cart->empty_cart();
		}
	}

	/**
	 * Muestra al cliente un aviso explicito de transaccion denegada.
	 *
	 * FAC exige que el resultado, aprobado o denegado, se comunique al cliente.
	 *
	 * @param string $message Motivo apto para el cliente.
	 */
	protected function add_declined_notice( $message ) {
		wc_add_notice(
			'<strong>' . esc_html__( 'Transaccion denegada.', 'powertranz-woocommerce' ) . '</strong> ' . esc_html( $message ),
			'error'
		);
	}

	/**
	 * Marca el pedido como fallido.
	 *
	 * @param WC_Order               $order    Pedido.
	 * @param WC_PowerTranz_Response $response Respuesta.
	 */
	protected function fail_order( $order, $response ) {
		$reason = $response->get_log_message();

		$order->update_meta_data( '_powertranz_last_error', $response->get_customer_message() );
		$order->save();

		if ( ! $order->has_status( array( 'failed', 'cancelled', 'refunded' ) ) ) {
			$order->update_status(
				'failed',
				sprintf(
					/* translators: %s: motivo tecnico */
					__( 'PowerTranz rechazo la transaccion: %s', 'powertranz-woocommerce' ),
					$reason
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Captura, anulacion y reembolso
	 * ------------------------------------------------------------------ */

	/**
	 * Captura una preautorizacion.
	 *
	 * @param WC_Order   $order  Pedido.
	 * @param float|null $amount Importe (null = total autorizado).
	 * @return true|WP_Error
	 */
	public function capture_payment( $order, $amount = null ) {
		$transaction_id = (string) $order->get_meta( '_powertranz_transaction_id' );

		if ( '' === $transaction_id ) {
			return new WP_Error( 'powertranz_no_transaction', __( 'El pedido no tiene una transaccion de PowerTranz asociada.', 'powertranz-woocommerce' ) );
		}
		if ( 'yes' === $order->get_meta( '_powertranz_captured' ) ) {
			return new WP_Error( 'powertranz_already_captured', __( 'La transaccion ya fue capturada.', 'powertranz-woocommerce' ) );
		}

		$amount   = null === $amount ? $order->get_total() : $amount;
		$currency = $order->get_currency();

		$response = $this->get_api_for_order( $order )->capture(
			array(
				'TransactionIdentifier' => $transaction_id,
				'TotalAmount'           => WC_PowerTranz_Helper::format_amount( $amount, $currency ),
				'CurrencyCode'          => WC_PowerTranz_Helper::get_numeric_currency( $currency ),
				'ExternalIdentifier'    => WC_PowerTranz_Helper::limit( 'wc-cap-' . $order->get_id(), 50 ),
			)
		);

		$this->record_transaction(
			$order,
			'capture',
			$response,
			array( __( 'Importe capturado', 'powertranz-woocommerce' ) => wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ) )
		);

		if ( ! $response->is_approved() ) {
			return new WP_Error( 'powertranz_capture_failed', $response->get_log_message() );
		}

		$order->update_meta_data( '_powertranz_captured', 'yes' );
		$order->update_meta_data( '_powertranz_captured_amount', WC_PowerTranz_Helper::format_amount( $amount, $currency ) );
		$order->save();

		$order->payment_complete( $transaction_id );

		return true;
	}

	/**
	 * Anula una autorizacion (no admite anulaciones parciales).
	 *
	 * @param WC_Order $order Pedido.
	 * @return true|WP_Error
	 */
	public function void_payment( $order ) {
		$transaction_id = (string) $order->get_meta( '_powertranz_transaction_id' );

		if ( '' === $transaction_id ) {
			return new WP_Error( 'powertranz_no_transaction', __( 'El pedido no tiene una transaccion de PowerTranz asociada.', 'powertranz-woocommerce' ) );
		}
		if ( 'yes' === $order->get_meta( '_powertranz_voided' ) ) {
			return new WP_Error( 'powertranz_already_voided', __( 'La transaccion ya fue anulada.', 'powertranz-woocommerce' ) );
		}

		$currency = $order->get_currency();

		$response = $this->get_api_for_order( $order )->void(
			array(
				'TransactionIdentifier' => $transaction_id,
				'TotalAmount'           => WC_PowerTranz_Helper::format_amount( $order->get_total(), $currency ),
				'CurrencyCode'          => WC_PowerTranz_Helper::get_numeric_currency( $currency ),
				'ExternalIdentifier'    => WC_PowerTranz_Helper::limit( 'wc-void-' . $order->get_id(), 50 ),
			)
		);

		$this->record_transaction( $order, 'void', $response );

		if ( ! $response->is_approved() ) {
			return new WP_Error( 'powertranz_void_failed', $response->get_log_message() );
		}

		$order->update_meta_data( '_powertranz_voided', 'yes' );
		$order->update_meta_data( '_powertranz_captured', 'no' );
		$order->save();

		$order->update_status( 'cancelled', __( 'Autorizacion anulada en PowerTranz.', 'powertranz-woocommerce' ) );

		return true;
	}

	/**
	 * Reembolso desde la pantalla de pedido de WooCommerce.
	 *
	 * @param int    $order_id Id de pedido.
	 * @param float  $amount   Importe.
	 * @param string $reason   Motivo.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'powertranz_no_order', __( 'Pedido no encontrado.', 'powertranz-woocommerce' ) );
		}

		$transaction_id = (string) $order->get_meta( '_powertranz_transaction_id' );
		if ( '' === $transaction_id ) {
			return new WP_Error( 'powertranz_no_transaction', __( 'El pedido no tiene una transaccion de PowerTranz asociada.', 'powertranz-woocommerce' ) );
		}

		$currency = $order->get_currency();
		$amount   = null === $amount ? $order->get_total() : (float) $amount;

		if ( $amount <= 0 ) {
			return new WP_Error( 'powertranz_invalid_amount', __( 'El importe a reembolsar debe ser mayor que cero.', 'powertranz-woocommerce' ) );
		}

		// Si aun no se ha capturado, lo correcto es anular, no reembolsar.
		$is_captured = 'no' !== $order->get_meta( '_powertranz_captured' );

		if ( ! $is_captured && WC_PowerTranz_Helper::format_amount( $amount, $currency ) >= WC_PowerTranz_Helper::format_amount( $order->get_total(), $currency ) ) {
			$voided = $this->void_payment( $order );
			return is_wp_error( $voided ) ? $voided : true;
		}

		$payload = array(
			'TransactionIdentifier' => $transaction_id,
			'TotalAmount'           => WC_PowerTranz_Helper::format_amount( $amount, $currency ),
			'CurrencyCode'          => WC_PowerTranz_Helper::get_numeric_currency( $currency ),
			'ExternalIdentifier'    => WC_PowerTranz_Helper::limit( 'wc-ref-' . $order->get_id() . '-' . time(), 50 ),
		);

		$response = $this->get_api_for_order( $order )->refund( $payload );

		$extra = array(
			__( 'Importe reembolsado', 'powertranz-woocommerce' ) => wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ),
		);
		if ( $reason ) {
			$extra[ __( 'Motivo', 'powertranz-woocommerce' ) ] = WC_PowerTranz_Helper::limit( $reason, 200 );
		}

		$this->record_transaction( $order, 'refund', $response, $extra );

		if ( ! $response->is_approved() ) {
			return new WP_Error( 'powertranz_refund_failed', $response->get_log_message() );
		}

		$refunded = (float) $order->get_meta( '_powertranz_refunded_amount' );
		$order->update_meta_data( '_powertranz_refunded_amount', WC_PowerTranz_Helper::format_amount( $refunded + $amount, $currency ) );
		$order->save();

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Campo de ajustes: tabla de planes de cuotas
	 * ------------------------------------------------------------------ */

	/**
	 * Render del campo personalizado de planes de cuotas.
	 *
	 * @param string $key  Clave.
	 * @param array  $data Datos del campo.
	 * @return string
	 */
	public function generate_powertranz_plans_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'       => '',
			'description' => '',
		);
		$data      = wp_parse_args( $data, $defaults );

		$plans = $this->installments->get_plans();

		$columns = array(
			'cuotas'        => __( 'Cuotas', 'powertranz-woocommerce' ),
			'label'         => __( 'Etiqueta visible', 'powertranz-woocommerce' ),
			'test_id'       => __( 'PowerTranz Id (pruebas)', 'powertranz-woocommerce' ),
			'test_password' => __( 'Password (pruebas)', 'powertranz-woocommerce' ),
			'live_id'       => __( 'PowerTranz Id (produccion)', 'powertranz-woocommerce' ),
			'live_password' => __( 'Password (produccion)', 'powertranz-woocommerce' ),
			'min_amount'    => __( 'Importe minimo', 'powertranz-woocommerce' ),
			'bins'          => __( 'BINs permitidos', 'powertranz-woocommerce' ),
			'extra'         => __( 'Campos extra (JSON)', 'powertranz-woocommerce' ),
		);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo wp_kses_post( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<table class="widefat wc-powertranz-plans" id="wc-powertranz-plans">
					<thead>
						<tr>
							<?php foreach ( $columns as $column_key => $label ) : ?>
								<th class="pt-col-<?php echo esc_attr( $column_key ); ?>"><?php echo esc_html( $label ); ?></th>
							<?php endforeach; ?>
							<th class="pt-col-actions">&nbsp;</th>
						</tr>
					</thead>
					<tbody>
						<?php
						$index = 0;
						foreach ( $plans as $plan ) :
							?>
							<tr>
								<td><input type="number" min="2" max="99" step="1" name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $index ); ?>][cuotas]" value="<?php echo esc_attr( $plan['cuotas'] ); ?>" /></td>
								<td><input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $plan['label'] ); ?>" /></td>
								<td><input type="text" autocomplete="off" name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $index ); ?>][test_id]" value="<?php echo esc_attr( $plan['test_id'] ); ?>" /></td>
								<td><input type="password" autocomplete="new-password" name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $index ); ?>][test_password]" value="<?php echo esc_attr( $plan['test_password'] ); ?>" /></td>
								<td><input type="text" autocomplete="off" name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $index ); ?>][live_id]" value="<?php echo esc_attr( $plan['live_id'] ); ?>" /></td>
								<td><input type="password" autocomplete="new-password" name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $index ); ?>][live_password]" value="<?php echo esc_attr( $plan['live_password'] ); ?>" /></td>
								<td><input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $index ); ?>][min_amount]" value="<?php echo esc_attr( $plan['min_amount'] ? $plan['min_amount'] : '' ); ?>" placeholder="0" /></td>
								<td><input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $index ); ?>][bins]" value="<?php echo esc_attr( implode( ', ', $plan['bins'] ) ); ?>" placeholder="454617, 528745" /></td>
								<td><input type="text" name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $index ); ?>][extra]" value="<?php echo esc_attr( $plan['extra'] ? wp_json_encode( $plan['extra'] ) : '' ); ?>" placeholder="{}" /></td>
								<td><button type="button" class="button pt-remove-plan" aria-label="<?php esc_attr_e( 'Eliminar plan', 'powertranz-woocommerce' ); ?>">&times;</button></td>
							</tr>
							<?php
							++$index;
						endforeach;
						?>
					</tbody>
					<tfoot>
						<tr>
							<td colspan="10">
								<button type="button" class="button pt-add-plan" data-index="<?php echo esc_attr( $index ); ?>" data-name="<?php echo esc_attr( $field_key ); ?>"><?php esc_html_e( 'Anadir plan de cuotas', 'powertranz-woocommerce' ); ?></button>
							</td>
						</tr>
					</tfoot>
				</table>
				<?php if ( $data['description'] ) : ?>
					<p class="description"><?php echo wp_kses_post( $data['description'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Validacion del campo personalizado de planes.
	 *
	 * @param string $key   Clave.
	 * @param mixed  $value Valor recibido.
	 * @return array
	 */
	public function validate_powertranz_plans_field( $key, $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();
		$seen  = array();

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$cuotas = isset( $row['cuotas'] ) ? absint( $row['cuotas'] ) : 0;
			if ( $cuotas < 2 || isset( $seen[ $cuotas ] ) ) {
				continue;
			}
			$seen[ $cuotas ] = true;

			$extra = '';
			if ( ! empty( $row['extra'] ) ) {
				$decoded = json_decode( (string) wp_unslash( $row['extra'] ), true );
				if ( is_array( $decoded ) ) {
					$extra = wp_json_encode( $decoded );
				} else {
					WC_Admin_Settings::add_error(
						sprintf(
							/* translators: %d: numero de cuotas */
							__( 'PowerTranz: el JSON de campos extra del plan de %d cuotas no es valido y se ha descartado.', 'powertranz-woocommerce' ),
							$cuotas
						)
					);
				}
			}

			$clean[] = array(
				'cuotas'        => $cuotas,
				'label'         => isset( $row['label'] ) ? sanitize_text_field( wp_unslash( $row['label'] ) ) : '',
				'test_id'       => isset( $row['test_id'] ) ? sanitize_text_field( wp_unslash( $row['test_id'] ) ) : '',
				'test_password' => isset( $row['test_password'] ) ? trim( (string) wp_unslash( $row['test_password'] ) ) : '',
				'live_id'       => isset( $row['live_id'] ) ? sanitize_text_field( wp_unslash( $row['live_id'] ) ) : '',
				'live_password' => isset( $row['live_password'] ) ? trim( (string) wp_unslash( $row['live_password'] ) ) : '',
				'min_amount'    => isset( $row['min_amount'] ) ? wc_format_decimal( wp_unslash( $row['min_amount'] ) ) : '',
				'bins'          => isset( $row['bins'] ) ? sanitize_text_field( wp_unslash( $row['bins'] ) ) : '',
				'extra'         => $extra,
				'enabled'       => 'yes',
			);
		}

		usort(
			$clean,
			static function ( $a, $b ) {
				return $a['cuotas'] <=> $b['cuotas'];
			}
		);

		return $clean;
	}
}
