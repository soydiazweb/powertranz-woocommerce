<?php
/**
 * Utilidades: monedas ISO, marcas de tarjeta, Luhn y mensajes de respuesta.
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PowerTranz_Helper
 */
class WC_PowerTranz_Helper {

	/**
	 * Mapa ISO 4217 alfabetico -> numerico.
	 *
	 * @var array
	 */
	protected static $currencies = array(
		'USD' => '840',
		'EUR' => '978',
		'GBP' => '826',
		'CAD' => '124',
		'CRC' => '188',
		'GTQ' => '320',
		'HNL' => '340',
		'NIO' => '558',
		'PAB' => '590',
		'SVC' => '222',
		'DOP' => '214',
		'MXN' => '484',
		'COP' => '170',
		'BRL' => '986',
		'ARS' => '032',
		'CLP' => '152',
		'PEN' => '604',
		'UYU' => '858',
		'BOB' => '068',
		'PYG' => '600',
		'VES' => '928',
		'TTD' => '780',
		'JMD' => '388',
		'BBD' => '052',
		'BSD' => '044',
		'XCD' => '951',
		'KYD' => '136',
		'BMD' => '060',
		'BZD' => '084',
		'ANG' => '532',
		'AWG' => '533',
		'SRD' => '968',
		'GYD' => '328',
		'HTG' => '332',
		'CUP' => '192',
		'AUD' => '036',
		'CHF' => '756',
		'JPY' => '392',
		'CNY' => '156',
	);

	/**
	 * Monedas sin decimales.
	 *
	 * @var array
	 */
	protected static $zero_decimal = array( 'JPY', 'CLP', 'PYG', 'VND', 'KRW', 'ISK' );

	/**
	 * Patrones de deteccion de marca.
	 *
	 * @var array
	 */
	protected static $brands = array(
		'visa'       => '/^4[0-9]{6,}$/',
		'mastercard' => '/^(5[1-5][0-9]{5,}|222[1-9][0-9]{3,}|22[3-9][0-9]{4,}|2[3-6][0-9]{5,}|27[01][0-9]{4,}|2720[0-9]{3,})$/',
		'amex'       => '/^3[47][0-9]{5,}$/',
		'discover'   => '/^6(?:011|5[0-9]{2}|4[4-9][0-9]|22(?:1(?:2[6-9]|[3-9][0-9])|[2-8][0-9]{2}|9(?:[01][0-9]|2[0-5])))[0-9]{3,}$/',
		'diners'     => '/^3(?:0[0-5]|[68][0-9])[0-9]{4,}$/',
		'jcb'        => '/^(?:2131|1800|35[0-9]{3})[0-9]{3,}$/',
		'unionpay'   => '/^62[0-9]{5,}$/',
		'maestro'    => '/^(5018|5020|5038|6304|6759|676[1-3])[0-9]{4,}$/',
	);

	/**
	 * Marcas que PowerTranz puede enrutar.
	 *
	 * La documentacion es explicita: PowerTranz envia las solicitudes
	 * financieras a las redes de Visa, MasterCard, American Express, Discover y
	 * JCB. Diners Club, UnionPay y Maestro no se procesan, por lo que no se
	 * ofrecen como marcas aceptables.
	 *
	 * @return array Slug => etiqueta.
	 */
	public static function get_supported_brands() {
		$labels = self::get_brand_labels();

		$supported = array();
		foreach ( array( 'visa', 'mastercard', 'amex', 'discover', 'jcb' ) as $brand ) {
			$supported[ $brand ] = $labels[ $brand ];
		}

		/**
		 * Permite ajustar las marcas ofrecidas si su cuenta enruta otras redes.
		 *
		 * @param array $supported Slug => etiqueta.
		 */
		return apply_filters( 'powertranz_supported_brands', $supported );
	}

