<?php
/**
 * Envoltorio de una respuesta de la API PowerTranz.
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PowerTranz_Response
 */
class WC_PowerTranz_Response {

	/**
	 * Codigos ISO que indican que hay que redirigir al flujo 3-D Secure.
	 *
	 * @var array
	 */
	const REDIRECT_CODES = array( 'SP4', 'SP5', 'HP0', 'SPA' );

	/**
	 * Cuerpo decodificado.
	 *
	 * @var array
	 */
	protected $data = array();

	/**
	 * Codigo HTTP.
	 *
	 * @var int
	 */
	protected $http_code = 0;

	/**
	 * Error de transporte.
	 *
	 * @var WP_Error|null
	 */
	protected $wp_error = null;

	/**
	 * Cuerpo crudo.
	 *
	 * @var string
	 */
	protected $raw_body = '';

	/**
	 * Constructor.
	 *
	 * @param array|null    $data      Cuerpo decodificado.
	 * @param int           $http_code Codigo HTTP.
	 * @param WP_Error|null $wp_error  Error de transporte.
	 * @param string        $raw_body  Cuerpo crudo.
	 */
	public function __construct( $data, $http_code = 0, $wp_error = null, $raw_body = '' ) {
		$this->data      = is_array( $data ) ? $data : array();
		$this->http_code = (int) $http_code;
		$this->wp_error  = $wp_error;
		$this->raw_body  = (string) $raw_body;
	}

	/**
	 * Lee un campo de primer nivel sin distinguir mayusculas.
	 *
	 * @param string $key     Clave.
	 * @param mixed  $default Valor por defecto.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		if ( isset( $this->data[ $key ] ) ) {
			return $this->data[ $key ];
		}
		foreach ( $this->data as $k => $v ) {
			if ( is_string( $k ) && 0 === strcasecmp( $k, $key ) ) {
				return $v;
			}
		}
		return $default;
	}

	/**
	 * Datos completos.
	 *
	 * @return array
	 */
	public function to_array() {
		return $this->data;
	}

	/**
	 * Cuerpo crudo.
	 *
	 * @return string
	 */
	public function get_raw_body() {
		return $this->raw_body;
	}

	/**
	 * Codigo HTTP.
	 *
	 * @return int
	 */
	public function get_http_code() {
		return $this->http_code;
	}

	/**
	 * Indica si hubo un fallo de transporte (no hubo respuesta de la pasarela).
	 *
	 * @return bool
	 */
	public function is_transport_error() {
		return is_wp_error( $this->wp_error );
	}

	/**
	 * Error de transporte.
	 *
	 * @return WP_Error|null
	 */
	public function get_wp_error() {
		return $this->wp_error;
	}

	/**
	 * Codigo ISO de respuesta.
	 *
	 * @return string
	 */
	public function get_iso_code() {
		return strtoupper( (string) $this->get( 'IsoResponseCode', '' ) );
	}

	/**
	 * Mensaje devuelto por la pasarela.
	 *
	 * @return string
	 */
	public function get_response_message() {
		return (string) $this->get( 'ResponseMessage', '' );
	}

	/**
	 * Indica si la transaccion fue aprobada.
	 *
	 * @return bool
	 */
	public function is_approved() {
		if ( $this->is_transport_error() ) {
			return false;
		}
		$approved = $this->get( 'Approved', null );
		$iso      = $this->get_iso_code();

		if ( '00' === $iso ) {
			return true;
		}
		// Algunos endpoints (Void, Capture) devuelven Approved sin IsoResponseCode.
		if ( true === $approved && '' === $iso ) {
			return true;
		}
		return false;
	}

	/**
	 * Indica si hay que renderizar RedirectData para completar 3-D Secure.
	 *
	 * @return bool
	 */
	public function requires_redirect() {
		if ( '' === $this->get_redirect_data() ) {
			return false;
		}
		$iso = $this->get_iso_code();
		if ( '' === $iso ) {
			return true;
		}
		return in_array( $iso, self::REDIRECT_CODES, true );
	}

