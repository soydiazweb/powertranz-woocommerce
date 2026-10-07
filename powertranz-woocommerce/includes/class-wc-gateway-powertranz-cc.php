<?php
/**
 * Pasarela de tarjetas PowerTranz con 3-D Secure EMV 2.x (SPI).
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Gateway_PowerTranz_CC
 */
class WC_Gateway_PowerTranz_CC extends WC_PowerTranz_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'powertranz_cc';
		$this->method_title       = __( 'PowerTranz (tarjetas)', 'powertranz-woocommerce' );
		$this->method_description = __( 'Acepta tarjetas de credito y debito a traves de First Atlantic Commerce (PowerTranz) con autenticacion 3-D Secure EMV 2.x mediante integracion simplificada (SPI).', 'powertranz-woocommerce' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );
		$this->icon               = apply_filters( 'powertranz_cc_icon', '' );

		parent::__construct();

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_auto_capture' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Ajustes
	 * ------------------------------------------------------------------ */

	/**
	 * Campos de configuracion.
	 */
	public function init_form_fields() {
		$brands = WC_PowerTranz_Helper::get_supported_brands();

		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Activar', 'powertranz-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Activar pagos con tarjeta via PowerTranz', 'powertranz-woocommerce' ),
				'default' => 'no',
			),
			'title' => array(
				'title'       => __( 'Titulo', 'powertranz-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Nombre que ve el cliente en el checkout.', 'powertranz-woocommerce' ),
				'default'     => __( 'Tarjeta de credito o debito', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Descripcion', 'powertranz-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Texto mostrado bajo el titulo en el checkout.', 'powertranz-woocommerce' ),
				'default'     => __( 'Pague de forma segura con su tarjeta. Su banco puede solicitarle una verificacion adicional.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),

			'credentials_section' => array(
				'title'       => __( 'Credenciales', 'powertranz-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'PowerTranz suministra un Id y una contrasena distintos para pruebas y para produccion. Solicitelos al equipo de soporte de First Atlantic Commerce.', 'powertranz-woocommerce' ),
			),
			'environment' => array(
				'title'       => __( 'Entorno', 'powertranz-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'test',
				'options'     => array(
					'test' => __( 'Pruebas (staging.ptranz.com)', 'powertranz-woocommerce' ),
					'live' => __( 'Produccion (gateway.ptranz.com)', 'powertranz-woocommerce' ),
				),
				'description' => __( 'En pruebas no se realizan cargos reales. Use las tarjetas de prueba de la documentacion.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'test_powertranz_id' => array(
				'title'             => __( 'PowerTranz Id (pruebas)', 'powertranz-woocommerce' ),
				'type'              => 'text',
				'default'           => '',
				'custom_attributes' => array( 'autocomplete' => 'off' ),
			),
			'test_powertranz_password' => array(
				'title'             => __( 'PowerTranz Password (pruebas)', 'powertranz-woocommerce' ),
				'type'              => 'password',
				'default'           => '',
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
			),
			'live_powertranz_id' => array(
				'title'             => __( 'PowerTranz Id (produccion)', 'powertranz-woocommerce' ),
				'type'              => 'text',
				'default'           => '',
				'custom_attributes' => array( 'autocomplete' => 'off' ),
			),
			'live_powertranz_password' => array(
				'title'             => __( 'PowerTranz Password (produccion)', 'powertranz-woocommerce' ),
				'type'              => 'password',
				'default'           => '',
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
			),
			'gateway_key' => array(
				'title'       => __( 'PowerTranz GatewayKey', 'powertranz-woocommerce' ),
				'type'        => 'password',
				'default'     => '',
				'description' => __( 'Cabecera opcional. Dejar vacio salvo que PowerTranz le suministre este valor.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),

			'transaction_section' => array(
				'title' => __( 'Transacciones', 'powertranz-woocommerce' ),
				'type'  => 'title',
			),
			'capture_mode' => array(
				'title'       => __( 'Modo de cobro', 'powertranz-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'sale',
				'options'     => array(
					'sale' => __( 'Venta: autoriza y captura de inmediato', 'powertranz-woocommerce' ),
					'auth' => __( 'Preautorizacion: reserva fondos y captura despues', 'powertranz-woocommerce' ),
				),
				'description' => __( 'En modo preautorizacion el pedido queda en el estado configurado abajo hasta que capture manualmente (sin captura no hay cobro).', 'powertranz-woocommerce' ),
			),
			'authorized_order_status' => array(
				'title'       => __( 'Estado tras preautorizar', 'powertranz-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'on-hold',
				'options'     => array(
					'on-hold'    => __( 'En espera', 'powertranz-woocommerce' ),
					'processing' => __( 'Procesando', 'powertranz-woocommerce' ),
				),
			),
			'auto_capture_on_completed' => array(
				'title'   => __( 'Captura automatica', 'powertranz-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Capturar la preautorizacion cuando el pedido pase a Completado', 'powertranz-woocommerce' ),
				'default' => 'no',
			),
			'accepted_cards' => array(
				'title'    => __( 'Tarjetas aceptadas', 'powertranz-woocommerce' ),
				'type'     => 'multiselect',
				'class'    => 'wc-enhanced-select',
				'options'  => $brands,
				'default'  => array( 'visa', 'mastercard', 'amex' ),
				'description' => __( 'Los logos que se muestran en el formulario son exactamente los que marque aqui. Las marcas no seleccionadas se rechazan en el navegador y en el servidor. PowerTranz solo enruta Visa, Mastercard, American Express, Discover y JCB; confirme con BAC cuales tiene habilitadas en su cuenta.', 'powertranz-woocommerce' ),
				'desc_tip' => true,
			),
			'order_prefix' => array(
				'title'       => __( 'Prefijo de OrderIdentifier', 'powertranz-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Prefijo enviado a PowerTranz junto al numero de pedido. Util si varias tiendas comparten el mismo PowerTranz Id.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'fraud_check' => array(
				'title'       => __( 'Verificacion de fraude (Kount)', 'powertranz-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enviar FraudCheck = true', 'powertranz-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Active solo si su cuenta de PowerTranz tiene contratado el servicio de verificacion de fraude.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'request_timeout' => array(
				'title'             => __( 'Timeout de la peticion (segundos)', 'powertranz-woocommerce' ),
				'type'              => 'number',
				'default'           => '60',
				'custom_attributes' => array(
					'min' => '15',
					'max' => '120',
				),
			),

			'threeds_section' => array(
				'title'       => __( '3-D Secure', 'powertranz-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'PowerTranz gestiona internamente la huella digital del dispositivo y el desafio del emisor. El modulo solo debe mostrar los datos de redireccion y evaluar el resultado.', 'powertranz-woocommerce' ),
			),
			'three_ds_enabled' => array(
				'title'       => __( 'Activar 3-D Secure', 'powertranz-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Autenticar con 3-D Secure 2.x (recomendado)', 'powertranz-woocommerce' ),
				'default'     => 'yes',
				'description' => __( 'Si se desactiva, las transacciones se envian sin autenticacion y el comercio asume la responsabilidad ante contracargos por fraude.', 'powertranz-woocommerce' ),
			),
			'three_ds_policy' => array(
				'title'       => __( 'Politica de finalizacion del cobro', 'powertranz-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'liability_shift',
				'options'     => array(
					'liability_shift' => __( 'Solo con proteccion de contracargos: estatus Y o A (recomendado)', 'powertranz-woocommerce' ),
					'gateway_allowed' => __( 'Todo lo que PowerTranz permite: estatus Y, A o U', 'powertranz-woocommerce' ),
					'any'             => __( 'Intentar siempre, incluso N y R (PowerTranz los rechazara)', 'powertranz-woocommerce' ),
				),
				'description' => __( 'Determina cuando se llama a /spi/payment. PowerTranz permite finalizar con Y, A o U, y rechaza N y R. El estatus U significa que la autenticacion fallo por un problema tecnico: se puede cobrar, pero el comercio pierde la proteccion frente a ciertos contracargos y asume el riesgo. Consulte a BAC antes de activarlo.', 'powertranz-woocommerce' ),
			),
			'allow_non_3ds_cards' => array(
				'title'       => __( 'Tarjetas sin 3-D Secure', 'powertranz-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Cobrar cuando la tarjeta no soporta 3DS 2.x (ISO 3D1)', 'powertranz-woocommerce' ),
				'default'     => 'yes',
				'description' => __( 'Estas transacciones no se consideran seguras y no trasladan la responsabilidad al emisor.', 'powertranz-woocommerce' ),
			),
			'challenge_indicator' => array(
				'title'   => __( 'ChallengeIndicator', 'powertranz-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => '01',
				'options' => array(
					'01' => __( '01 - Sin preferencia', 'powertranz-woocommerce' ),
					'02' => __( '02 - No solicitar desafio', 'powertranz-woocommerce' ),
					'03' => __( '03 - Solicitar desafio (preferencia del comercio)', 'powertranz-woocommerce' ),
					'04' => __( '04 - Solicitar desafio (mandato normativo)', 'powertranz-woocommerce' ),
				),
			),
			'challenge_window_size' => array(
				'title'   => __( 'ChallengeWindowSize', 'powertranz-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => '4',
				'options' => array(
					'1' => '250 x 400',
					'2' => '390 x 400',
					'3' => '500 x 600',
					'4' => '600 x 400',
					'5' => __( '5 - Pantalla completa', 'powertranz-woocommerce' ),
				),
			),

			'installments_section' => array(
				'title'       => __( 'Cuotas BAC Credomatic', 'powertranz-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'La API de PowerTranz no tiene un campo de cuotas: el adquirente asigna un PowerTranz Id y contrasena distintos a cada plan. Configure aqui un plan por cada Id que le haya entregado BAC. El pago unico usa las credenciales principales.', 'powertranz-woocommerce' ),
			),
			'installments_enabled' => array(
				'title'   => __( 'Activar cuotas', 'powertranz-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Mostrar el selector de cuotas en el checkout', 'powertranz-woocommerce' ),
				'default' => 'no',
			),
			'installments_label' => array(
				'title'   => __( 'Etiqueta del selector', 'powertranz-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'Cuotas', 'powertranz-woocommerce' ),
			),
			'installments_single_label' => array(
				'title'   => __( 'Etiqueta del pago unico', 'powertranz-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'Un solo pago', 'powertranz-woocommerce' ),
			),
			'installment_plans' => array(
				'title'       => __( 'Planes', 'powertranz-woocommerce' ),
				'type'        => 'powertranz_plans',
				'description' => __( 'BINs permitidos: prefijos separados por comas; dejar vacio para aceptar cualquier tarjeta. Campos extra (JSON): se fusionan en la solicitud si su adquirente pide datos adicionales, por ejemplo <code>{"ExtendedData":{"Installments":3}}</code>.', 'powertranz-woocommerce' ),
			),

			'advanced_section' => array(
				'title' => __( 'Avanzado', 'powertranz-woocommerce' ),
				'type'  => 'title',
			),
			'three_ds_display' => array(
				'title'       => __( 'Presentacion del desafio 3DS', 'powertranz-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'iframe',
				'options'     => array(
					'iframe'   => __( 'iFrame sobre una pagina intermedia (recomendado por PowerTranz)', 'powertranz-woocommerce' ),
					'redirect' => __( 'Pagina completa', 'powertranz-woocommerce' ),
				),
			),
			'debug' => array(
				'title'       => __( 'Modo depuracion', 'powertranz-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Registrar peticiones y respuestas completas', 'powertranz-woocommerce' ),
				'default'     => 'no',
				'description' => sprintf(
					/* translators: %s: enlace a los logs */
					__( 'Las transacciones siempre se registran. Con esta opcion se anaden los cuerpos JSON completos (con el PAN y el CVV enmascarados). Ver %s.', 'powertranz-woocommerce' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . esc_html__( 'WooCommerce &rarr; Estado &rarr; Logs', 'powertranz-woocommerce' ) . '</a>'
				),
			),
		);

		$this->form_fields = array_merge( $this->form_fields, WC_PowerTranz_Branding::get_settings_fields() );
	}

	/**
	 * Estilos y scripts de la pantalla de ajustes.
	 *
	 * @param string $hook Hook actual.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		if ( $this->id !== $section ) {
			return;
		}

		wp_enqueue_style( 'powertranz-admin', WC_POWERTRANZ_URL . 'assets/css/powertranz-admin.css', array(), WC_PowerTranz::asset_version( 'assets/css/powertranz-admin.css' ) );
		wp_enqueue_script( 'powertranz-admin', WC_POWERTRANZ_URL . 'assets/js/powertranz-admin.js', array( 'jquery' ), WC_PowerTranz::asset_version( 'assets/js/powertranz-admin.js' ), true );
	}

	/**
	 * Avisos de configuracion en la pantalla de ajustes.
	 */
	public function admin_options() {
		$credentials = $this->get_credentials();

		if ( 'yes' === $this->enabled && ( '' === $credentials['id'] || '' === $credentials['password'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Faltan las credenciales de PowerTranz para el entorno seleccionado. La pasarela no podra procesar pagos.', 'powertranz-woocommerce' ) . '</p></div>';
		}
		if ( 'yes' === $this->enabled && 'live' === $this->get_environment() && ! is_ssl() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'El sitio no esta sirviendo HTTPS. PowerTranz exige TLS y los datos de tarjeta no deben transmitirse sin cifrar.', 'powertranz-woocommerce' ) . '</p></div>';
		}
		if ( 'yes' === $this->enabled && 'no' === $this->get_option( 'three_ds_enabled', 'yes' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( '3-D Secure esta desactivado. Todas las transacciones se procesaran sin autenticacion del tarjetahabiente.', 'powertranz-woocommerce' ) . '</p></div>';
		}
		if ( 'yes' === $this->get_option( 'installments_enabled', 'no' ) && ! $this->installments->get_plans() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Las cuotas estan activas pero no hay planes configurados.', 'powertranz-woocommerce' ) . '</p></div>';
		}

		echo '<p class="powertranz-hint">' . sprintf(
			/* translators: %s: version de cURL y OpenSSL */
			esc_html__( 'Conexiones salientes: %s. El modulo exige TLS 1.2 como minimo en todas las llamadas a PowerTranz.', 'powertranz-woocommerce' ),
			esc_html( WC_PowerTranz_API::get_tls_support() )
		) . '</p>';

		parent::admin_options();

		if ( $this->is_test() ) {
			WC_PowerTranz_Test_Cards::render_admin_panel();
		}
	}

	/* ---------------------------------------------------------------------
	 * Disponibilidad y checkout
	 * ------------------------------------------------------------------ */

	/**
	 * Indica si la pasarela esta disponible.
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
		if ( '' === WC_PowerTranz_Helper::get_numeric_currency( get_woocommerce_currency() ) ) {
			return false;
		}
		return parent::is_available();
	}

	/**
	 * Marcas aceptadas.
	 *
	 * @return array
	 */
	public function get_accepted_cards() {
		$cards = $this->get_option( 'accepted_cards', array() );
		return is_array( $cards ) ? $cards : array();
	}

	/**
	 * Total del carrito o del pedido a pagar.
	 *
	 * @return float
	 */
	protected function get_checkout_amount() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			if ( $order ) {
				return (float) $order->get_total();
			}
		}
		if ( WC()->cart ) {
			return (float) WC()->cart->get_total( 'edit' );
		}
		return 0.0;
	}

	/**
	 * Parametros para el JS del checkout.
	 *
	 * @return array
	 */
	public function get_frontend_params() {
		$amount = $this->get_checkout_amount();

		return array_merge(
			WC_PowerTranz_Branding::get_frontend_data( $this ),
			array(
				'gateway_id'      => $this->id,
				'accepted_cards'  => $this->get_accepted_cards(),
				'brand_labels'    => WC_PowerTranz_Helper::get_brand_labels(),
				'installments'    => $this->installments->is_enabled() ? $this->installments->get_frontend_plans( $amount ) : array(),
				'installments_on' => $this->installments->is_enabled(),
				'amount'          => $amount,
				'is_test'         => $this->is_test(),
				'i18n'            => array(
				'invalid_number' => __( 'El numero de tarjeta no es valido.', 'powertranz-woocommerce' ),
				'invalid_expiry' => __( 'La fecha de caducidad no es valida.', 'powertranz-woocommerce' ),
				'invalid_cvc'    => __( 'El codigo de seguridad no es valido.', 'powertranz-woocommerce' ),
				'required_cvc'   => __( 'El codigo de seguridad es obligatorio.', 'powertranz-woocommerce' ),
				'sequence_cvc'   => __( 'El codigo de seguridad no es valido. No se aceptan secuencias de ceros ni de nueves.', 'powertranz-woocommerce' ),
				'invalid_name'   => __( 'Indique el nombre que aparece en la tarjeta.', 'powertranz-woocommerce' ),
				'brand_rejected' => __( 'No aceptamos esta marca de tarjeta.', 'powertranz-woocommerce' ),
				'plan_rejected'  => __( 'Esta tarjeta no admite el plan de cuotas seleccionado.', 'powertranz-woocommerce' ),
				'processing'     => __( 'Procesando el pago...', 'powertranz-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Formulario de tarjeta en el checkout.
	 */
	public function payment_fields() {
		$description = $this->get_description();

		if ( $this->is_test() ) {
			$description .= ' <strong>' . __( 'MODO DE PRUEBAS: no se realizaran cargos reales.', 'powertranz-woocommerce' ) . '</strong>';
		}

		if ( $description ) {
			echo '<p>' . wp_kses_post( trim( $description ) ) . '</p>';
		}

		$accepted = $this->get_accepted_cards();
		$amount   = $this->get_checkout_amount();
		$cvv_len  = 4;

		echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-cc-form" class="wc-credit-card-form wc-payment-form powertranz-form">';

		if ( $this->installments->is_enabled() ) {
			$options = $this->installments->get_select_options( $amount );
			if ( count( $options ) > 1 ) {
				echo '<p class="form-row form-row-wide powertranz-installments-row">';
				echo '<label for="powertranz-installments">' . esc_html( $this->installments->get_field_label() ) . '</label>';
				echo '<select id="powertranz-installments" name="powertranz-installments" class="powertranz-installments">';
				foreach ( $options as $value => $label ) {
					echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				echo '</p>';
			}
		}
		?>
		<p class="form-row form-row-wide">
			<label for="powertranz-card-name"><?php esc_html_e( 'Nombre en la tarjeta', 'powertranz-woocommerce' ); ?>&nbsp;<span class="required">*</span></label>
			<input id="powertranz-card-name" name="powertranz-card-name" class="input-text" type="text" autocomplete="cc-name" inputmode="text" maxlength="45" spellcheck="false" required aria-required="true" />
		</p>
		<p class="form-row form-row-wide">
			<label for="powertranz-card-number"><?php esc_html_e( 'Numero de tarjeta', 'powertranz-woocommerce' ); ?>&nbsp;<span class="required">*</span></label>
			<input id="powertranz-card-number" name="powertranz-card-number" class="input-text powertranz-card-number" type="text" inputmode="numeric" autocomplete="cc-number" autocorrect="off" spellcheck="false" maxlength="23" required aria-required="true" placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;" />
		</p>
		<p class="form-row form-row-first">
			<label for="powertranz-card-expiry"><?php esc_html_e( 'Caducidad (MM/AA)', 'powertranz-woocommerce' ); ?>&nbsp;<span class="required">*</span></label>
			<input id="powertranz-card-expiry" name="powertranz-card-expiry" class="input-text powertranz-card-expiry" type="text" inputmode="numeric" autocomplete="cc-exp" maxlength="7" required aria-required="true" placeholder="MM / AA" />
		</p>
		<p class="form-row form-row-last">
			<label for="powertranz-card-cvc"><?php esc_html_e( 'Codigo de seguridad', 'powertranz-woocommerce' ); ?>&nbsp;<span class="required">*</span></label>
			<input id="powertranz-card-cvc" name="powertranz-card-cvc" class="input-text powertranz-card-cvc" type="password" inputmode="numeric" autocomplete="cc-csc" maxlength="<?php echo (int) $cvv_len; ?>" required aria-required="true" placeholder="&bull;&bull;&bull;" aria-describedby="powertranz-cvc-help" />
			<span id="powertranz-cvc-help" class="powertranz-field-help"><?php esc_html_e( 'Los 3 digitos del reverso de la tarjeta (4 en American Express).', 'powertranz-woocommerce' ); ?></span>
		</p>
		<div class="clear"></div>
		<?php
		if ( $this->is_test() ) {
			$this->render_test_card_hint();
		}

		// Marcas aceptadas, aval del adquirente y de la pasarela, y contacto.
		echo WC_PowerTranz_Branding::render_accepted_cards( $accepted, $this ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generado y escapado en la clase de marca.
		echo WC_PowerTranz_Branding::render_seals( $this ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generado y escapado en la clase de marca.
		echo WC_PowerTranz_Branding::render_support_contact( $this ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generado y escapado en la clase de marca.

		echo '</fieldset>';
	}

	/**
	 * Ayuda desplegable con tarjetas de prueba en el checkout.
	 *
	 * Solo se imprime en el entorno de pruebas.
	 */
	protected function render_test_card_hint() {
		$cards = WC_PowerTranz_Test_Cards::get_checkout_shortlist();

		if ( ! $cards ) {
			return;
		}

		echo '<details class="powertranz-test-hint">';
		echo '<summary>' . esc_html__( 'Tarjetas de prueba', 'powertranz-woocommerce' ) . '</summary>';
		echo '<table><tbody>';

		foreach ( $cards as $card ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $card['pan'] ) . '</code></td>';
			echo '<td>' . esc_html( $card['scenario'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p>' . sprintf(
			/* translators: %s: contrasena del desafio */
			esc_html__( 'Clave del desafio: %s. Cualquier caducidad futura y CVV de 3 digitos (4 en Amex).', 'powertranz-woocommerce' ),
			'<code>' . esc_html( WC_PowerTranz_Test_Cards::CHALLENGE_PASSWORD ) . '</code>'
		) . '</p>';
		echo '</details>';
	}

	/* ---------------------------------------------------------------------
	 * Procesamiento del pago
	 * ------------------------------------------------------------------ */

	/**
	 * Lee y valida los datos de tarjeta enviados.
	 *
	 * WooCommerce Blocks copia paymentMethodData a $_POST antes de invocar a
	 * las pasarelas clasicas, por lo que la misma lectura sirve para ambos
	 * checkouts.
	 *
	 * @return array
	 * @throws Exception Si algun dato no es valido.
	 */
	protected function get_posted_card_data() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce valida el nonce del checkout antes de llamar a process_payment.
		$pan    = isset( $_POST['powertranz-card-number'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['powertranz-card-number'] ) ) : '';
		$expiry = isset( $_POST['powertranz-card-expiry'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['powertranz-card-expiry'] ) ) : '';
		$cvc    = isset( $_POST['powertranz-card-cvc'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['powertranz-card-cvc'] ) ) : '';
		$name   = isset( $_POST['powertranz-card-name'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['powertranz-card-name'] ) ) : '';
		// phpcs:enable

		if ( '' === $pan ) {
			throw new Exception( esc_html__( 'Ingrese el numero de tarjeta.', 'powertranz-woocommerce' ) );
		}

		/**
		 * En el entorno de pruebas no se aplica Luhn: varias tarjetas del
		 * sandbox de First Atlantic Commerce no cumplen el digito de control
		 * (por ejemplo 4333333333332222 o 341111000000009) y la validacion
		 * impediria probar la integracion.
		 *
		 * @param bool                     $skip    Si se omite la comprobacion.
		 * @param string                   $pan     Numero de tarjeta.
		 * @param WC_Gateway_PowerTranz_CC $gateway Pasarela.
		 */
		$skip_luhn = (bool) apply_filters( 'powertranz_skip_luhn_check', $this->is_test(), $pan, $this );

		if ( $skip_luhn ) {
			if ( ! WC_PowerTranz_Helper::is_plausible_pan( $pan ) ) {
				throw new Exception( esc_html__( 'El numero de tarjeta no es valido.', 'powertranz-woocommerce' ) );
			}
		} elseif ( ! WC_PowerTranz_Helper::is_luhn_valid( $pan ) ) {
			throw new Exception( esc_html__( 'El numero de tarjeta no es valido.', 'powertranz-woocommerce' ) );
		}

		$brand = WC_PowerTranz_Helper::detect_brand( $pan );
		if ( '' === $brand ) {
			throw new Exception( esc_html__( 'No reconocemos la marca de esta tarjeta.', 'powertranz-woocommerce' ) );
		}

		$accepted = $this->get_accepted_cards();
		if ( $accepted && ! in_array( $brand, $accepted, true ) ) {
			$labels = WC_PowerTranz_Helper::get_brand_labels();
			throw new Exception(
				sprintf(
					/* translators: %s: marca de tarjeta */
					esc_html__( 'No aceptamos tarjetas %s.', 'powertranz-woocommerce' ),
					esc_html( $labels[ $brand ] ?? $brand )
				)
			);
		}

		// Caducidad: acepta MM/AA, MM/AAAA, MM AA y MMAA.
		$digits = preg_replace( '/\D/', '', $expiry );
		if ( 4 !== strlen( $digits ) && 6 !== strlen( $digits ) ) {
			throw new Exception( esc_html__( 'La fecha de caducidad no es valida. Use el formato MM/AA.', 'powertranz-woocommerce' ) );
		}
		$month = substr( $digits, 0, 2 );
		$year  = substr( $digits, 2 );
		$yymm  = WC_PowerTranz_Helper::format_expiry( $month, $year );

		if ( '' === $yymm ) {
			throw new Exception( esc_html__( 'La fecha de caducidad no es valida. Use el formato MM/AA.', 'powertranz-woocommerce' ) );
		}
		if ( WC_PowerTranz_Helper::is_expired( $yymm ) ) {
			throw new Exception( esc_html__( 'La tarjeta esta vencida.', 'powertranz-woocommerce' ) );
		}

		$cvv_error = WC_PowerTranz_Helper::validate_cvv( $cvc, $brand );

		if ( 'empty' === $cvv_error ) {
			throw new Exception( esc_html__( 'El codigo de seguridad es obligatorio.', 'powertranz-woocommerce' ) );
		}
		if ( 'length' === $cvv_error ) {
			throw new Exception(
				sprintf(
					/* translators: %d: numero de digitos */
					esc_html__( 'El codigo de seguridad debe tener %d digitos.', 'powertranz-woocommerce' ),
					(int) WC_PowerTranz_Helper::get_cvv_length( $brand )
				)
			);
		}
		if ( 'sequence' === $cvv_error ) {
			throw new Exception( esc_html__( 'El codigo de seguridad no es valido. No se aceptan secuencias de ceros ni de nueves.', 'powertranz-woocommerce' ) );
		}

		$name = WC_PowerTranz_Helper::sanitize_3ds_text( $name, 45 );
		if ( strlen( $name ) < 2 ) {
			throw new Exception( esc_html__( 'Indique el nombre que aparece en la tarjeta.', 'powertranz-woocommerce' ) );
		}

		return array(
			'pan'    => $pan,
			'cvv'    => $cvc,
			'expiry' => $yymm,
			'name'   => $name,
			'brand'  => $brand,
			'last4'  => substr( $pan, -4 ),
		);
	}

	/**
	 * Procesa el pago.
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
			$card = $this->get_posted_card_data();

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$requested_plan = isset( $_POST['powertranz-installments'] ) ? sanitize_text_field( wp_unslash( $_POST['powertranz-installments'] ) ) : 'single';
			$plan           = $this->installments->resolve_requested_plan( $requested_plan, $order->get_total(), $card['pan'] );

			$attempts = (int) $order->get_meta( '_powertranz_attempts' ) + 1;
			$order->update_meta_data( '_powertranz_attempts', $attempts );

			$transaction_id = wp_generate_uuid4();
			$mode           = $this->get_capture_mode();
			$use_3ds        = 'yes' === $this->get_option( 'three_ds_enabled', 'yes' );

			$payload                  = $this->build_base_payload( $order, $transaction_id );
			$payload['ThreeDSecure']  = $use_3ds;
			$payload['Source']        = array(
				'CardPan'        => $card['pan'],
				'CardCvv'        => $card['cvv'],
				'CardExpiration' => $card['expiry'],
				'CardholderName' => $card['name'],
			);

			if ( $use_3ds ) {
				$payload['ExtendedData'] = $this->build_extended_data( $order );
			}

			if ( ! empty( $plan['extra'] ) ) {
				$payload = $this->merge_plan_extra( $payload, $plan['extra'] );
			}

			// Datos de la tarjeta y del plan en el pedido (nunca el PAN completo ni el CVV).
			$order->update_meta_data( '_powertranz_card_brand', $card['brand'] );
			$order->update_meta_data( '_powertranz_card_last4', $card['last4'] );
			$order->update_meta_data( '_powertranz_card_expiry', $card['expiry'] );
			$order->update_meta_data( '_powertranz_cardholder', $card['name'] );
			$order->update_meta_data( '_powertranz_installment_plan_id', $plan['id'] );
			$order->update_meta_data( '_powertranz_installments', $plan['cuotas'] );
			$order->update_meta_data( '_powertranz_installment_label', $plan['label'] );
			$order->update_meta_data( '_powertranz_capture_mode', $mode );
			$order->update_meta_data( '_powertranz_pending_transaction_id', $transaction_id );
			$order->save();

			if ( $plan['cuotas'] > 1 ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: etiqueta del plan, 2: numero de cuotas */
						__( 'PowerTranz: plan de cuotas seleccionado "%1$s" (%2$d cuotas). Se transacciona con el PowerTranz Id asignado a ese plan.', 'powertranz-woocommerce' ),
						$plan['label'],
						$plan['cuotas']
					)
				);
			}

			$api = $this->get_api( $plan );

			if ( $use_3ds ) {
				return $this->start_3ds_flow( $order, $api, $payload, $mode );
			}

			return $this->process_direct_payment( $order, $api, $payload, $mode );

		} catch ( Exception $e ) {
			$message = $e->getMessage();

			WC_PowerTranz_Logger::transaction( $order, 'validacion', $message, array(), 'warning' );
			$order->add_order_note(
				sprintf(
					/* translators: %s: motivo */
					__( 'PowerTranz: el pago no pudo iniciarse. %s', 'powertranz-woocommerce' ),
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
	 * Fusiona los campos extra de un plan en la solicitud.
	 *
	 * @param array $payload Solicitud.
	 * @param array $extra   Campos extra.
	 * @return array
	 */
	protected function merge_plan_extra( $payload, $extra ) {
		foreach ( $extra as $key => $value ) {
			if ( is_array( $value ) && isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
				$payload[ $key ] = array_replace_recursive( $payload[ $key ], $value );
			} else {
				$payload[ $key ] = $value;
			}
		}
		return $payload;
	}

	/**
	 * Inicia el flujo SPI con 3-D Secure.
	 *
	 * @param WC_Order          $order   Pedido.
	 * @param WC_PowerTranz_API $api     Cliente.
	 * @param array             $payload Solicitud.
	 * @param string            $mode    sale|auth.
	 * @return array
	 */
	protected function start_3ds_flow( $order, $api, $payload, $mode ) {
		$step     = 'auth' === $mode ? 'spi_auth' : 'spi_sale';
		$response = 'auth' === $mode ? $api->spi_auth( $payload ) : $api->spi_sale( $payload );

		$this->record_transaction( $order, $step, $response );

		// Caso normal: SP4 con RedirectData y SpiToken.
		if ( $response->requires_redirect() ) {
			$token = $response->get_spi_token();

			if ( '' === $token ) {
				$message = __( 'PowerTranz no devolvio el token SPI necesario para completar la autenticacion.', 'powertranz-woocommerce' );
				$this->fail_order( $order, $response );
				wc_add_notice( $message, 'error' );
				return array(
					'result'   => 'failure',
					'messages' => $message,
				);
			}

			$order->update_meta_data( '_powertranz_spi_token', $token );
			$order->update_meta_data( '_powertranz_spi_issued_at', time() );
			$order->update_meta_data( '_powertranz_3ds_pending', 'yes' );
			$order->save();

			WC_PowerTranz_3DS_Handler::store_redirect_data( $order, $response->get_redirect_data() );

			$order->update_status(
				'pending',
				__( 'PowerTranz: esperando la autenticacion 3-D Secure del tarjetahabiente.', 'powertranz-woocommerce' )
			);

			return array(
				'result'   => 'success',
				'redirect' => WC_PowerTranz_3DS_Handler::get_challenge_url( $order ),
			);
		}

		// La tarjeta no soporta 3DS 2.x y PowerTranz ya lo informa en la primera llamada.
		if ( '3D1' === $response->get_iso_code() && $response->get_spi_token() ) {
			if ( 'yes' !== $this->get_option( 'allow_non_3ds_cards', 'yes' ) ) {
				$message = __( 'Esta tarjeta no soporta la autenticacion 3-D Secure requerida por la tienda.', 'powertranz-woocommerce' );
				$this->fail_order( $order, $response );
				wc_add_notice( $message, 'error' );
				return array(
					'result'   => 'failure',
					'messages' => $message,
				);
			}

			$payment = $this->complete_spi_payment( $order, $api, $response->get_spi_token(), $mode );

			if ( 'success' !== ( $payment['result'] ?? '' ) && ! empty( $payment['messages'] ) ) {
				wc_add_notice( $payment['messages'], 'error' );
			}

			return $payment;
		}

		// Aprobacion directa (poco habitual en SPI, se contempla por robustez).
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
	}

	/**
	 * Finaliza el cobro tras la autenticacion 3-D Secure.
	 *
	 * @param WC_Order          $order     Pedido.
	 * @param WC_PowerTranz_API $api       Cliente.
	 * @param string            $spi_token Token SPI.
	 * @param string            $mode      sale|auth.
	 * @return array
	 */
	public function complete_spi_payment( $order, $api, $spi_token, $mode ) {
		$response = $api->spi_payment( $spi_token );

		$this->record_transaction( $order, 'spi_payment', $response );

		$order->delete_meta_data( '_powertranz_3ds_pending' );
		$order->save();

		if ( $response->is_approved() ) {
			$this->complete_order( $order, $response, $mode );
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		$message = $response->get_customer_message();
		$this->fail_order( $order, $response );

		return array(
			'result'   => 'failure',
			'messages' => $message,
		);
	}

	/**
	 * Procesa un cobro sin 3-D Secure.
	 *
	 * @param WC_Order          $order   Pedido.
	 * @param WC_PowerTranz_API $api     Cliente.
	 * @param array             $payload Solicitud.
	 * @param string            $mode    sale|auth.
	 * @return array
	 */
	protected function process_direct_payment( $order, $api, $payload, $mode ) {
		$response = 'auth' === $mode ? $api->auth( $payload ) : $api->sale( $payload );

		$this->record_transaction( $order, $mode, $response );

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