	/**
	 * Etiquetas de todas las marcas que el modulo sabe detectar.
	 *
	 * Incluye marcas que PowerTranz no procesa, para poder decirle al cliente
	 * exactamente que tarjeta ha introducido en lugar de un error genérico.
	 *
	 * @return array
	 */
	public static function get_brand_labels() {
		return array(
			'visa'       => 'Visa',
			'mastercard' => 'Mastercard',
			'amex'       => 'American Express',
			'discover'   => 'Discover',
			'diners'     => 'Diners Club',
			'jcb'        => 'JCB',
			'unionpay'   => 'UnionPay',
			'maestro'    => 'Maestro',
		);
	}

	/**
	 * Convierte el codigo de moneda de WooCommerce al numerico de PowerTranz.
	 *
	 * @param string $currency Codigo alfabetico.
	 * @return string
	 */
	public static function get_numeric_currency( $currency ) {
		$currency = strtoupper( $currency );
		$code     = self::$currencies[ $currency ] ?? '';

		/**
		 * Permite anadir o corregir el mapeo de monedas.
		 *
		 * @param string $code     Codigo numerico.
		 * @param string $currency Codigo alfabetico.
		 */
		return (string) apply_filters( 'powertranz_numeric_currency', $code, $currency );
	}

	/**
	 * Mapa ISO 3166-1 alpha-2 -> numerico de 3 digitos.
	 *
	 * EMV 3DS define billAddrCountry y shipAddrCountry como numericos de 3
	 * digitos; enviar el alpha-2 provoca el rechazo ISO 97 "Request failed
	 * validation" con el error 58 "Invalid 3DS field".
	 *
	 * @var array
	 */
	protected static $countries = array(
		'AD' => '020', 'AE' => '784', 'AF' => '004', 'AG' => '028', 'AI' => '660',
		'AL' => '008', 'AM' => '051', 'AO' => '024', 'AQ' => '010', 'AR' => '032',
		'AS' => '016', 'AT' => '040', 'AU' => '036', 'AW' => '533', 'AX' => '248',
		'AZ' => '031', 'BA' => '070', 'BB' => '052', 'BD' => '050', 'BE' => '056',
		'BF' => '854', 'BG' => '100', 'BH' => '048', 'BI' => '108', 'BJ' => '204',
		'BL' => '652', 'BM' => '060', 'BN' => '096', 'BO' => '068', 'BQ' => '535',
		'BR' => '076', 'BS' => '044', 'BT' => '064', 'BV' => '074', 'BW' => '072',
		'BY' => '112', 'BZ' => '084', 'CA' => '124', 'CC' => '166', 'CD' => '180',
		'CF' => '140', 'CG' => '178', 'CH' => '756', 'CI' => '384', 'CK' => '184',
		'CL' => '152', 'CM' => '120', 'CN' => '156', 'CO' => '170', 'CR' => '188',
		'CU' => '192', 'CV' => '132', 'CW' => '531', 'CX' => '162', 'CY' => '196',
		'CZ' => '203', 'DE' => '276', 'DJ' => '262', 'DK' => '208', 'DM' => '212',
		'DO' => '214', 'DZ' => '012', 'EC' => '218', 'EE' => '233', 'EG' => '818',
		'EH' => '732', 'ER' => '232', 'ES' => '724', 'ET' => '231', 'FI' => '246',
		'FJ' => '242', 'FK' => '238', 'FM' => '583', 'FO' => '234', 'FR' => '250',
		'GA' => '266', 'GB' => '826', 'GD' => '308', 'GE' => '268', 'GF' => '254',
		'GG' => '831', 'GH' => '288', 'GI' => '292', 'GL' => '304', 'GM' => '270',
		'GN' => '324', 'GP' => '312', 'GQ' => '226', 'GR' => '300', 'GS' => '239',
		'GT' => '320', 'GU' => '316', 'GW' => '624', 'GY' => '328', 'HK' => '344',
		'HM' => '334', 'HN' => '340', 'HR' => '191', 'HT' => '332', 'HU' => '348',
		'ID' => '360', 'IE' => '372', 'IL' => '376', 'IM' => '833', 'IN' => '356',
		'IO' => '086', 'IQ' => '368', 'IR' => '364', 'IS' => '352', 'IT' => '380',
		'JE' => '832', 'JM' => '388', 'JO' => '400', 'JP' => '392', 'KE' => '404',
		'KG' => '417', 'KH' => '116', 'KI' => '296', 'KM' => '174', 'KN' => '659',
		'KP' => '408', 'KR' => '410', 'KW' => '414', 'KY' => '136', 'KZ' => '398',
		'LA' => '418', 'LB' => '422', 'LC' => '662', 'LI' => '438', 'LK' => '144',
		'LR' => '430', 'LS' => '426', 'LT' => '440', 'LU' => '442', 'LV' => '428',
		'LY' => '434', 'MA' => '504', 'MC' => '492', 'MD' => '498', 'ME' => '499',
		'MF' => '663', 'MG' => '450', 'MH' => '584', 'MK' => '807', 'ML' => '466',
		'MM' => '104', 'MN' => '496', 'MO' => '446', 'MP' => '580', 'MQ' => '474',
		'MR' => '478', 'MS' => '500', 'MT' => '470', 'MU' => '480', 'MV' => '462',
		'MW' => '454', 'MX' => '484', 'MY' => '458', 'MZ' => '508', 'NA' => '516',
		'NC' => '540', 'NE' => '562', 'NF' => '574', 'NG' => '566', 'NI' => '558',
		'NL' => '528', 'NO' => '578', 'NP' => '524', 'NR' => '520', 'NU' => '570',
		'NZ' => '554', 'OM' => '512', 'PA' => '591', 'PE' => '604', 'PF' => '258',
		'PG' => '598', 'PH' => '608', 'PK' => '586', 'PL' => '616', 'PM' => '666',
		'PN' => '612', 'PR' => '630', 'PS' => '275', 'PT' => '620', 'PW' => '585',
		'PY' => '600', 'QA' => '634', 'RE' => '638', 'RO' => '642', 'RS' => '688',
		'RU' => '643', 'RW' => '646', 'SA' => '682', 'SB' => '090', 'SC' => '690',
		'SD' => '729', 'SE' => '752', 'SG' => '702', 'SH' => '654', 'SI' => '705',
		'SJ' => '744', 'SK' => '703', 'SL' => '694', 'SM' => '674', 'SN' => '686',
		'SO' => '706', 'SR' => '740', 'SS' => '728', 'ST' => '678', 'SV' => '222',
		'SX' => '534', 'SY' => '760', 'SZ' => '748', 'TC' => '796', 'TD' => '148',
		'TF' => '260', 'TG' => '768', 'TH' => '764', 'TJ' => '762', 'TK' => '772',
		'TL' => '626', 'TM' => '795', 'TN' => '788', 'TO' => '776', 'TR' => '792',
		'TT' => '780', 'TV' => '798', 'TW' => '158', 'TZ' => '834', 'UA' => '804',
		'UG' => '800', 'UM' => '581', 'US' => '840', 'UY' => '858', 'UZ' => '860',
		'VA' => '336', 'VC' => '670', 'VE' => '862', 'VG' => '092', 'VI' => '850',
		'VN' => '704', 'VU' => '548', 'WF' => '876', 'WS' => '882', 'YE' => '887',
		'YT' => '175', 'ZA' => '710', 'ZM' => '894', 'ZW' => '716',
	);

