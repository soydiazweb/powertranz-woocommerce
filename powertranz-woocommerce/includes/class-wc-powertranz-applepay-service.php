<?php
/**
 * Servicios de Apple Pay: verificacion de dominio, validacion de sesion de
 * comercio y datos del carrito para la hoja de pago.
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PowerTranz_ApplePay_Service
 */
class WC_PowerTranz_ApplePay_Service {

	/**
	 * Ruta del archivo de asociacion de dominio de Apple.
	 */
	const DOMAIN_ASSOCIATION_PATH = '.well-known/apple-developer-merchantid-domain-association';

	/**
	 * Certificado de identidad del comercio para la peticion TLS mutua.
	 *
	 * @var array
	 */
	protected $tls_client_cert = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'maybe_serve_domain_association' ), 1 );

		add_action( 'wp_ajax_powertranz_applepay_validate', array( $this, 'ajax_validate_merchant' ) );
		add_action( 'wp_ajax_nopriv_powertranz_applepay_validate', array( $this, 'ajax_validate_merchant' ) );

		add_action( 'wp_ajax_powertranz_applepay_data', array( $this, 'ajax_payment_request_data' ) );
		add_action( 'wp_ajax_nopriv_powertranz_applepay_data', array( $this, 'ajax_payment_request_data' ) );

		add_action( 'http_api_curl', array( $this, 'apply_client_certificate' ), 10, 3 );
	}

	/**
	 * Devuelve la pasarela de Apple Pay.
	 *
	 * @return WC_Gateway_PowerTranz_ApplePay|null
	 */
	protected function get_gateway() {
		$gateway = WC_PowerTranz::get_gateway( 'powertranz_applepay' );
		return $gateway instanceof WC_Gateway_PowerTranz_ApplePay ? $gateway : null;
	}

	/**
	 * Lee un ajuste de la pasarela sin instanciar WooCommerce completo.
	 *
	 * @param string $key     Clave.
	 * @param string $default Valor por defecto.
	 * @return string
	 */
	protected function get_setting( $key, $default = '' ) {
		$settings = get_option( 'woocommerce_powertranz_applepay_settings', array() );
		if ( ! is_array( $settings ) ) {
			return $default;
		}
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/* ---------------------------------------------------------------------
	 * Verificacion de dominio
	 * ------------------------------------------------------------------ */

	/**
	 * Sirve el archivo de asociacion de dominio si WordPress atiende la ruta.
	 *
	 * Si el archivo ya existe fisicamente en el servidor web, este metodo no
	 * llega a ejecutarse.
	 */
	public function maybe_serve_domain_association() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path        = strtok( (string) $request_uri, '?' );
		$path        = trim( (string) $path, '/' );

		if ( self::DOMAIN_ASSOCIATION_PATH !== $path && self::DOMAIN_ASSOCIATION_PATH . '.txt' !== $path ) {
			return;
		}

		$contents = (string) $this->get_setting( 'domain_association', '' );
		$contents = trim( $contents );

		if ( '' === $contents ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Length: ' . strlen( $contents ) );
		echo $contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Contenido literal suministrado por Apple.
		exit;
	}

	/**
	 * URL publica del archivo de asociacion de dominio.
	 *
	 * @return string
	 */
	public static function get_domain_association_url() {
		return home_url( '/' . self::DOMAIN_ASSOCIATION_PATH );
	}

	/* ---------------------------------------------------------------------
	 * Validacion de la sesion de comercio
	 * ------------------------------------------------------------------ */

	/**
	 * Endpoint AJAX que obtiene la sesion de comercio de los servidores de Apple.
	 */
	public function ajax_validate_merchant() {
		check_ajax_referer( 'powertranz_applepay', 'nonce' );

		$gateway = $this->get_gateway();
		if ( ! $gateway || ! $gateway->is_available() ) {
			wp_send_json_error( array( 'message' => __( 'Apple Pay no esta disponible.', 'powertranz-woocommerce' ) ), 400 );
		}

		$validation_url = isset( $_POST['validationURL'] ) ? esc_url_raw( wp_unslash( $_POST['validationURL'] ) ) : '';

		if ( ! $this->is_valid_apple_url( $validation_url ) ) {
			WC_PowerTranz_Logger::log( 'Apple Pay: se rechazo una validationURL fuera de apple.com: ' . $validation_url, 'error' );
			wp_send_json_error( array( 'message' => __( 'La URL de validacion no pertenece a Apple.', 'powertranz-woocommerce' ) ), 400 );
		}

		$session = $this->request_merchant_session( $validation_url );

		if ( is_wp_error( $session ) ) {
			WC_PowerTranz_Logger::log( 'Apple Pay: fallo la validacion de comercio. ' . $session->get_error_message(), 'error' );
			wp_send_json_error( array( 'message' => $session->get_error_message() ), 400 );
		}

		WC_PowerTranz_Logger::log( 'Apple Pay: sesion de comercio obtenida correctamente.', 'info' );
		wp_send_json_success( $session );
	}

	/**
	 * Comprueba que la URL de validacion es de Apple.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	protected function is_valid_apple_url( $url ) {
		if ( '' === $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}
		if ( empty( $parts['host'] ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] );

		return 'apple.com' === $host || substr( $host, -10 ) === '.apple.com';
	}

	/**
	 * Solicita la sesion de comercio a Apple mediante TLS mutuo.
	 *
	 * @param string $validation_url URL suministrada por ApplePaySession.
	 * @return array|WP_Error
	 */
	protected function request_merchant_session( $validation_url ) {
		$merchant_identifier = trim( (string) $this->get_setting( 'merchant_identifier' ) );
		$display_name        = trim( (string) $this->get_setting( 'display_name' ) );
		$domain              = $this->get_domain();

		if ( '' === $merchant_identifier ) {
			return new WP_Error( 'powertranz_applepay_no_merchant', __( 'Falta el identificador de comercio de Apple.', 'powertranz-woocommerce' ) );
		}
		if ( '' === $display_name ) {
			$display_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}

		$cert_path = trim( (string) $this->get_setting( 'identity_cert_path' ) );
		$key_path  = trim( (string) $this->get_setting( 'identity_key_path' ) );
		$key_pass  = (string) $this->get_setting( 'identity_key_password' );

		if ( '' === $cert_path || ! is_readable( $cert_path ) ) {
			return new WP_Error(
				'powertranz_applepay_no_identity_cert',
				__( 'No se puede leer el certificado de identidad del comercio de Apple Pay en la ruta configurada.', 'powertranz-woocommerce' )
			);
		}
		if ( '' !== $key_path && ! is_readable( $key_path ) ) {
			return new WP_Error(
				'powertranz_applepay_no_identity_key',
				__( 'No se puede leer la clave privada del certificado de identidad del comercio.', 'powertranz-woocommerce' )
			);
		}
		if ( ! function_exists( 'curl_version' ) ) {
			return new WP_Error(
				'powertranz_applepay_no_curl',
				__( 'La validacion de Apple Pay requiere la extension cURL de PHP para TLS con certificado de cliente.', 'powertranz-woocommerce' )
			);
		}

		$this->tls_client_cert = array(
			'cert'     => $cert_path,
			'key'      => $key_path,
			'password' => $key_pass,
		);

		$response = wp_remote_post(
			$validation_url,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'merchantIdentifier' => $merchant_identifier,
						'displayName'        => $display_name,
						'initiative'         => 'web',
						'initiativeContext'  => $domain,
					)
				),
			)
		);

		$this->tls_client_cert = array();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'powertranz_applepay_validation_failed',
				sprintf(
					/* translators: 1: codigo HTTP, 2: respuesta */
					__( 'Apple rechazo la validacion de comercio (HTTP %1$d): %2$s', 'powertranz-woocommerce' ),
					$code,
					WC_PowerTranz_Logger::truncate( $body, 300 )
				)
			);
		}

		$session = json_decode( $body, true );

		if ( ! is_array( $session ) ) {
			return new WP_Error( 'powertranz_applepay_bad_session', __( 'La respuesta de Apple no es una sesion de comercio valida.', 'powertranz-woocommerce' ) );
		}

		return $session;
	}

	/**
	 * Anade el certificado de cliente a la peticion cURL de validacion.
	 *
	 * @param resource|CurlHandle $handle Recurso cURL.
	 * @param array               $args   Argumentos.
	 * @param string              $url    URL.
	 */
	public function apply_client_certificate( $handle, $args, $url ) {
		if ( empty( $this->tls_client_cert['cert'] ) ) {
			return;
		}
		if ( ! $this->is_valid_apple_url( $url ) ) {
			return;
		}

		$cert = $this->tls_client_cert['cert'];
		$key  = $this->tls_client_cert['key'];
		$pass = $this->tls_client_cert['password'];

		$is_pkcs12 = in_array( strtolower( (string) pathinfo( $cert, PATHINFO_EXTENSION ) ), array( 'p12', 'pfx' ), true );

		// TLS 1.2 como minimo, igual que en las llamadas a PowerTranz.
		if ( defined( 'CURL_SSLVERSION_TLSv1_2' ) ) {
			curl_setopt( $handle, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2 );
		}

		curl_setopt( $handle, CURLOPT_SSLCERT, $cert );
		curl_setopt( $handle, CURLOPT_SSLCERTTYPE, $is_pkcs12 ? 'P12' : 'PEM' );

		if ( '' !== $pass ) {
			curl_setopt( $handle, CURLOPT_SSLCERTPASSWD, $pass );
		}

		if ( ! $is_pkcs12 && '' !== $key ) {
			curl_setopt( $handle, CURLOPT_SSLKEY, $key );
			curl_setopt( $handle, CURLOPT_SSLKEYTYPE, 'PEM' );
			if ( '' !== $pass ) {
				curl_setopt( $handle, CURLOPT_SSLKEYPASSWD, $pass );
			}
		}
	}

	/**
	 * Dominio usado como initiativeContext.
	 *
	 * @return string
	 */
	public function get_domain() {
		$configured = trim( (string) $this->get_setting( 'domain_name' ) );

		if ( '' !== $configured ) {
			return $configured;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host ? $host : '';
	}

	/* ---------------------------------------------------------------------
	 * Datos de la hoja de pago
	 * ------------------------------------------------------------------ */

	/**
	 * Devuelve los importes actuales para construir el ApplePayPaymentRequest.
	 */
	public function ajax_payment_request_data() {
		check_ajax_referer( 'powertranz_applepay', 'nonce' );

		$gateway = $this->get_gateway();
		if ( ! $gateway ) {
			wp_send_json_error( array( 'message' => __( 'Apple Pay no esta disponible.', 'powertranz-woocommerce' ) ), 400 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			$key   = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';

			if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
				wp_send_json_error( array( 'message' => __( 'Pedido no valido.', 'powertranz-woocommerce' ) ), 400 );
			}

			wp_send_json_success( $this->build_request_from_order( $order ) );
		}

		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_error( array( 'message' => __( 'El carrito esta vacio.', 'powertranz-woocommerce' ) ), 400 );
		}

		WC()->cart->calculate_totals();

		wp_send_json_success( $this->build_request_from_cart() );
	}

	/**
	 * Construye los importes desde el carrito.
	 *
	 * @return array
	 */
	protected function build_request_from_cart() {
		$cart      = WC()->cart;
		$currency  = get_woocommerce_currency();
		$total     = WC_PowerTranz_Helper::format_amount( $cart->get_total( 'edit' ), $currency );
		$subtotal  = WC_PowerTranz_Helper::format_amount( $cart->get_subtotal() + $cart->get_subtotal_tax(), $currency );
		$shipping  = WC_PowerTranz_Helper::format_amount( $cart->get_shipping_total() + $cart->get_shipping_tax(), $currency );
		$tax       = WC_PowerTranz_Helper::format_amount( $cart->get_total_tax(), $currency );
		$discount  = WC_PowerTranz_Helper::format_amount( $cart->get_discount_total() + $cart->get_discount_tax(), $currency );

		$line_items = array(
			array(
				'label'  => __( 'Subtotal', 'powertranz-woocommerce' ),
				'amount' => (string) $subtotal,
				'type'   => 'final',
			),
		);

		if ( $discount > 0 ) {
			$line_items[] = array(
				'label'  => __( 'Descuento', 'powertranz-woocommerce' ),
				'amount' => (string) ( -1 * $discount ),
				'type'   => 'final',
			);
		}
		if ( $shipping > 0 ) {
			$line_items[] = array(
				'label'  => __( 'Envio', 'powertranz-woocommerce' ),
				'amount' => (string) $shipping,
				'type'   => 'final',
			);
		}
		if ( $tax > 0 ) {
			$line_items[] = array(
				'label'  => __( 'Impuestos', 'powertranz-woocommerce' ),
				'amount' => (string) $tax,
				'type'   => 'final',
			);
		}

		return array(
			'currencyCode' => $currency,
			'countryCode'  => $this->get_country_code(),
			'total'        => (string) $total,
			'lineItems'    => $line_items,
		);
	}

	/**
	 * Construye los importes desde un pedido (pagina de pago de pedido).
	 *
	 * @param WC_Order $order Pedido.
	 * @return array
	 */
	protected function build_request_from_order( $order ) {
		$currency = $order->get_currency();

		return array(
			'currencyCode' => $currency,
			'countryCode'  => $this->get_country_code(),
			'total'        => (string) WC_PowerTranz_Helper::format_amount( $order->get_total(), $currency ),
			'lineItems'    => array(
				array(
					'label'  => sprintf(
						/* translators: %s: numero de pedido */
						__( 'Pedido %s', 'powertranz-woocommerce' ),
						$order->get_order_number()
					),
					'amount' => (string) WC_PowerTranz_Helper::format_amount( $order->get_total(), $currency ),
					'type'   => 'final',
				),
			),
		);
	}

	/**
	 * Codigo de pais de la tienda.
	 *
	 * @return string
	 */
	public function get_country_code() {
		$base = wc_get_base_location();
		return isset( $base['country'] ) && $base['country'] ? strtoupper( $base['country'] ) : 'US';
	}
}
