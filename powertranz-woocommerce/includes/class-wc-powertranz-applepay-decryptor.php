<?php
/**
 * Descifrado del token de pago de Apple Pay.
 *
 * En el metodo directo de integracion, PowerTranz espera recibir el objeto de
 * pago ya descifrado, por lo que el descifrado ocurre en el servidor del
 * comercio con la clave privada del certificado de procesamiento de cobros
 * (curva eliptica prime256v1 / NIST P-256).
 *
 * Referencia: Apple Pay Payment Token Format Reference (version EC_v1).
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PowerTranz_ApplePay_Decryptor
 */
class WC_PowerTranz_ApplePay_Decryptor {

	/**
	 * Identificador de algoritmo del KDF (NIST SP 800-56A, paso unico).
	 */
	const KDF_ALGORITHM = "\x0did-aes256-GCM";

	/**
	 * PartyUInfo del KDF.
	 */
	const KDF_PARTY_U = 'Apple';

	/**
	 * Ruta del certificado de procesamiento de cobros (PEM) o del .p12.
	 *
	 * @var string
	 */
	protected $certificate_path;

	/**
	 * Ruta de la clave privada (PEM). Vacia si se usa un .p12.
	 *
	 * @var string
	 */
	protected $private_key_path;

	/**
	 * Contrasena de la clave privada o del .p12.
	 *
	 * @var string
	 */
	protected $passphrase;

	/**
	 * Identificador de comercio de Apple (merchant.com.ejemplo).
	 *
	 * @var string
	 */
	protected $merchant_identifier;

	/**
	 * Constructor.
	 *
	 * @param string $certificate_path    Certificado PEM o PKCS#12.
	 * @param string $private_key_path    Clave privada PEM (vacia con PKCS#12).
	 * @param string $passphrase          Contrasena.
	 * @param string $merchant_identifier Identificador de comercio Apple.
	 */
	public function __construct( $certificate_path, $private_key_path, $passphrase, $merchant_identifier ) {
		$this->certificate_path    = (string) $certificate_path;
		$this->private_key_path    = (string) $private_key_path;
		$this->passphrase          = (string) $passphrase;
		$this->merchant_identifier = (string) $merchant_identifier;
	}

	/**
	 * Indica si la configuracion permite descifrar.
	 *
	 * @return true|WP_Error
	 */
	public function check_configuration() {
		if ( ! function_exists( 'openssl_pkey_derive' ) ) {
			return new WP_Error(
				'powertranz_no_openssl_derive',
				__( 'La extension OpenSSL de PHP no expone openssl_pkey_derive(), necesaria para descifrar los tokens de Apple Pay. Requiere PHP 7.3 o superior con OpenSSL.', 'powertranz-woocommerce' )
			);
		}
		if ( '' === $this->certificate_path || ! is_readable( $this->certificate_path ) ) {
			return new WP_Error(
				'powertranz_no_certificate',
				__( 'No se puede leer el certificado de procesamiento de cobros de Apple Pay en la ruta configurada.', 'powertranz-woocommerce' )
			);
		}
		if ( '' !== $this->private_key_path && ! is_readable( $this->private_key_path ) ) {
			return new WP_Error(
				'powertranz_no_private_key',
				__( 'No se puede leer la clave privada del certificado de procesamiento de cobros de Apple Pay.', 'powertranz-woocommerce' )
			);
		}
		if ( '' === $this->merchant_identifier ) {
			return new WP_Error(
				'powertranz_no_merchant_identifier',
				__( 'Falta el identificador de comercio de Apple (merchant ID).', 'powertranz-woocommerce' )
			);
		}

		$material = $this->load_key_material();
		if ( is_wp_error( $material ) ) {
			return $material;
		}

		return true;
	}