	/**
	 * Convierte el pais de WooCommerce al numerico ISO 3166-1 que exige EMV 3DS.
	 *
	 * @param string $country Codigo alpha-2 (o ya numerico).
	 * @return string Codigo numerico de 3 digitos, cadena vacia si no se reconoce.
	 */
	public static function get_numeric_country( $country ) {
		$country = strtoupper( trim( (string) $country ) );

		if ( preg_match( '/^[0-9]{3}$/', $country ) ) {
			$code = $country;
		} else {
			$code = self::$countries[ $country ] ?? '';
		}

		/**
		 * Permite anadir o corregir el mapeo de paises.
		 *
		 * @param string $code    Codigo numerico.
		 * @param string $country Codigo alpha-2.
		 */
		return (string) apply_filters( 'powertranz_numeric_country', $code, $country );
	}

	/**
	 * Formatea un importe para la API (decimal con 2 posiciones salvo monedas sin decimales).
	 *
	 * @param float|string $amount   Importe.
	 * @param string       $currency Moneda.
	 * @return float
	 */
	public static function format_amount( $amount, $currency = '' ) {
		$currency = strtoupper( $currency ? $currency : get_woocommerce_currency() );
		$decimals = in_array( $currency, self::$zero_decimal, true ) ? 0 : 2;
		return (float) number_format( (float) $amount, $decimals, '.', '' );
	}

