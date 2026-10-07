<?php
/**
 * Registro en los logs de WooCommerce con redaccion de datos sensibles.
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PowerTranz_Logger
 */
class WC_PowerTranz_Logger {

	const SOURCE = 'powertranz';

	/**
	 * Instancia de WC_Logger.
	 *
	 * @var WC_Logger_Interface|null
	 */
	protected static $logger = null;

	/**
	 * Claves cuyo valor nunca debe escribirse tal cual.
	 *
	 * @var array
	 */
	protected static $sensitive = array(
		'cardpan'                 => 'pan',
		'pan'                     => 'pan',
		'cardnumber'              => 'pan',
		'number'                  => 'pan',
		'cardcvv'                 => 'mask',
		'cardcvv2'                => 'mask',
		'cvv'                     => 'mask',
		'cvc'                     => 'mask',
		'cvv2'                    => 'mask',
		'password'                => 'mask',
		'powertranzpassword'      => 'mask',
		'powertranz-powertranzpassword' => 'mask',
		'encrypteddata'           => 'truncate',
		'decrypteddata'           => 'truncate',
		'paymentdata'             => 'truncate',
		'data'                    => 'truncate',
		'signature'               => 'truncate',
		'ephemeralpublickey'      => 'truncate',
		'spitoken'                => 'truncate',
		'redirectdata'            => 'truncate',
		'cavv'                    => 'truncate',
		'certificate'             => 'truncate',
		'privatekey'              => 'mask',
	);

	/**
	 * Devuelve el logger de WooCommerce.
	 *
	 * @return WC_Logger_Interface
	 */
	protected static function logger() {
		if ( null === self::$logger ) {
			self::$logger = wc_get_logger();
		}
		return self::$logger;
	}

	/**
	 * Escribe una linea en el log.
	 *
	 * @param string $message Mensaje.
	 * @param string $level   Nivel (emergency|alert|critical|error|warning|notice|info|debug).
	 * @param array  $context Datos adicionales (se redactan).
	 */
	public static function log( $message, $level = 'info', $context = array() ) {
		$logger = self::logger();
		if ( ! $logger ) {
			return;
		}

		if ( ! empty( $context ) ) {
			$message .= "\n" . wp_json_encode( self::redact( $context ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		$logger->log( $level, $message, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Log de nivel debug: solo se escribe si el modo depuracion esta activo.
	 *
	 * @param string $message Mensaje.
	 * @param array  $context Datos.
	 */
	public static function debug( $message, $context = array() ) {
		if ( ! self::is_debug_enabled() ) {
			return;
		}
		self::log( $message, 'debug', $context );
	}

	/**
	 * Log de una transaccion. Siempre se escribe.
	 *
	 * @param WC_Order|null $order   Pedido.
	 * @param string        $step    Paso ejecutado.
	 * @param string        $summary Resumen legible.
	 * @param array         $context Datos.
	 * @param string        $level   Nivel.
	 */
	public static function transaction( $order, $step, $summary, $context = array(), $level = 'info' ) {
		$prefix = '[' . $step . ']';
		if ( $order instanceof WC_Order ) {
			$prefix .= ' pedido #' . $order->get_order_number();
		}
		self::log( trim( $prefix . ' ' . $summary ), $level, $context );
	}

	/**
	 * Indica si el modo depuracion esta activo en cualquiera de las pasarelas.
	 *
	 * @return bool
	 */
	public static function is_debug_enabled() {
		foreach ( array( 'woocommerce_powertranz_cc_settings', 'woocommerce_powertranz_applepay_settings' ) as $option ) {
			$settings = get_option( $option, array() );
			if ( is_array( $settings ) && isset( $settings['debug'] ) && 'yes' === $settings['debug'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Redacta recursivamente datos sensibles.
	 *
	 * @param mixed $data Datos.
	 * @return mixed
	 */
	public static function redact( $data ) {
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}

		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $value ) {
				$rule = is_string( $key ) ? ( self::$sensitive[ strtolower( $key ) ] ?? '' ) : '';

				if ( '' === $rule ) {
					$out[ $key ] = self::redact( $value );
					continue;
				}

				if ( is_array( $value ) ) {
					$out[ $key ] = '[redactado]';
					continue;
				}

				switch ( $rule ) {
					case 'pan':
						$out[ $key ] = self::mask_pan( (string) $value );
						break;
					case 'truncate':
						$out[ $key ] = self::truncate( (string) $value );
						break;
					default:
						$out[ $key ] = '[redactado]';
						break;
				}
			}
			return $out;
		}

		if ( is_string( $data ) ) {
			return self::scrub_string( $data );
		}

		return $data;
	}

	/**
	 * Enmascara un PAN dejando primeros 6 y ultimos 4.
	 *
	 * @param string $pan Numero.
	 * @return string
	 */
	public static function mask_pan( $pan ) {
		$digits = preg_replace( '/\D/', '', $pan );
		$len    = strlen( $digits );
		if ( $len < 12 ) {
			return str_repeat( '*', max( 0, $len ) );
		}
		return substr( $digits, 0, 6 ) . str_repeat( '*', $len - 10 ) . substr( $digits, -4 );
	}

	/**
	 * Corta una cadena larga.
	 *
	 * @param string $value  Valor.
	 * @param int    $length Longitud a conservar.
	 * @return string
	 */
	public static function truncate( $value, $length = 24 ) {
		if ( strlen( $value ) <= $length ) {
			return $value;
		}
		return substr( $value, 0, $length ) . '...[' . strlen( $value ) . ' bytes]';
	}

	/**
	 * Elimina de una cadena libre cualquier secuencia que parezca un PAN.
	 *
	 * @param string $value Valor.
	 * @return string
	 */
	protected static function scrub_string( $value ) {
		if ( strlen( $value ) > 4000 ) {
			$value = self::truncate( $value, 4000 );
		}
		return preg_replace_callback(
			'/\b(?:\d[ -]?){12,19}\b/',
			static function ( $matches ) {
				return self::mask_pan( $matches[0] );
			},
			$value
		);
	}
}