	/**
	 * HTML/JS que debe renderizarse en el navegador.
	 *
	 * @return string
	 */
	public function get_redirect_data() {
		return (string) $this->get( 'RedirectData', '' );
	}

	/**
	 * Token SPI.
	 *
	 * @return string
	 */
	public function get_spi_token() {
		return (string) $this->get( 'SpiToken', '' );
	}

	/**
	 * Identificador de la transaccion.
	 *
	 * @return string
	 */
	public function get_transaction_id() {
		return (string) $this->get( 'TransactionIdentifier', '' );
	}

	/**
	 * Identificador de la transaccion original (capturas, anulaciones, reembolsos).
	 *
	 * @return string
	 */
	public function get_original_transaction_id() {
		$value = $this->get( 'OriginalTrxnIdentifier', '' );
		if ( '' === $value ) {
			$value = $this->get( 'OriginalTransactionIdentifier', '' );
		}
		return (string) $value;
	}

	/**
	 * Identificador de pedido enviado.
	 *
	 * @return string
	 */
	public function get_order_identifier() {
		return (string) $this->get( 'OrderIdentifier', '' );
	}

	/**
	 * Codigo de autorizacion.
	 *
	 * @return string
	 */
	public function get_auth_code() {
		return (string) $this->get( 'AuthorizationCode', '' );
	}

	/**
	 * Numero de referencia de recuperacion.
	 *
	 * @return string
	 */
	public function get_rrn() {
		return (string) $this->get( 'RRN', '' );
	}

	/**
	 * Marca de la tarjeta informada por la pasarela.
	 *
	 * @return string
	 */
	public function get_card_brand() {
		return (string) $this->get( 'CardBrand', '' );
	}

	/**
	 * Token del PAN (si la cuenta tiene tokenizacion activa).
	 *
	 * @return string
	 */
	public function get_pan_token() {
		return (string) $this->get( 'PanToken', '' );
	}

	/**
	 * Importe informado.
	 *
	 * @return float
	 */
	public function get_amount() {
		return (float) $this->get( 'TotalAmount', 0 );
	}

	/**
	 * Bloque RiskManagement.
	 *
	 * @return array
	 */
	public function get_risk_management() {
		$risk = $this->get( 'RiskManagement', array() );
		return is_array( $risk ) ? $risk : array();
	}

	/**
	 * Bloque RiskManagement.ThreeDSecure normalizado.
	 *
	 * @return array
	 */
	public function get_three_ds() {
		$risk = $this->get_risk_management();
		$tds  = array();

		foreach ( $risk as $key => $value ) {
			if ( is_string( $key ) && 0 === strcasecmp( $key, 'ThreeDSecure' ) && is_array( $value ) ) {
				$tds = $value;
				break;
			}
		}

		if ( empty( $tds ) ) {
			$direct = $this->get( 'ThreeDSecure', array() );
			if ( is_array( $direct ) ) {
				$tds = $direct;
			}
		}

		$read = static function ( $array, $keys, $default = '' ) {
			foreach ( $keys as $needle ) {
				foreach ( $array as $k => $v ) {
					if ( is_string( $k ) && 0 === strcasecmp( $k, $needle ) && '' !== $v && null !== $v ) {
						return $v;
					}
				}
			}
			return $default;
		};

		return array(
			'status'     => strtoupper( (string) $read( $tds, array( 'AuthenticationStatus', 'Status' ) ) ),
			'eci'        => (string) $read( $tds, array( 'Eci', 'ECI', 'EciIndicator' ) ),
			'version'    => (string) $read( $tds, array( 'ProtocolVersion', 'ThreeDsVersion', 'Version' ) ),
			'ds_trans_id' => (string) $read( $tds, array( 'DsTransId', 'DsTransID', 'DirectoryServerTransactionId' ) ),
			'xid'        => (string) $read( $tds, array( 'Xid', 'XID' ) ),
			'enrolled'   => (string) $read( $tds, array( 'Enrolled' ) ),
			'cavv'       => '' !== (string) $read( $tds, array( 'Cavv', 'CAVV' ) ) ? 'yes' : 'no',
			'response_code' => (string) $read( $tds, array( 'ResponseCode', 'ThreeDsResponseCode' ) ),
			'raw'        => is_array( $tds ) ? $tds : array(),
		);
	}