	/**
	 * Convierte un importe a unidades minimas de su moneda.
	 *
	 * Apple Pay informa transactionAmount en unidades minimas, cuyo exponente
	 * depende de la moneda (2 para USD o CRC, 0 para CLP o PYG).
	 *
	 * @param float|string $amount   Importe.
	 * @param string       $currency Moneda.
	 * @return int
	 */
	public static function to_minor_units( $amount, $currency ) {
		$exponent = self::get_currency_exponent( $currency );
		return (int) round( (float) $amount * pow( 10, $exponent ) );
	}

	/**
	 * Numero de decimales de una moneda.
	 *
	 * @param string $currency Moneda.
	 * @return int
	 */
	public static function get_currency_exponent( $currency ) {
		$currency = strtoupper( (string) $currency );
		return in_array( $currency, self::$zero_decimal, true ) ? 0 : 2;
	}

	/**
	 * Detecta la marca de la tarjeta.
	 *
	 * @param string $pan Numero de tarjeta.
	 * @return string Slug de marca o cadena vacia.
	 */
	public static function detect_brand( $pan ) {
		$pan = preg_replace( '/\D/', '', (string) $pan );
		foreach ( self::$brands as $brand => $pattern ) {
			if ( preg_match( $pattern, $pan ) ) {
				return $brand;
			}
		}
		return '';
	}

	/**
	 * Validacion Luhn.
	 *
	 * @param string $pan Numero de tarjeta.
	 * @return bool
	 */
	public static function is_luhn_valid( $pan ) {
		$pan = preg_replace( '/\D/', '', (string) $pan );
		$len = strlen( $pan );
		if ( $len < 12 || $len > 19 ) {
			return false;
		}

		$sum = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$digit = (int) $pan[ $len - 1 - $i ];
			if ( $i % 2 ) {
				$digit *= 2;
				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}
			$sum += $digit;
		}