	/**
	 * Carga el certificado y la clave privada.
	 *
	 * @return array|WP_Error {certificate, private_key}
	 */
	protected function load_key_material() {
		$contents = file_get_contents( $this->certificate_path );

		if ( false === $contents ) {
			return new WP_Error( 'powertranz_certificate_unreadable', __( 'No se pudo leer el certificado de procesamiento de cobros.', 'powertranz-woocommerce' ) );
		}

		// PKCS#12: certificado y clave en un solo archivo.
		if ( false === strpos( $contents, '-----BEGIN' ) ) {
			$bundle = array();
			if ( ! openssl_pkcs12_read( $contents, $bundle, $this->passphrase ) ) {
				return new WP_Error(
					'powertranz_pkcs12_error',
					__( 'No se pudo abrir el archivo PKCS#12 de Apple Pay. Verifique la contrasena.', 'powertranz-woocommerce' )
				);
			}
			return array(
				'certificate' => $bundle['cert'] ?? '',
				'private_key' => $bundle['pkey'] ?? '',
			);
		}

		$private_key = $contents;
		if ( '' !== $this->private_key_path ) {
			$key_contents = file_get_contents( $this->private_key_path );
			if ( false === $key_contents ) {
				return new WP_Error( 'powertranz_key_unreadable', __( 'No se pudo leer la clave privada de Apple Pay.', 'powertranz-woocommerce' ) );
			}
			$private_key = $key_contents;
		}

		return array(
			'certificate' => $contents,
			'private_key' => $private_key,
		);
	}

	/**
	 * Descifra el objeto paymentData de un token de Apple Pay.
	 *
	 * @param array $payment_data Objeto token.paymentData.
	 * @return array|WP_Error Datos descifrados.
	 */
	public function decrypt( $payment_data ) {
		if ( ! is_array( $payment_data ) ) {
			return new WP_Error( 'powertranz_applepay_bad_token', __( 'El token de Apple Pay no tiene el formato esperado.', 'powertranz-woocommerce' ) );
		}

		$version = strtoupper( (string) ( $payment_data['version'] ?? '' ) );

		if ( 'EC_V1' !== $version ) {
			return new WP_Error(
				'powertranz_applepay_version',
				sprintf(
					/* translators: %s: version del token */
					__( 'Version de token de Apple Pay no soportada: %s. Este modulo descifra tokens EC_v1.', 'powertranz-woocommerce' ),
					$version ? $version : '(vacia)'
				)
			);
		}

		$data      = (string) ( $payment_data['data'] ?? '' );
		$header    = isset( $payment_data['header'] ) && is_array( $payment_data['header'] ) ? $payment_data['header'] : array();
		$ephemeral = (string) ( $header['ephemeralPublicKey'] ?? '' );

		if ( '' === $data || '' === $ephemeral ) {
			return new WP_Error( 'powertranz_applepay_bad_token', __( 'El token de Apple Pay esta incompleto.', 'powertranz-woocommerce' ) );
		}

		$material = $this->load_key_material();
		if ( is_wp_error( $material ) ) {
			return $material;
		}

		$private_key = openssl_pkey_get_private( $material['private_key'], $this->passphrase );
		if ( ! $private_key ) {
			return new WP_Error(
				'powertranz_applepay_key',
				__( 'No se pudo cargar la clave privada del certificado de procesamiento de cobros de Apple Pay.', 'powertranz-woocommerce' )
			);
		}

		// El token debe haber sido cifrado con nuestra clave publica.
		$hash_check = $this->verify_public_key_hash( $material['certificate'], (string) ( $header['publicKeyHash'] ?? '' ) );
		if ( is_wp_error( $hash_check ) ) {
			return $hash_check;
		}

		$ephemeral_key = $this->load_ephemeral_key( $ephemeral );

		if ( ! $ephemeral_key ) {
			return new WP_Error( 'powertranz_applepay_ephemeral', __( 'La clave publica efimera del token de Apple Pay no es valida.', 'powertranz-woocommerce' ) );
		}

		$shared_secret = openssl_pkey_derive( $ephemeral_key, $private_key );
		if ( ! $shared_secret ) {
			return new WP_Error(
				'powertranz_applepay_ecdh',
				__( 'Fallo el acuerdo de claves ECDH con el token de Apple Pay. Compruebe que el certificado configurado es el de procesamiento de cobros.', 'powertranz-woocommerce' )
			);
		}

		$symmetric_key = $this->derive_symmetric_key( $shared_secret );

		$ciphertext = base64_decode( $data, true );
		if ( false === $ciphertext || strlen( $ciphertext ) <= 16 ) {
			return new WP_Error( 'powertranz_applepay_bad_data', __( 'Los datos cifrados del token de Apple Pay no son validos.', 'powertranz-woocommerce' ) );
		}

		$tag        = substr( $ciphertext, -16 );
		$ciphertext = substr( $ciphertext, 0, -16 );

		$plaintext = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			$symmetric_key,
			OPENSSL_RAW_DATA,
			str_repeat( "\0", 16 ),
			$tag
		);

