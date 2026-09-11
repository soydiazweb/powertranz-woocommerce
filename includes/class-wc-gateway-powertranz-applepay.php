<?php
/**
 * Pasarela Apple Pay en la Web (metodo directo de integracion) sobre PowerTranz.
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Gateway_PowerTranz_ApplePay
 */
class WC_Gateway_PowerTranz_ApplePay extends WC_PowerTranz_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'powertranz_applepay';
		$this->method_title       = __( 'PowerTranz Apple Pay', 'powertranz-woocommerce' );
		$this->method_description = __( 'Apple Pay en la Web mediante el metodo directo de integracion: el servidor descifra el token de Apple con su certificado de procesamiento de cobros y lo envia a PowerTranz. Se muestra como metodo de pago independiente cuando esta activo.', 'powertranz-woocommerce' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );

		parent::__construct();

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_auto_capture' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Credenciales compartidas con la pasarela de tarjetas
	 * ------------------------------------------------------------------ */

	/**
	 * Indica si se reutilizan las credenciales de la pasarela de tarjetas.
	 *
	 * @return bool
	 */
	protected function uses_cc_credentials() {
		return 'yes' === $this->get_option( 'use_cc_credentials', 'yes' );
	}

	/**
	 * Ajustes de la pasarela de tarjetas.
	 *
	 * @return array
	 */
	protected function get_cc_settings() {
		$settings = get_option( 'woocommerce_powertranz_cc_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Entorno activo.
	 *
	 * @return string
	 */
	public function get_environment() {
		if ( $this->uses_cc_credentials() ) {
			$cc = $this->get_cc_settings();
			return 'live' === ( $cc['environment'] ?? 'test' ) ? 'live' : 'test';
		}
		return parent::get_environment();
	}

	/**
	 * Credenciales para el entorno activo.
	 *
	 * Apple Pay no usa cuotas: siempre se transacciona con las credenciales
	 * principales.
	 *
	 * @param array|null $plan Ignorado.
	 * @return array
	 */
	public function get_credentials( $plan = null ) {
		if ( ! $this->uses_cc_credentials() ) {
			return parent::get_credentials( null );
		}

		$cc  = $this->get_cc_settings();
		$env = $this->get_environment();

		return apply_filters(
			'powertranz_credentials',
			array(
				'id'       => trim( (string) ( $cc[ $env . '_powertranz_id' ] ?? '' ) ),
				'password' => trim( (string) ( $cc[ $env . '_powertranz_password' ] ?? '' ) ),
			),
			$env,
			null,
			$this
		);
	}

	/**
	 * GatewayKey heredado de la pasarela de tarjetas si corresponde.
	 *
	 * @param string $key     Clave.
	 * @param mixed  $empty   Valor por defecto.
	 * @return mixed
	 */
	public function get_option( $key, $empty = null ) {
		if ( 'gateway_key' === $key && $this->uses_cc_credentials() ) {
			$cc = $this->get_cc_settings();
			return (string) ( $cc['gateway_key'] ?? '' );
		}
		return parent::get_option( $key, $empty );
	}

	/* ---------------------------------------------------------------------
	 * Ajustes
	 * ------------------------------------------------------------------ */

	/**
	 * Campos de configuracion.
	 */
	public function init_form_fields() {
		// Solo las redes que PowerTranz enruta.
		$networks = array(
			'visa'       => 'Visa',
			'masterCard' => 'Mastercard',
			'amex'       => 'American Express',
			'discover'   => 'Discover',
			'JCB'        => 'JCB',
		);

		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Activar', 'powertranz-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Mostrar Apple Pay como metodo de pago independiente', 'powertranz-woocommerce' ),
				'default' => 'no',
			),
			'title' => array(
				'title'   => __( 'Titulo', 'powertranz-woocommerce' ),
				'type'    => 'text',
				'default' => 'Apple Pay',
			),
			'description' => array(
				'title'   => __( 'Descripcion', 'powertranz-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Pague con Apple Pay usando Touch ID o Face ID.', 'powertranz-woocommerce' ),
			),

			'powertranz_section' => array(
				'title'       => __( 'Cuenta PowerTranz', 'powertranz-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Apple Pay se autoriza contra los endpoints directos /api/sale y /api/auth, sin 3-D Secure: el criptograma del dispositivo cumple esa funcion.', 'powertranz-woocommerce' ),
			),
			'use_cc_credentials' => array(
				'title'   => __( 'Credenciales', 'powertranz-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Usar el entorno y las credenciales de la pasarela PowerTranz (tarjetas)', 'powertranz-woocommerce' ),
				'default' => 'yes',
			),
			'environment' => array(
				'title'       => __( 'Entorno propio', 'powertranz-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'test',
				'options'     => array(
					'test' => __( 'Pruebas', 'powertranz-woocommerce' ),
					'live' => __( 'Produccion', 'powertranz-woocommerce' ),
				),
				'description' => __( 'Solo se usa si la casilla anterior esta desmarcada.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'test_powertranz_id' => array(
				'title'   => __( 'PowerTranz Id (pruebas)', 'powertranz-woocommerce' ),
				'type'    => 'text',
				'default' => '',
			),
			'test_powertranz_password' => array(
				'title'   => __( 'PowerTranz Password (pruebas)', 'powertranz-woocommerce' ),
				'type'    => 'password',
				'default' => '',
			),
			'live_powertranz_id' => array(
				'title'   => __( 'PowerTranz Id (produccion)', 'powertranz-woocommerce' ),
				'type'    => 'text',
				'default' => '',
			),
			'live_powertranz_password' => array(
				'title'   => __( 'PowerTranz Password (produccion)', 'powertranz-woocommerce' ),
				'type'    => 'password',
				'default' => '',
			),
			'capture_mode' => array(
				'title'   => __( 'Modo de cobro', 'powertranz-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'sale',
				'options' => array(
					'sale' => __( 'Venta: autoriza y captura de inmediato', 'powertranz-woocommerce' ),
					'auth' => __( 'Preautorizacion: reserva fondos y captura despues', 'powertranz-woocommerce' ),
				),
			),
			'authorized_order_status' => array(
				'title'   => __( 'Estado tras preautorizar', 'powertranz-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'on-hold',
				'options' => array(
					'on-hold'    => __( 'En espera', 'powertranz-woocommerce' ),
					'processing' => __( 'Procesando', 'powertranz-woocommerce' ),
				),
			),
			'auto_capture_on_completed' => array(
				'title'   => __( 'Captura automatica', 'powertranz-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Capturar cuando el pedido pase a Completado', 'powertranz-woocommerce' ),
				'default' => 'no',
			),

			'apple_section' => array(
				'title'       => __( 'Cuenta de desarrollador Apple', 'powertranz-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Debe registrar su propio Merchant ID en Apple, verificar el dominio y generar dos certificados: identidad del comercio y procesamiento de cobros.', 'powertranz-woocommerce' ),
			),
			'merchant_identifier' => array(
				'title'       => __( 'Apple Merchant ID', 'powertranz-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'merchant.com.midominio.tienda',
				'description' => __( 'Identificador creado en la cuenta de desarrollador de Apple.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'display_name' => array(
				'title'       => __( 'Nombre a mostrar', 'powertranz-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Nombre del comercio en la hoja de pago. Si se deja vacio se usa el nombre del sitio.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'domain_name' => array(
				'title'       => __( 'Dominio verificado', 'powertranz-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Dominio registrado en el Merchant ID. Si se deja vacio se usa el del sitio.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'domain_association' => array(
				'title'       => __( 'Archivo de asociacion de dominio', 'powertranz-woocommerce' ),
				'type'        => 'textarea',
				'default'     => '',
				'css'         => 'height:110px;font-family:monospace;font-size:11px;',
				'description' => sprintf(
					/* translators: %s: URL publica del archivo */
					__( 'Pegue aqui el contenido de apple-developer-merchantid-domain-association que descargo de Apple. El modulo lo servira en %s. Si su servidor web ya sirve ese archivo, deje este campo vacio.', 'powertranz-woocommerce' ),
					'<code>' . esc_url( WC_PowerTranz_ApplePay_Service::get_domain_association_url() ) . '</code>'
				),
			),

			'certs_section' => array(
				'title'       => __( 'Certificados', 'powertranz-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Guarde los archivos fuera de la raiz publica del sitio y con permisos restringidos (por ejemplo 0400 para el usuario de PHP). Nunca dentro de wp-content/uploads: ahi son descargables por HTTP.', 'powertranz-woocommerce' ),
			),
			'decrypt_mode' => array(
				'title'       => __( 'Quien descifra el token', 'powertranz-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'gateway',
				'options'     => array(
					'gateway'  => __( 'PowerTranz / BAC (recomendado)', 'powertranz-woocommerce' ),
					'merchant' => __( 'Este servidor (requiere el certificado de procesamiento)', 'powertranz-woocommerce' ),
				),
				'description' => __( 'Depende de quien genero el CSR del certificado de procesamiento de cobros. Si lo genero BAC, la clave privada la tiene PowerTranz: elija la primera opcion y envie el token cifrado tal cual. Si genero el CSR usted, la clave privada es suya y debe descifrar aqui: elija la segunda y complete los certificados de abajo.', 'powertranz-woocommerce' ),
			),
			'identity_cert_path' => array(
				'title'       => __( 'Certificado de identidad del comercio', 'powertranz-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => '/ruta/segura/merchantIdentity.pem',
				'description' => __( 'Ruta absoluta al PEM (o .p12) usado para la peticion TLS mutua que valida la sesion con Apple.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'identity_key_path' => array(
				'title'       => __( 'Clave del certificado de identidad', 'powertranz-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => '/ruta/segura/merchantIdentity.key',
				'description' => __( 'Dejar vacio si el archivo anterior es un .p12 o ya contiene la clave.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'identity_key_password' => array(
				'title'   => __( 'Contrasena del certificado de identidad', 'powertranz-woocommerce' ),
				'type'    => 'password',
				'default' => '',
			),
			'processing_cert_path' => array(
				'title'       => __( 'Certificado de procesamiento de cobros', 'powertranz-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => '/ruta/segura/paymentProcessing.pem',
				'description' => __( 'Solo para el modo "Este servidor". Ruta absoluta al PEM (o .p12) con el que se descifra el token de Apple Pay.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'processing_key_path' => array(
				'title'       => __( 'Clave del certificado de procesamiento', 'powertranz-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => '/ruta/segura/paymentProcessing.key',
				'description' => __( 'Clave privada EC prime256v1. Dejar vacio si el archivo anterior es un .p12.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'processing_key_password' => array(
				'title'   => __( 'Contrasena del certificado de procesamiento', 'powertranz-woocommerce' ),
				'type'    => 'password',
				'default' => '',
			),

			'sheet_section' => array(
				'title' => __( 'Hoja de pago', 'powertranz-woocommerce' ),
				'type'  => 'title',
			),
			'supported_networks' => array(
				'title'   => __( 'Redes soportadas', 'powertranz-woocommerce' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'options' => $networks,
				'default' => array( 'visa', 'masterCard', 'amex' ),
			),
			'merchant_capabilities' => array(
				'title'       => __( 'Capacidades del comercio', 'powertranz-woocommerce' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'options'     => array(
					'supports3DS'        => 'supports3DS',
					'supportsCredit'     => 'supportsCredit',
					'supportsDebit'      => 'supportsDebit',
					'supportsEMV'        => 'supportsEMV',
				),
				'default'     => array( 'supports3DS' ),
				'description' => __( 'Apple exige supports3DS.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'button_style' => array(
				'title'   => __( 'Estilo del boton', 'powertranz-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'black',
				'options' => array(
					'black'          => __( 'Negro', 'powertranz-woocommerce' ),
					'white'          => __( 'Blanco', 'powertranz-woocommerce' ),
					'white-outline'  => __( 'Blanco con borde', 'powertranz-woocommerce' ),
				),
			),
			'button_type' => array(
				'title'   => __( 'Texto del boton', 'powertranz-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'buy',
				'options' => array(
					'buy'      => __( 'Comprar con Apple Pay', 'powertranz-woocommerce' ),
					'plain'    => __( 'Solo el logo', 'powertranz-woocommerce' ),
					'pay'      => __( 'Pagar con Apple Pay', 'powertranz-woocommerce' ),
					'checkout' => __( 'Finalizar con Apple Pay', 'powertranz-woocommerce' ),
					'order'    => __( 'Pedir con Apple Pay', 'powertranz-woocommerce' ),
				),
			),
			'request_billing_contact' => array(
				'title'       => __( 'Solicitar direccion de facturacion', 'powertranz-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Pedir a Apple el contacto de facturacion completo', 'powertranz-woocommerce' ),
				'default'     => 'yes',
				'description' => __( 'Mejora la verificacion AVS del adquirente.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),

			'advanced_section' => array(
				'title' => __( 'Avanzado', 'powertranz-woocommerce' ),
				'type'  => 'title',
			),
			'request_timeout' => array(
				'title'   => __( 'Timeout de la peticion (segundos)', 'powertranz-woocommerce' ),
				'type'    => 'number',
				'default' => '60',
			),
			'order_prefix' => array(
				'title'   => __( 'Prefijo de OrderIdentifier', 'powertranz-woocommerce' ),
				'type'    => 'text',
				'default' => '',
			),
			'fraud_check' => array(
				'title'   => __( 'Verificacion de fraude (Kount)', 'powertranz-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enviar FraudCheck = true', 'powertranz-woocommerce' ),
				'default' => 'no',
			),
			'debug' => array(
				'title'   => __( 'Modo depuracion', 'powertranz-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Registrar peticiones y respuestas completas', 'powertranz-woocommerce' ),
				'default' => 'no',
			),
		);

		$this->form_fields = array_merge( $this->form_fields, WC_PowerTranz_Branding::get_settings_fields() );
	}

	/**
	 * Avisos en la pantalla de ajustes.
	 */
	public function admin_options() {
		if ( 'yes' === $this->enabled ) {
			$problems = $this->get_configuration_problems();

			if ( $problems ) {
				echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Apple Pay no puede operar todavia:', 'powertranz-woocommerce' ) . '</strong></p><ul style="list-style:disc;margin-left:1.5em">';
				foreach ( $problems as $problem ) {
					echo '<li>' . esc_html( $problem ) . '</li>';
				}
				echo '</ul></div>';
			} else {
				echo '<div class="notice notice-success inline"><p>' . esc_html__( 'La configuracion de Apple Pay esta completa.', 'powertranz-woocommerce' ) . '</p></div>';
			}

			if ( $this->is_test() ) {
				echo '<div class="notice notice-info inline"><p>' . wp_kses(
					__( 'Apple Pay no se prueba con las tarjetas de prueba de PowerTranz. Necesita una cuenta <strong>Sandbox Tester</strong> de Apple, con una tarjeta de prueba de Apple anadida al Wallet, en un dispositivo Apple real con Safari. Apple no exige verificacion de dominio en su entorno sandbox.', 'powertranz-woocommerce' ),
					array( 'strong' => array() )
				) . '</p></div>';
			}

			if ( trim( (string) $this->get_option( 'domain_association', '' ) ) ) {
				echo '<div class="notice notice-info inline"><p>' . sprintf(
					/* translators: %s: URL */
					esc_html__( 'Archivo de verificacion de dominio servido en: %s', 'powertranz-woocommerce' ),
					'<code>' . esc_url( WC_PowerTranz_ApplePay_Service::get_domain_association_url() ) . '</code>'
				) . '</p></div>';
			}
		}

		parent::admin_options();
	}

	/**
	 * Problemas de configuracion detectables.
	 *
	 * @return array
	 */
	public function get_configuration_problems() {
		$problems    = array();
		$credentials = $this->get_credentials();

		if ( '' === $credentials['id'] || '' === $credentials['password'] ) {
			$problems[] = __( 'Faltan las credenciales de PowerTranz para el entorno seleccionado.', 'powertranz-woocommerce' );
		}
		if ( ! is_ssl() && 'live' === $this->get_environment() ) {
			$problems[] = __( 'Apple Pay exige que el sitio se sirva por HTTPS.', 'powertranz-woocommerce' );
		}
		if ( '' === trim( (string) $this->get_option( 'merchant_identifier', '' ) ) ) {
			$problems[] = __( 'Falta el Apple Merchant ID.', 'powertranz-woocommerce' );
		}
		if ( '' === WC_PowerTranz_Helper::get_numeric_currency( get_woocommerce_currency() ) ) {
			$problems[] = __( 'La moneda de la tienda no tiene codigo ISO numerico configurado.', 'powertranz-woocommerce' );
		}

		$identity = trim( (string) $this->get_option( 'identity_cert_path', '' ) );
		if ( '' === $identity ) {
			$problems[] = __( 'Falta la ruta del certificado de identidad del comercio.', 'powertranz-woocommerce' );
		} elseif ( ! is_readable( $identity ) ) {
			$problems[] = __( 'No se puede leer el certificado de identidad del comercio en la ruta indicada.', 'powertranz-woocommerce' );
		}

		if ( $this->decrypts_locally() ) {
			$check = $this->get_decryptor()->check_configuration();
			if ( is_wp_error( $check ) ) {
				$problems[] = $check->get_error_message();
			}
		}

		return $problems;
	}

	/**
	 * Indica quien descifra el token de Apple Pay.
	 *
	 * @return string gateway|merchant
	 */
	public function get_decrypt_mode() {
		return 'merchant' === $this->get_option( 'decrypt_mode', 'gateway' ) ? 'merchant' : 'gateway';
	}

	/**
	 * Indica si el descifrado ocurre en este servidor.
	 *
	 * @return bool
	 */
	public function decrypts_locally() {
		return 'merchant' === $this->get_decrypt_mode();
	}

	/**
	 * Instancia del descifrador de tokens.
	 *
	 * @return WC_PowerTranz_ApplePay_Decryptor
	 */
	public function get_decryptor() {
		return new WC_PowerTranz_ApplePay_Decryptor(
			trim( (string) $this->get_option( 'processing_cert_path', '' ) ),
			trim( (string) $this->get_option( 'processing_key_path', '' ) ),
			(string) $this->get_option( 'processing_key_password', '' ),
			trim( (string) $this->get_option( 'merchant_identifier', '' ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Disponibilidad y checkout
	 * ------------------------------------------------------------------ */

	/**
	 * Disponibilidad de la pasarela.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		$credentials = $this->get_credentials();
		if ( '' === $credentials['id'] || '' === $credentials['password'] ) {
			return false;
		}
		if ( '' === trim( (string) $this->get_option( 'merchant_identifier', '' ) ) ) {
			return false;
		}
		if ( '' === WC_PowerTranz_Helper::get_numeric_currency( get_woocommerce_currency() ) ) {
			return false;
		}
		// Apple Pay solo funciona sobre HTTPS. Se permite en local para pruebas.
		if ( ! is_ssl() && 'live' === $this->get_environment() ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Parametros para el JS del checkout.
	 *
	 * @return array
	 */
	public function get_frontend_params() {
		$order_id  = 0;
		$order_key = '';

		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			if ( $order ) {
				$order_key = $order->get_order_key();
			} else {
				$order_id = 0;
			}
		}

		$networks = $this->get_option( 'supported_networks', array() );
		$caps     = $this->get_option( 'merchant_capabilities', array() );

		if ( ! is_array( $networks ) || ! $networks ) {
			$networks = array( 'visa', 'masterCard', 'amex' );
		}
		if ( ! is_array( $caps ) || ! $caps ) {
			$caps = array( 'supports3DS' );
		}
		if ( ! in_array( 'supports3DS', $caps, true ) ) {
			$caps[] = 'supports3DS';
		}

		return array_merge(
			WC_PowerTranz_Branding::get_frontend_data( $this, false ),
			array(
			'gateway_id'          => $this->id,
			'ajax_url'            => admin_url( 'admin-ajax.php' ),
			'nonce'               => wp_create_nonce( 'powertranz_applepay' ),
			'merchant_identifier' => trim( (string) $this->get_option( 'merchant_identifier', '' ) ),
			'display_name'        => $this->get_option( 'display_name' ) ? $this->get_option( 'display_name' ) : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'supported_networks'  => array_values( $networks ),
			'merchant_capabilities' => array_values( $caps ),
			'button_style'        => $this->get_option( 'button_style', 'black' ),
			'button_type'         => $this->get_option( 'button_type', 'buy' ),
			'request_billing'     => 'yes' === $this->get_option( 'request_billing_contact', 'yes' ),
			'needs_shipping'      => WC()->cart ? WC()->cart->needs_shipping() : false,
			'order_id'            => $order_id,
			'order_key'           => $order_key,
			'i18n'               => array(
				'unavailable' => __( 'Apple Pay no esta disponible en este dispositivo o navegador.', 'powertranz-woocommerce' ),
				'no_card'     => __( 'No hay tarjetas configuradas en Apple Pay en este dispositivo.', 'powertranz-woocommerce' ),
				'cancelled'   => __( 'Cancelo el pago con Apple Pay.', 'powertranz-woocommerce' ),
				'error'       => __( 'No pudimos iniciar Apple Pay. Intente de nuevo o use otro metodo de pago.', 'powertranz-woocommerce' ),
				'authorizing' => __( 'Autorizando el pago...', 'powertranz-woocommerce' ),
				'authorize_first' => __( 'Pulse el boton de Apple Pay para autorizar el pago antes de continuar.', 'powertranz-woocommerce' ),
			),
			)
		);
	}

	/**
	 * Boton y campo oculto del token.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo '<p>' . wp_kses_post( $this->description ) . '</p>';
		}
		if ( $this->is_test() ) {
			echo '<p><strong>' . esc_html__( 'MODO DE PRUEBAS: no se realizaran cargos reales.', 'powertranz-woocommerce' ) . '</strong></p>';
		}
		?>
		<div class="powertranz-applepay-wrap" data-powertranz-applepay>
			<div class="powertranz-applepay-button-holder">
				<div
					id="powertranz-applepay-button"
					class="powertranz-applepay-button"
					role="button"
					tabindex="0"
					aria-label="<?php esc_attr_e( 'Pagar con Apple Pay', 'powertranz-woocommerce' ); ?>"
					data-style="<?php echo esc_attr( $this->get_option( 'button_style', 'black' ) ); ?>"
					data-type="<?php echo esc_attr( $this->get_option( 'button_type', 'buy' ) ); ?>"></div>
			</div>
			<p class="powertranz-applepay-message" aria-live="polite"></p>
			<input type="hidden" name="powertranz_applepay_token" id="powertranz_applepay_token" value="" />
		</div>
		<?php
		// Apple Pay no usa 3-D Secure: el criptograma del dispositivo cumple
		// esa funcion, por eso no se muestra ese sello.
		echo WC_PowerTranz_Branding::render_accepted_cards( $this->get_wallet_brands(), $this ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo WC_PowerTranz_Branding::render_seals( $this, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo WC_PowerTranz_Branding::render_support_contact( $this ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Marcas que se muestran junto al boton de Apple Pay.
	 *
	 * Se derivan de las redes configuradas en la hoja de pago.
	 *
	 * @return array
	 */
	protected function get_wallet_brands() {
		$map = array(
			'visa'       => 'visa',
			'masterCard' => 'mastercard',
			'amex'       => 'amex',
			'discover'   => 'discover',
			'JCB'        => 'jcb',
		);

		$networks = $this->get_option( 'supported_networks', array() );
		$networks = is_array( $networks ) ? $networks : array();

		$brands = array();
		foreach ( $networks as $network ) {
			if ( isset( $map[ $network ] ) ) {
				$brands[] = $map[ $network ];
			}
		}

		return $brands;
	}

	/* ---------------------------------------------------------------------
	 * Procesamiento del pago
	 * ------------------------------------------------------------------ */

	/**
	 * Procesa el pago con Apple Pay.
	 *
	 * @param int $order_id Id de pedido.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return array(
				'result'   => 'failure',
				'messages' => __( 'Pedido no encontrado.', 'powertranz-woocommerce' ),
			);
		}

		try {
			$payment   = $this->get_posted_apple_payment();
			$decrypted = array();

			if ( $this->decrypts_locally() ) {
				$decrypted = $this->get_decryptor()->decrypt( $payment['token']['paymentData'] );

				if ( is_wp_error( $decrypted ) ) {
					WC_PowerTranz_Logger::log( 'Apple Pay: ' . $decrypted->get_error_message(), 'error' );
					throw new Exception( esc_html__( 'No pudimos procesar el pago de Apple Pay. Intente de nuevo o use otro metodo de pago.', 'powertranz-woocommerce' ) );
				}

				$this->verify_decrypted_amount( $order, $decrypted );
			}

			$attempts = (int) $order->get_meta( '_powertranz_attempts' ) + 1;
			$order->update_meta_data( '_powertranz_attempts', $attempts );

			$transaction_id = wp_generate_uuid4();
			$mode           = $this->get_capture_mode();

			$payload                 = $this->build_base_payload( $order, $transaction_id );
			$payload['ThreeDSecure'] = false;

			/**
			 * En la llamada directa de billetera, el ejemplo de First Atlantic
			 * Commerce envia TransactionIdentifier vacio y deja que PowerTranz
			 * lo asigne; el identificador definitivo se toma de la respuesta.
			 *
			 * @param string   $transaction_id Identificador propuesto.
			 * @param WC_Order $order          Pedido.
			 */
			$payload['TransactionIdentifier'] = apply_filters( 'powertranz_applepay_transaction_identifier', '', $order );

			$apple_pay = array( 'Payment' => $payment );

			if ( ! empty( $decrypted ) ) {
				$apple_pay['DecryptedData'] = $decrypted;
			}

			$payload['Source'] = array(
				'CardPresent'     => false,
				'CardEmvFallback' => false,
				'ManualEntry'     => false,
				'Debit'           => false,
				'Contactless'     => false,
				'Wallet'          => array(
					'ApplePay' => $apple_pay,
				),
			);

			/**
			 * Permite ajustar el objeto Source de Apple Pay antes de enviarlo.
			 *
			 * @param array    $source    Objeto Source.
			 * @param array    $payment   Objeto de pago de Apple.
			 * @param array    $decrypted Datos descifrados.
			 * @param WC_Order $order     Pedido.
			 */
			$payload['Source'] = apply_filters( 'powertranz_applepay_source', $payload['Source'], $payment, $decrypted, $order );

			// Metadatos del medio de pago (nunca el PAN del dispositivo).
			$method = isset( $payment['token']['paymentMethod'] ) && is_array( $payment['token']['paymentMethod'] )
				? $payment['token']['paymentMethod']
				: array();

			$order->update_meta_data( '_powertranz_wallet', 'ApplePay' );
			$order->update_meta_data( '_powertranz_applepay_decrypt_mode', $this->get_decrypt_mode() );
			$order->update_meta_data( '_powertranz_capture_mode', $mode );
			$order->update_meta_data( '_powertranz_pending_transaction_id', $transaction_id );
			$order->update_meta_data( '_powertranz_installment_plan_id', 'single' );

			if ( ! empty( $method['displayName'] ) ) {
				$order->update_meta_data( '_powertranz_applepay_card', sanitize_text_field( (string) $method['displayName'] ) );
			}
			if ( ! empty( $method['network'] ) ) {
				$order->update_meta_data( '_powertranz_card_brand', sanitize_text_field( (string) $method['network'] ) );
			}
			if ( ! empty( $method['type'] ) ) {
				$order->update_meta_data( '_powertranz_applepay_card_type', sanitize_text_field( (string) $method['type'] ) );
			}
			if ( ! empty( $decrypted['paymentDataType'] ) ) {
				$order->update_meta_data( '_powertranz_applepay_data_type', sanitize_text_field( (string) $decrypted['paymentDataType'] ) );
			}
			if ( ! empty( $decrypted['paymentData']['eciIndicator'] ) ) {
				$order->update_meta_data( '_powertranz_3ds_eci', sanitize_text_field( (string) $decrypted['paymentData']['eciIndicator'] ) );
			}

			$order->save();

			$api      = $this->get_api();
			$step     = 'auth' === $mode ? 'applepay_auth' : 'applepay_sale';
			$response = 'auth' === $mode ? $api->auth( $payload ) : $api->sale( $payload );

			$this->record_transaction(
				$order,
				$step,
				$response,
				array(
					__( 'Billetera', 'powertranz-woocommerce' ) => 'Apple Pay' . ( ! empty( $method['displayName'] ) ? ' (' . $method['displayName'] . ')' : '' ),
					__( 'Descifrado del token', 'powertranz-woocommerce' ) => $this->decrypts_locally()
						? __( 'en este servidor', 'powertranz-woocommerce' )
						: __( 'en PowerTranz', 'powertranz-woocommerce' ),
				)
			);

			if ( $response->is_approved() ) {
				$this->complete_order( $order, $response, $mode );
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}

			$message = $response->get_customer_message();
			$this->fail_order( $order, $response );
			$this->add_declined_notice( $message );

			return array(
				'result'   => 'failure',
				'messages' => $message,
			);

		} catch ( Exception $e ) {
			$message = $e->getMessage();

			WC_PowerTranz_Logger::transaction( $order, 'applepay_validacion', $message, array(), 'warning' );
			$order->add_order_note(
				sprintf(
					/* translators: %s: motivo */
					__( 'PowerTranz Apple Pay: el pago no pudo iniciarse. %s', 'powertranz-woocommerce' ),
					$message
				)
			);

			wc_add_notice( $message, 'error' );

			return array(
				'result'   => 'failure',
				'messages' => $message,
			);
		}
	}

	/**
	 * Lee y valida el objeto de pago de Apple enviado por el navegador.
	 *
	 * @return array
	 * @throws Exception Si el token no es valido.
	 */
	protected function get_posted_apple_payment() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce valida el nonce del checkout.
		$raw = isset( $_POST['powertranz_applepay_token'] ) ? wp_unslash( $_POST['powertranz_applepay_token'] ) : '';

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			throw new Exception( esc_html__( 'No recibimos la autorizacion de Apple Pay. Pulse el boton de Apple Pay para autorizar el pago.', 'powertranz-woocommerce' ) );
		}

		$payment = json_decode( $raw, true );

		if ( ! is_array( $payment ) ) {
			throw new Exception( esc_html__( 'La autorizacion de Apple Pay no tiene el formato esperado.', 'powertranz-woocommerce' ) );
		}

		if ( empty( $payment['token']['paymentData'] ) || ! is_array( $payment['token']['paymentData'] ) ) {
			throw new Exception( esc_html__( 'La autorizacion de Apple Pay no incluye los datos de pago cifrados.', 'powertranz-woocommerce' ) );
		}

		return $payment;
	}

	/**
	 * Comprueba que el importe autorizado por el dispositivo coincide con el pedido.
	 *
	 * @param WC_Order $order     Pedido.
	 * @param array    $decrypted Datos descifrados.
	 * @throws Exception Si los importes no coinciden.
	 */
	protected function verify_decrypted_amount( $order, $decrypted ) {
		if ( ! isset( $decrypted['transactionAmount'] ) || ! is_numeric( $decrypted['transactionAmount'] ) ) {
			return;
		}

		$expected = WC_PowerTranz_Helper::to_minor_units( $order->get_total(), $order->get_currency() );
		$actual   = (int) $decrypted['transactionAmount'];

		// Tolerancia de un centimo por redondeos de la hoja de pago.
		if ( abs( $expected - $actual ) > 1 ) {
			WC_PowerTranz_Logger::log(
				sprintf(
					'Apple Pay: el importe autorizado (%d) no coincide con el total del pedido #%s (%d).',
					$actual,
					$order->get_order_number(),
					$expected
				),
				'error'
			);
			throw new Exception( esc_html__( 'El importe autorizado en Apple Pay no coincide con el total del pedido. Vuelva a intentar el pago.', 'powertranz-woocommerce' ) );
		}
	}

	/**
	 * Captura automatica al completar el pedido.
	 *
	 * @param int      $order_id Id de pedido.
	 * @param WC_Order $order    Pedido.
	 */
	public function maybe_auto_capture( $order_id, $order = null ) {
		if ( 'yes' !== $this->get_option( 'auto_capture_on_completed', 'no' ) ) {
			return;
		}

		$order = $order ? $order : wc_get_order( $order_id );
		if ( ! $order || $this->id !== $order->get_payment_method() ) {
			return;
		}
		if ( 'no' !== $order->get_meta( '_powertranz_captured' ) ) {
			return;
		}

		$result = $this->capture_payment( $order );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: motivo */
					__( 'PowerTranz: la captura automatica fallo. %s', 'powertranz-woocommerce' ),
					$result->get_error_message()
				)
			);
		}
	}
}