		return 0 === $sum % 10;
	}

	/**
	 * Comprueba que el numero tiene una longitud plausible de PAN.
	 *
	 * Se usa como validacion minima cuando no se aplica Luhn: varias tarjetas
	 * del entorno de pruebas de First Atlantic Commerce no cumplen el digito
	 * de control.
	 *
	 * @param string $pan Numero de tarjeta.
	 * @return bool
	 */
	public static function is_plausible_pan( $pan ) {
		$length = strlen( preg_replace( '/\D/', '', (string) $pan ) );
		return $length >= 12 && $length <= 19;
	}

	/**
	 * Normaliza la caducidad al formato YYMM que espera PowerTranz.
	 *
	 * @param string $month Mes (1-12).
	 * @param string $year  Anio (2 o 4 digitos).
	 * @return string Cadena YYMM o vacia si es invalida.
	 */
	public static function format_expiry( $month, $year ) {
		$month = (int) preg_replace( '/\D/', '', (string) $month );
		$year  = preg_replace( '/\D/', '', (string) $year );

		if ( $month < 1 || $month > 12 || '' === $year ) {
			return '';
		}

		if ( 4 === strlen( $year ) ) {
			$year = substr( $year, 2 );
		}
		if ( 2 !== strlen( $year ) ) {
			return '';
		}

		return sprintf( '%02d%02d', (int) $year, $month );
	}

	/**
	 * Comprueba si una caducidad YYMM ya paso.
	 *
	 * @param string $yymm Caducidad.
	 * @return bool
	 */
	public static function is_expired( $yymm ) {
		if ( 4 !== strlen( (string) $yymm ) ) {
			return true;
		}
		$year  = 2000 + (int) substr( $yymm, 0, 2 );
		$month = (int) substr( $yymm, 2, 2 );

		$now_year  = (int) gmdate( 'Y' );
		$now_month = (int) gmdate( 'n' );

		if ( $year < $now_year ) {
			return true;
		}
		if ( $year === $now_year && $month < $now_month ) {
			return true;
		}
		return false;
	}

	/**
	 * Longitud de CVV esperada por marca.
	 *
	 * @param string $brand Marca.
	 * @return int
	 */
	public static function get_cvv_length( $brand ) {
		return 'amex' === $brand ? 4 : 3;
	}

	/**
	 * Valida el codigo de seguridad.
	 *
	 * FAC exige que el CVV sea obligatorio y que no se acepten secuencias de
	 * ceros ni de nueves.
	 *
	 * @param string $cvv   Codigo introducido.
	 * @param string $brand Marca de la tarjeta.
	 * @return string Cadena vacia si es valido, o el motivo del rechazo:
	 *                'empty', 'length' o 'sequence'.
	 */
	public static function validate_cvv( $cvv, $brand ) {
		$cvv = preg_replace( '/\D/', '', (string) $cvv );

		if ( '' === $cvv ) {
			return 'empty';
		}

		if ( strlen( $cvv ) !== self::get_cvv_length( $brand ) ) {
			return 'length';
		}

		if ( preg_match( '/^0+$/', $cvv ) || preg_match( '/^9+$/', $cvv ) ) {
			return 'sequence';
		}

		return '';
	}

	/**
	 * Mensajes de codigos ISO de respuesta.
	 *
	 * @return array
	 */
	public static function get_iso_messages() {
		return array(
			// Exito / etapas completadas (ver doc "IsoResponseCode").
			'00'  => __( 'Aprobada.', 'powertranz-woocommerce' ),
			'3D0' => __( 'Autenticacion 3-D Secure completada.', 'powertranz-woocommerce' ),
			'3D1' => __( 'La tarjeta no soporta 3-D Secure 2.x; se procesa sin autenticacion.', 'powertranz-woocommerce' ),
			'HP0' => __( 'Preprocesamiento de pagina alojada completado.', 'powertranz-woocommerce' ),
			'TK0' => __( 'Tokenizacion completada.', 'powertranz-woocommerce' ),
			'SP4' => __( 'Preprocesamiento SPI completado.', 'powertranz-woocommerce' ),
			'FC0' => __( 'Verificacion de fraude completada.', 'powertranz-woocommerce' ),
			'3D4' => __( 'Se requiere autenticar al tarjetahabiente.', 'powertranz-woocommerce' ),
			'3D5' => __( 'Se requiere huella digital del dispositivo.', 'powertranz-woocommerce' ),
			'3D6' => __( 'Se requiere desafio al tarjetahabiente.', 'powertranz-woocommerce' ),

			// Rechazos y errores.
			'01'  => __( 'La transaccion requiere contactar al banco emisor.', 'powertranz-woocommerce' ),
			'02'  => __( 'La transaccion requiere contactar al banco emisor.', 'powertranz-woocommerce' ),
			'03'  => __( 'Comercio no valido. Revise la configuracion de la cuenta.', 'powertranz-woocommerce' ),
			'04'  => __( 'Tarjeta rechazada por el emisor. Contacte a su banco.', 'powertranz-woocommerce' ),
			'05'  => __( 'Transaccion no autorizada por el banco emisor.', 'powertranz-woocommerce' ),
			'06'  => __( 'Error en la transaccion. Intente de nuevo.', 'powertranz-woocommerce' ),
			'07'  => __( 'Tarjeta rechazada por el emisor. Contacte a su banco.', 'powertranz-woocommerce' ),
			'12'  => __( 'Transaccion no valida.', 'powertranz-woocommerce' ),
			'13'  => __( 'Importe no valido.', 'powertranz-woocommerce' ),
			'14'  => __( 'Numero de tarjeta no valido.', 'powertranz-woocommerce' ),
			'15'  => __( 'Emisor no reconocido.', 'powertranz-woocommerce' ),
			'19'  => __( 'Vuelva a intentar la transaccion.', 'powertranz-woocommerce' ),
			'25'  => __( 'La transaccion original no existe.', 'powertranz-woocommerce' ),
			'30'  => __( 'Error de formato en la solicitud.', 'powertranz-woocommerce' ),
			'34'  => __( 'Transaccion rechazada por sospecha de fraude.', 'powertranz-woocommerce' ),
			'3D3' => __( 'Error durante la autenticacion 3-D Secure.', 'powertranz-woocommerce' ),
			'41'  => __( 'Tarjeta reportada. Contacte a su banco.', 'powertranz-woocommerce' ),
			'43'  => __( 'Tarjeta reportada. Contacte a su banco.', 'powertranz-woocommerce' ),
			'51'  => __( 'Fondos insuficientes.', 'powertranz-woocommerce' ),
			'54'  => __( 'Tarjeta vencida.', 'powertranz-woocommerce' ),
			'55'  => __( 'PIN incorrecto.', 'powertranz-woocommerce' ),
			'57'  => __( 'Tipo o marca de tarjeta no soportada.', 'powertranz-woocommerce' ),
			'58'  => __( 'Operacion no permitida en esta terminal.', 'powertranz-woocommerce' ),
			'61'  => __( 'Excede el limite de importe permitido.', 'powertranz-woocommerce' ),
			'62'  => __( 'Tarjeta restringida.', 'powertranz-woocommerce' ),
			'63'  => __( 'Violacion de seguridad.', 'powertranz-woocommerce' ),
			'65'  => __( 'Excede el limite de frecuencia de uso.', 'powertranz-woocommerce' ),
			'75'  => __( 'Numero de intentos de PIN excedido.', 'powertranz-woocommerce' ),
			'76'  => __( 'No se encontro la transaccion original.', 'powertranz-woocommerce' ),
			'78'  => __( 'Tarjeta inactiva o no activada.', 'powertranz-woocommerce' ),
			'82'  => __( 'CVV incorrecto.', 'powertranz-woocommerce' ),
			'89'  => __( 'Credenciales de PowerTranz no validas.', 'powertranz-woocommerce' ),
			'91'  => __( 'El banco emisor no esta disponible. Intente mas tarde.', 'powertranz-woocommerce' ),
			'92'  => __( 'No se pudo enrutar la transaccion.', 'powertranz-woocommerce' ),
			'94'  => __( 'Transaccion duplicada.', 'powertranz-woocommerce' ),
			'96'  => __( 'Error del sistema. Intente mas tarde.', 'powertranz-woocommerce' ),
			'97'  => __( 'La solicitud no paso la validacion de datos.', 'powertranz-woocommerce' ),
			'98'  => __( 'Error del host procesador. Intente mas tarde.', 'powertranz-woocommerce' ),
			'99'  => __( 'Error general de la pasarela. Intente mas tarde.', 'powertranz-woocommerce' ),
			'FC3' => __( 'Error en el servicio de verificacion de fraude.', 'powertranz-woocommerce' ),
			'N7'  => __( 'CVV incorrecto.', 'powertranz-woocommerce' ),
		);
	}

	/**
	 * Codigos ISO que representan una etapa completada con exito (no un rechazo).
	 *
	 * @return array
	 */
	public static function get_success_stage_codes() {
		return array( '00', '3D0', '3D1', 'HP0', 'TK0', 'SP4', 'FC0' );
	}

	/**
	 * Mensaje asociado a un codigo ISO.
	 *
	 * @param string $code     Codigo.
	 * @param string $fallback Mensaje por defecto.
	 * @return string
	 */
	public static function get_iso_message( $code, $fallback = '' ) {
		$messages = self::get_iso_messages();
		$code     = strtoupper( (string) $code );

		if ( isset( $messages[ $code ] ) ) {
			return $messages[ $code ];
		}

		return $fallback ? $fallback : __( 'La transaccion no pudo ser procesada.', 'powertranz-woocommerce' );
	}

	/**
	 * Descripcion del estado de autenticacion 3-D Secure.
	 *
	 * @param string $status Estado (Y, A, N, U, R, C, D, I).
	 * @return string
	 */
	public static function get_3ds_status_label( $status ) {
		$labels = array(
			'Y' => __( 'Autenticacion exitosa (Y)', 'powertranz-woocommerce' ),
			'A' => __( 'Intento de autenticacion (A)', 'powertranz-woocommerce' ),
			'N' => __( 'Autenticacion fallida, cuenta no verificada (N)', 'powertranz-woocommerce' ),
			'U' => __( 'Autenticacion fallida por problemas tecnicos (U)', 'powertranz-woocommerce' ),
			'R' => __( 'Autenticacion rechazada por el emisor (R)', 'powertranz-woocommerce' ),
			'C' => __( 'Se requiere desafio (C)', 'powertranz-woocommerce' ),
			'D' => __( 'Desafio en curso, autenticacion desacoplada (D)', 'powertranz-woocommerce' ),
			'I' => __( 'Solo informativo (I)', 'powertranz-woocommerce' ),
		);

		$status = strtoupper( (string) $status );
		return $labels[ $status ] ?? ( $status ? $status : __( 'Sin datos', 'powertranz-woocommerce' ) );
	}

	/**
	 * Trunca una cadena respetando el limite de la API.
	 *
	 * @param string $value  Valor.
	 * @param int    $length Longitud maxima.
	 * @return string
	 */
	public static function limit( $value, $length ) {
		$value = trim( wp_strip_all_tags( (string) $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}
		return substr( $value, 0, $length );
	}

	/**
	 * IP del cliente.
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		return WC_Geolocation::get_ip_address();
	}

	/**
	 * Sanea texto para los campos de una solicitud 3-D Secure.
	 *
	 * El protocolo EMV 3DS usa el juego de caracteres ISO 8859: tildes, enies y
	 * dieresis hacen fracasar la autenticacion. Se transliteran a ASCII y se
	 * eliminan los caracteres no imprimibles.
	 *
	 * @param string $value  Valor original.
	 * @param int    $length Longitud maxima (0 = sin limite).
	 * @return string
	 */
	public static function sanitize_3ds_text( $value, $length = 0 ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );

		$map = array(
			'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ª' => 'a',
			'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A',
			'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
			'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
			'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
			'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
			'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'º' => 'o',
			'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O',
			'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
			'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
			'ñ' => 'n', 'Ñ' => 'N',
			'ç' => 'c', 'Ç' => 'C',
			'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'Y',
			'ß' => 'ss', 'æ' => 'ae', 'Æ' => 'AE', 'ø' => 'o', 'Ø' => 'O',
			'–' => '-', '—' => '-',
			'’' => "'", '‘' => "'", '´' => "'", '`' => "'",
			'“' => '"', '”' => '"',
			"\xc2\xa0" => ' ',
		);

		$value = strtr( $value, $map );

		// Cualquier resto no ASCII se descarta.
		$value = preg_replace( '/[^\x20-\x7E]/', '', $value );
		$value = preg_replace( '/\s+/', ' ', (string) $value );
		$value = trim( (string) $value );

		if ( $length > 0 ) {
			$value = substr( $value, 0, $length );
		}

		return $value;
	}

	/**
	 * Normaliza un telefono al formato que aceptan los ACS: solo digitos.
	 *
	 * @param string $phone Telefono.
	 * @param int    $length Longitud maxima.
	 * @return string
	 */
	public static function sanitize_phone( $phone, $length = 20 ) {
		$phone = preg_replace( '/[^0-9]/', '', (string) $phone );
		return substr( (string) $phone, 0, $length );
	}

	/**
	 * Estatus de autenticacion que PowerTranz permite finalizar.
	 *
	 * Segun FAC: se permite completar el pago con Y, A o U. Con N o R la
	 * pasarela rechaza la finalizacion.
	 *
	 * @return array
	 */
	public static function get_completable_3ds_statuses() {
		return array( 'Y', 'A', 'U' );
	}

	/**
	 * Estatus que conservan proteccion frente a ciertos contracargos.
	 *
	 * @return array
	 */
	public static function get_protected_3ds_statuses() {
		return array( 'Y', 'A' );
	}

	/**
	 * Indica si el resultado 3-D Secure traslada la responsabilidad al emisor.
	 *
	 * FAC es explicito: el campo AuthenticationStatus tiene prioridad sobre el
	 * ECI, y la proteccion frente a contracargos existe con Y o A, y no existe
	 * con N, U o R. Por eso la decision se toma sobre el estatus y no sobre el
	 * ECI ni la presencia de CAVV.
	 *
	 * @param array $three_ds Datos 3DS normalizados.
	 * @return bool
	 */
	public static function is_liability_shifted( $three_ds ) {
		$status = strtoupper( (string) ( $three_ds['status'] ?? '' ) );

		return in_array( $status, self::get_protected_3ds_statuses(), true );
	}

	/**
	 * Indica si el ECI y el CAVV respaldan el estatus recibido.
	 *
	 * Solo se usa como diagnostico: si el estatus es Y o A pero faltan el CAVV
	 * o el ECI esperado, conviene dejarlo anotado en el pedido sin bloquear el
	 * cobro, porque puede indicar un problema de configuracion del adquirente.
	 *
	 * @param array $three_ds Datos 3DS normalizados.
	 * @return bool
	 */
	public static function has_eci_corroboration( $three_ds ) {
		$eci  = (string) ( $three_ds['eci'] ?? '' );
		$cavv = ( 'yes' === ( $three_ds['cavv'] ?? 'no' ) );

		if ( ! $cavv ) {
			return false;
		}

		// Visa y Amex: 05 y 06. Mastercard: 02 y 01.
		return in_array( ltrim( $eci, '0' ), array( '5', '6', '2', '1' ), true );
	}

	/**
	 * Descripcion del valor ECI.
	 *
	 * @param string $eci   Valor ECI.
	 * @param string $brand Marca de tarjeta.
	 * @return string
	 */
	public static function get_eci_label( $eci, $brand = '' ) {
		$eci   = (string) $eci;
		$brand = strtolower( (string) $brand );

		if ( 'mastercard' === $brand || 'maestro' === $brand ) {
			$labels = array(
				'02' => __( 'Emisor autentico al tarjetahabiente', 'powertranz-woocommerce' ),
				'01' => __( 'Autenticacion realizada por la red (emisor sin 3DS)', 'powertranz-woocommerce' ),
				'00' => __( 'Sin autenticacion', 'powertranz-woocommerce' ),
			);
		} else {
			$labels = array(
				'05' => __( 'Emisor autentico al tarjetahabiente', 'powertranz-woocommerce' ),
				'06' => __( 'Autenticacion realizada por la red (emisor sin 3DS)', 'powertranz-woocommerce' ),
				'07' => __( 'Sin autenticacion', 'powertranz-woocommerce' ),
			);
		}

		return $labels[ $eci ] ?? $eci;
	}
}