		if ( false === $plaintext ) {
			return new WP_Error(
				'powertranz_applepay_decrypt',
				__( 'No se pudo descifrar el token de Apple Pay (fallo la verificacion AES-GCM).', 'powertranz-woocommerce' )
			);
		}

		$decoded = json_decode( $plaintext, true );

		// El texto en claro puede llevar relleno al final.
		if ( ! is_array( $decoded ) ) {
			$trimmed = rtrim( $plaintext, "\x00..\x20" );
			$decoded = json_decode( $trimmed, true );
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'powertranz_applepay_json', __( 'El contenido descifrado del token de Apple Pay no es un JSON valido.', 'powertranz-woocommerce' ) );
		}

		return $decoded;
	}

	/**
	 * Carga la clave publica efimera del token.
	 *
	 * Normalmente llega como SubjectPublicKeyInfo en DER, pero algunas
	 * versiones entregan solo el punto de curva sin comprimir (65 bytes que
	 * empiezan por 0x04). En ese caso se le anade la cabecera ASN.1 de
	 * prime256v1 para poder cargarla con OpenSSL.
	 *
	 * @param string $ephemeral_base64 Clave en base64.
	 * @return resource|OpenSSLAsymmetricKey|false
	 */
	protected function load_ephemeral_key( $ephemeral_base64 ) {
		$der = base64_decode( $ephemeral_base64, true );

		if ( false === $der || '' === $der ) {
			return false;
		}

		$to_pem = static function ( $binary ) {
			return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $binary ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		};

		$key = openssl_pkey_get_public( $to_pem( $der ) );

		if ( $key ) {
			return $key;
		}

		// Punto de curva sin comprimir: se envuelve en un SPKI de prime256v1.
		if ( 65 === strlen( $der ) && "\x04" === $der[0] ) {
			// Cabecera: SEQUENCE { SEQUENCE { id-ecPublicKey, prime256v1 }, BIT STRING }.
			$header = hex2bin( '3059301306072A8648CE3D020106082A8648CE3D030107034200' );
			$key    = openssl_pkey_get_public( $to_pem( $header . $der ) );

			if ( $key ) {
				WC_PowerTranz_Logger::debug( 'Apple Pay: la clave efimera llego como punto de curva sin comprimir; se envolvio en un SPKI.' );
				return $key;
			}
		}

		// Se vacia la pila de errores de OpenSSL para no contaminar llamadas posteriores.
		while ( openssl_error_string() ) {
			continue;
		}

		return false;
	}

	/**
	 * Deriva la clave simetrica AES-256 con el KDF de un paso de NIST SP 800-56A.
	 *
	 * @param string $shared_secret Secreto compartido ECDH.
	 * @return string Clave de 32 bytes.
	 */
	protected function derive_symmetric_key( $shared_secret ) {
		// El identificador de comercio participa como SHA-256 de su cadena.
		$party_v = hash( 'sha256', $this->merchant_identifier, true );

		$kdf_info = self::KDF_ALGORITHM . self::KDF_PARTY_U . $party_v;

		return hash( 'sha256', "\x00\x00\x00\x01" . $shared_secret . $kdf_info, true );
	}

	/**
	 * Comprueba que publicKeyHash corresponde a nuestro certificado.
	 *
	 * @param string $certificate      Certificado PEM.
	 * @param string $public_key_hash  Hash en base64 enviado por Apple.
	 * @return true|WP_Error
	 */
	protected function verify_public_key_hash( $certificate, $public_key_hash ) {
		if ( '' === $public_key_hash || '' === $certificate ) {
			return true;
		}

		$resource = openssl_pkey_get_public( $certificate );
		if ( ! $resource ) {
			return true;
		}

		$details = openssl_pkey_get_details( $resource );
		if ( empty( $details['key'] ) ) {
			return true;
		}

		// SHA-256 sobre la clave publica en formato DER (SubjectPublicKeyInfo).
		$pem = $details['key'];
		$der = base64_decode( preg_replace( '/-----[^-]+-----|\s+/', '', $pem ), true );

		if ( false === $der ) {
			return true;
		}

		$expected = base64_encode( hash( 'sha256', $der, true ) );

		if ( ! hash_equals( $expected, $public_key_hash ) ) {
			return new WP_Error(
				'powertranz_applepay_key_mismatch',
				__( 'El token de Apple Pay fue cifrado para otro certificado. Verifique que el certificado de procesamiento de cobros configurado es el vinculado a su merchant ID.', 'powertranz-woocommerce' )
			);
		}

		return true;
	}
}