	/**
	 * Resultado de AVS.
	 *
	 * @return string
	 */
	public function get_avs_response() {
		$risk = $this->get_risk_management();
		foreach ( $risk as $key => $value ) {
			if ( is_string( $key ) && 0 === strcasecmp( $key, 'AvsResponseCode' ) ) {
				return (string) $value;
			}
		}
		return '';
	}

	/**
	 * Resultado de CVV.
	 *
	 * @return string
	 */
	public function get_cvv_response() {
		$risk = $this->get_risk_management();
		foreach ( $risk as $key => $value ) {
			if ( is_string( $key ) && 0 === strcasecmp( $key, 'CvvResponseCode' ) ) {
				return (string) $value;
			}
		}
		return '';
	}

	/**
	 * Errores devueltos por la pasarela como texto plano.
	 *
	 * @return string
	 */
	public function get_errors_text() {
		$errors = $this->get( 'Errors', array() );
		if ( empty( $errors ) ) {
			return '';
		}
		if ( is_string( $errors ) ) {
			return $errors;
		}

		$parts = array();
		foreach ( (array) $errors as $error ) {
			if ( is_string( $error ) ) {
				$parts[] = $error;
				continue;
			}
			if ( is_array( $error ) ) {
				$code    = $error['Code'] ?? ( $error['code'] ?? '' );
				$message = $error['Message'] ?? ( $error['message'] ?? '' );
				$parts[] = trim( ( $code ? $code . ': ' : '' ) . $message );
			}
		}

		return implode( ' | ', array_filter( $parts ) );
	}

	/**
	 * Mensaje tecnico para el registro interno.
	 *
	 * @return string
	 */
	public function get_log_message() {
		if ( $this->is_transport_error() ) {
			return $this->wp_error->get_error_message();
		}

		$parts = array();
		$iso   = $this->get_iso_code();
		if ( $iso ) {
			$parts[] = 'ISO ' . $iso;
		}
		if ( $this->get_response_message() ) {
			$parts[] = $this->get_response_message();
		}
		if ( $this->get_errors_text() ) {
			$parts[] = $this->get_errors_text();
		}
		if ( empty( $parts ) ) {
			$parts[] = 'HTTP ' . $this->http_code;
		}

		return implode( ' - ', $parts );
	}

	/**
	 * Mensaje apto para mostrar al cliente (sin detalles tecnicos).
	 *
	 * @return string
	 */
	public function get_customer_message() {
		if ( $this->is_transport_error() ) {
			return __( 'No pudimos comunicarnos con la pasarela de pagos. Intente de nuevo en unos minutos.', 'powertranz-woocommerce' );
		}

		$iso = $this->get_iso_code();

		// Un codigo de etapa completada que llega por una via de fallo indica
		// una respuesta inesperada: no se muestra su texto de exito.
		if ( $iso && in_array( $iso, WC_PowerTranz_Helper::get_success_stage_codes(), true ) ) {
			return __( 'El pago no pudo completarse. Intente de nuevo o use otro metodo de pago.', 'powertranz-woocommerce' );
		}

		if ( $iso ) {
			return WC_PowerTranz_Helper::get_iso_message( $iso, $this->get_response_message() );
		}

		$errors = $this->get_errors_text();
		if ( $errors ) {
			return __( 'La transaccion fue rechazada. Verifique los datos de la tarjeta e intente de nuevo.', 'powertranz-woocommerce' );
		}

		return __( 'La transaccion no pudo ser procesada. Intente de nuevo.', 'powertranz-woocommerce' );
	}
}
