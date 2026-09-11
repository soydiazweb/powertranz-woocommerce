<?php
/**
 * Cliente HTTP de la API PowerTranz.
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PowerTranz_API
 */
class WC_PowerTranz_API {

	const ENDPOINT_TEST = 'https://staging.ptranz.com/api';
	const ENDPOINT_LIVE = 'https://gateway.ptranz.com/api';

	/**
	 * PowerTranz Id (merchant id).
	 *
	 * @var string
	 */
	protected $merchant_id;

	/**
	 * PowerTranz Password.
	 *
	 * @var string
	 */
	protected $merchant_password;

	/**
	 * Entorno: test | live.
	 *
	 * @var string
	 */
	protected $environment;

	/**
	 * Timeout en segundos.
	 *
	 * @var int
	 */
	protected $timeout;

	/**
	 * PowerTranz-GatewayKey opcional (solo si PowerTranz lo suministra).
	 *
	 * @var string
	 */
	protected $gateway_key = '';

	/**
	 * Constructor.
	 *
	 * @param string $merchant_id       PowerTranz Id.
	 * @param string $merchant_password PowerTranz Password.
	 * @param string $environment       test|live.
	 * @param int    $timeout           Timeout.
	 * @param string $gateway_key       PowerTranz-GatewayKey opcional.
	 */
	public function __construct( $merchant_id, $merchant_password, $environment = 'test', $timeout = 60, $gateway_key = '' ) {
		$this->merchant_id       = trim( (string) $merchant_id );
		$this->merchant_password = trim( (string) $merchant_password );
		$this->environment       = 'live' === $environment ? 'live' : 'test';
		$this->timeout           = (int) $timeout;
		$this->gateway_key       = trim( (string) $gateway_key );
	}

	/**
	 * Comprueba el estado de la pasarela (endpoint no financiero GET /api/alive).
	 *
	 * @return array {
	 *     @type bool   $ok      Si la pasarela responde.
	 *     @type int    $code    Codigo HTTP.
	 *     @type string $message Detalle.
	 * }
	 */
	public function alive() {
		$response = wp_remote_get(
			$this->get_base_url() . '/alive',
			array(
				'timeout'   => 15,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return array(
			'ok'      => $code >= 200 && $code < 300,
			'code'    => $code,
			'message' => wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * URL base segun entorno.
	 *
	 * @return string
	 */
	public function get_base_url() {
		$url = 'live' === $this->environment ? self::ENDPOINT_LIVE : self::ENDPOINT_TEST;

		/**
		 * Permite sobrescribir la URL base (por ejemplo para un entorno de certificacion propio).
		 *
		 * @param string $url         URL base sin barra final.
		 * @param string $environment test|live.
		 */
		return untrailingslashit( apply_filters( 'powertranz_api_base_url', $url, $this->environment ) );
	}

	/**
	 * Indica si las credenciales estan completas.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== $this->merchant_id && '' !== $this->merchant_password;
	}

	/**
	 * Entorno actual.
	 *
	 * @return string
	 */
	public function get_environment() {
		return $this->environment;
	}

	/* ---------------------------------------------------------------------
	 * Endpoints SPI (3-D Secure simplificado)
	 * ------------------------------------------------------------------ */

	/**
	 * Preautorizacion con SPI. Reserva fondos; requiere captura posterior.
	 *
	 * @param array $payload Cuerpo.
	 * @return WC_PowerTranz_Response
	 */
	public function spi_auth( $payload ) {
		return $this->request( '/spi/auth', $payload );
	}

	/**
	 * Venta con SPI. Autoriza y captura en un solo paso.
	 *
	 * @param array $payload Cuerpo.
	 * @return WC_PowerTranz_Response
	 */
	public function spi_sale( $payload ) {
		return $this->request( '/spi/sale', $payload );
	}

	/**
	 * Completa la transaccion SPI despues de la autenticacion 3-D Secure.
	 *
	 * El endpoint espera el SpiToken como cadena JSON en el cuerpo. Si la
	 * pasarela lo rechaza, se reintenta con el token envuelto en un objeto.
	 *
	 * @param string $spi_token Token SPI.
	 * @return WC_PowerTranz_Response
	 */
	public function spi_payment( $spi_token ) {
		$response = $this->request( '/spi/payment', $spi_token, true );

		$needs_retry = $response->is_transport_error()
			|| in_array( $response->get_http_code(), array( 400, 415, 422 ), true );

		if ( $needs_retry && ! $response->is_transport_error() ) {
			WC_PowerTranz_Logger::debug( 'spi/payment con token plano devolvio HTTP ' . $response->get_http_code() . '; reintentando con objeto.' );
			$retry = $this->request( '/spi/payment', array( 'SpiToken' => $spi_token ) );
			if ( $retry->is_approved() || 200 === $retry->get_http_code() ) {
				return $retry;
			}
		}

		return $response;
	}

	/* ---------------------------------------------------------------------
	 * Endpoints directos (sin 3-D Secure o post-autenticacion)
	 * ------------------------------------------------------------------ */

	/**
	 * Preautorizacion directa.
	 *
	 * @param array $payload Cuerpo.
	 * @return WC_PowerTranz_Response
	 */
	public function auth( $payload ) {
		return $this->request( '/auth', $payload );
	}

	/**
	 * Venta directa.
	 *
	 * @param array $payload Cuerpo.
	 * @return WC_PowerTranz_Response
	 */
	public function sale( $payload ) {
		return $this->request( '/sale', $payload );
	}

	/**
	 * Captura de una preautorizacion.
	 *
	 * @param array $payload Cuerpo.
	 * @return WC_PowerTranz_Response
	 */
	public function capture( $payload ) {
		return $this->request( '/capture', $payload );
	}

	/**
	 * Anulacion (void) de una transaccion del mismo dia.
	 *
	 * @param array $payload Cuerpo.
	 * @return WC_PowerTranz_Response
	 */
	public function void( $payload ) {
		return $this->request( '/void', $payload );
	}

	/**
	 * Reembolso total o parcial.
	 *
	 * @param array $payload Cuerpo.
	 * @return WC_PowerTranz_Response
	 */
	public function refund( $payload ) {
		return $this->request( '/refund', $payload );
	}

	/**
	 * Gestion de riesgo con SPI: autentica 3DS y/o tokeniza sin autorizar cobro.
	 *
	 * @param array $payload Cuerpo.
	 * @return WC_PowerTranz_Response
	 */
	public function spi_risk_mgmt( $payload ) {
		return $this->request( '/spi/riskmgmt', $payload );
	}

	/**
	 * Consulta de gestion de riesgo / estado 3-D Secure.
	 *
	 * @param array $payload Cuerpo.
	 * @return WC_PowerTranz_Response
	 */
	public function risk_mgmt( $payload ) {
		return $this->request( '/riskmgmt', $payload );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Fija TLS 1.2 como version minima de la conexion.
	 *
	 * En cURL 7.54 y posteriores, CURL_SSLVERSION_TLSv1_2 actua como minimo,
	 * de modo que TLS 1.3 sigue siendo posible.
	 *
	 * @param resource|CurlHandle $handle Recurso cURL.
	 * @param array               $args   Argumentos.
	 * @param string              $url    URL.
	 */
	public static function force_minimum_tls( $handle, $args = array(), $url = '' ) {
		if ( defined( 'CURL_SSLVERSION_TLSv1_2' ) ) {
			curl_setopt( $handle, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2 );
		}
	}

	/**
	 * Version de TLS y de la libreria SSL disponibles en el servidor.
	 *
	 * @return string
	 */
	public static function get_tls_support() {
		if ( ! function_exists( 'curl_version' ) ) {
			return __( 'cURL no esta disponible en este servidor.', 'powertranz-woocommerce' );
		}

		$info = curl_version();

		return sprintf(
			/* translators: 1: version de cURL, 2: libreria SSL */
			__( 'cURL %1$s con %2$s', 'powertranz-woocommerce' ),
			$info['version'] ?? '?',
			$info['ssl_version'] ?? '?'
		);
	}

	/**
	 * Ejecuta una peticion contra la API.
	 *
	 * @param string       $path      Ruta relativa (con barra inicial).
	 * @param array|string $body      Cuerpo. Si es cadena y $raw_string es true se envia como cadena JSON.
	 * @param bool         $raw_string Enviar $body como cadena JSON literal.
	 * @return WC_PowerTranz_Response
	 */
	protected function request( $path, $body, $raw_string = false ) {
		$url = $this->get_base_url() . $path;

		if ( ! $this->has_credentials() ) {
			$error = new WP_Error(
				'powertranz_missing_credentials',
				__( 'Faltan las credenciales de PowerTranz para el entorno seleccionado.', 'powertranz-woocommerce' )
			);
			WC_PowerTranz_Logger::log( 'Credenciales de PowerTranz incompletas. Peticion abortada: ' . $path, 'error' );
			return new WC_PowerTranz_Response( null, 0, $error );
		}

		$json = $raw_string ? wp_json_encode( (string) $body ) : wp_json_encode( $body );

		$args = array(
			'method'      => 'POST',
			'timeout'     => $this->timeout,
			'redirection' => 0,
			'httpversion' => '1.1',
			'sslverify'   => true,
			'headers'     => array(
				'Content-Type'                => 'application/json',
				'Accept'                      => 'application/json',
				'PowerTranz-PowerTranzId'     => $this->merchant_id,
				'PowerTranz-PowerTranzPassword' => $this->merchant_password,
			),
			'body'        => $json,
			'user-agent'  => 'PowerTranz-WooCommerce/' . WC_POWERTRANZ_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
		);

		if ( '' !== $this->gateway_key ) {
			$args['headers']['PowerTranz-GatewayKey'] = $this->gateway_key;
		}

		/**
		 * Permite modificar los argumentos de la peticion HTTP.
		 *
		 * @param array  $args Argumentos de wp_remote_post.
		 * @param string $path Ruta.
		 */
		$args = apply_filters( 'powertranz_api_request_args', $args, $path );

		WC_PowerTranz_Logger::debug(
			'Peticion -> ' . $url,
			array(
				'entorno' => $this->environment,
				'cuerpo'  => $raw_string ? WC_PowerTranz_Logger::truncate( (string) $body ) : $body,
			)
		);

		// FAC exige TLS 1.2 como minimo en las conexiones con la pasarela.
		add_action( 'http_api_curl', array( __CLASS__, 'force_minimum_tls' ), 10, 3 );

		$start    = microtime( true );
		$response = wp_remote_post( $url, $args );
		$ms       = (int) round( ( microtime( true ) - $start ) * 1000 );

		remove_action( 'http_api_curl', array( __CLASS__, 'force_minimum_tls' ), 10 );

		if ( is_wp_error( $response ) ) {
			WC_PowerTranz_Logger::log(
				sprintf( 'Fallo de comunicacion con %s tras %d ms: %s', $path, $ms, $response->get_error_message() ),
				'error'
			);
			return new WC_PowerTranz_Response( null, 0, $response );
		}

		$code      = (int) wp_remote_retrieve_response_code( $response );
		$raw_body  = (string) wp_remote_retrieve_body( $response );
		$decoded   = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			// Algunas respuestas de error llegan como texto plano o XML.
			$decoded = null;
		}

		WC_PowerTranz_Logger::debug(
			'Respuesta <- ' . $path . ' HTTP ' . $code . ' (' . $ms . ' ms)',
			is_array( $decoded ) ? $decoded : array( 'cuerpo_crudo' => WC_PowerTranz_Logger::truncate( $raw_body, 500 ) )
		);

		$wp_error = null;
		if ( null === $decoded && $code >= 400 ) {
			$wp_error = new WP_Error(
				'powertranz_http_error',
				sprintf(
					/* translators: %d: codigo HTTP */
					__( 'La pasarela devolvio un error HTTP %d.', 'powertranz-woocommerce' ),
					$code
				)
			);
		}

		return new WC_PowerTranz_Response( $decoded, $code, $wp_error, $raw_body );
	}
}
