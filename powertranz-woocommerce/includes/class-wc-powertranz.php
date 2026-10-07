<?php
/**
 * Cargador principal.
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PowerTranz
 */
final class WC_PowerTranz {

	/**
	 * Instancia unica.
	 *
	 * @var WC_PowerTranz|null
	 */
	protected static $instance = null;

	/**
	 * Handler de 3-D Secure.
	 *
	 * @var WC_PowerTranz_3DS_Handler
	 */
	public $threeds;

	/**
	 * Servicio de Apple Pay.
	 *
	 * @var WC_PowerTranz_ApplePay_Service
	 */
	public $applepay;

	/**
	 * Devuelve la instancia unica.
	 *
	 * @return WC_PowerTranz
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->includes();
		$this->hooks();
	}

	/**
	 * Carga de clases.
	 */
	private function includes() {
		$path = WC_POWERTRANZ_PATH . 'includes/';

		require_once $path . 'class-wc-powertranz-logger.php';
		require_once $path . 'class-wc-powertranz-helper.php';
		require_once $path . 'class-wc-powertranz-response.php';
		require_once $path . 'class-wc-powertranz-api.php';
		require_once $path . 'class-wc-powertranz-installments.php';
		require_once $path . 'class-wc-powertranz-test-cards.php';
		require_once $path . 'class-wc-powertranz-branding.php';
		require_once $path . 'abstract-class-wc-powertranz-gateway.php';
		require_once $path . 'class-wc-gateway-powertranz-cc.php';
		require_once $path . 'class-wc-powertranz-applepay-decryptor.php';
		require_once $path . 'class-wc-gateway-powertranz-applepay.php';
		require_once $path . 'class-wc-powertranz-3ds-handler.php';
		require_once $path . 'class-wc-powertranz-applepay-service.php';

		if ( is_admin() ) {
			require_once $path . 'class-wc-powertranz-order-admin.php';
			new WC_PowerTranz_Order_Admin();
		}

		$this->threeds  = new WC_PowerTranz_3DS_Handler();
		$this->applepay = new WC_PowerTranz_ApplePay_Service();
	}

	/**
	 * Hooks generales.
	 */
	private function hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateways' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks_support' ) );

		// Motivo del ultimo rechazo como aviso de WooCommerce al volver a la
		// pagina de pago (se muestra ahi o en el checkout si la tienda redirige).
		add_action( 'template_redirect', array( $this, 'maybe_show_last_error' ), 1 );

		// Resultado visible del pago y vaciado del carrito al confirmar.
		foreach ( array( 'powertranz_cc', 'powertranz_applepay' ) as $gateway_id ) {
			add_action( 'woocommerce_thankyou_' . $gateway_id, array( $this, 'render_payment_result' ), 5 );
			add_action( 'woocommerce_thankyou_' . $gateway_id, array( $this, 'maybe_empty_cart' ), 20 );
		}
	}

	/**
	 * Carga las traducciones.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'powertranz-woocommerce', false, dirname( WC_POWERTRANZ_BASENAME ) . '/languages' );
	}

	/**
	 * Registra las pasarelas en WooCommerce.
	 *
	 * @param array $gateways Pasarelas.
	 * @return array
	 */
	public function register_gateways( $gateways ) {
		$gateways[] = 'WC_Gateway_PowerTranz_CC';
		$gateways[] = 'WC_Gateway_PowerTranz_ApplePay';
		return $gateways;
	}

	/**
	 * Devuelve una pasarela por id.
	 *
	 * @param string $id Id de pasarela.
	 * @return WC_Payment_Gateway|null
	 */
	public static function get_gateway( $id ) {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways[ $id ] ) ? $gateways[ $id ] : null;
	}

	/**
	 * Encola scripts y estilos del checkout clasico.
	 */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_checkout' ) ) {
			return;
		}

		$is_checkout = is_checkout() || is_checkout_pay_page() || ( function_exists( 'is_cart' ) && is_cart() );

		/** @var WC_Gateway_PowerTranz_CC|null $cc */
		$cc = self::get_gateway( 'powertranz_cc' );
		/** @var WC_Gateway_PowerTranz_ApplePay|null $ap */
		$ap = self::get_gateway( 'powertranz_applepay' );

		if ( ! $is_checkout ) {
			return;
		}

		wp_enqueue_style(
			'powertranz',
			WC_POWERTRANZ_URL . 'assets/css/powertranz.css',
			array(),
			self::asset_version( 'assets/css/powertranz.css' )
		);

		if ( $cc && 'yes' === $cc->enabled ) {
			wp_enqueue_script(
				'powertranz-checkout',
				WC_POWERTRANZ_URL . 'assets/js/powertranz-checkout.js',
				array( 'jquery' ),
				self::asset_version( 'assets/js/powertranz-checkout.js' ),
				true
			);
			wp_localize_script( 'powertranz-checkout', 'powertranz_cc_params', $cc->get_frontend_params() );
		}

		if ( $ap && $ap->is_available() ) {
			self::register_applepay_sdk();
			wp_register_script(
				'powertranz-applepay-core',
				WC_POWERTRANZ_URL . 'assets/js/powertranz-applepay-core.js',
				array( 'powertranz-apple-sdk' ),
				self::asset_version( 'assets/js/powertranz-applepay-core.js' ),
				true
			);
			wp_enqueue_script(
				'powertranz-applepay',
				WC_POWERTRANZ_URL . 'assets/js/powertranz-applepay.js',
				array( 'jquery', 'powertranz-applepay-core' ),
				self::asset_version( 'assets/js/powertranz-applepay.js' ),
				true
			);
			wp_localize_script( 'powertranz-applepay', 'powertranz_applepay_params', $ap->get_frontend_params() );
		}
	}

	/**
	 * Version de un asset basada en su fecha de modificacion.
	 *
	 * Sin esto, editar un CSS o un JS no invalida la copia en cache del
	 * navegador, porque la URL sigue llevando la misma version del plugin.
	 *
	 * @param string $relative_path Ruta relativa dentro del plugin.
	 * @return string
	 */
	public static function asset_version( $relative_path ) {
		$file = WC_POWERTRANZ_PATH . ltrim( $relative_path, '/' );

		if ( file_exists( $file ) ) {
			return WC_POWERTRANZ_VERSION . '.' . filemtime( $file );
		}

		return WC_POWERTRANZ_VERSION;
	}

	/**
	 * Registra el SDK de Apple Pay que aporta el elemento <apple-pay-button>.
	 *
	 * Se sirve desde el CDN de Apple: es la unica fuente admitida y no puede
	 * empaquetarse localmente.
	 */
	public static function register_applepay_sdk() {
		if ( wp_script_is( 'powertranz-apple-sdk', 'registered' ) ) {
			return;
		}

		wp_register_script(
			'powertranz-apple-sdk',
			'https://applepay.cdn-apple.com/jsapi/v1/apple-pay-sdk.js',
			array(),
			null,
			true
		);
	}

	/**
	 * Integracion con los bloques de checkout.
	 */
	public function register_blocks_support() {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		require_once WC_POWERTRANZ_PATH . 'includes/class-wc-powertranz-blocks-support.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new WC_PowerTranz_Blocks_CC() );
				$registry->register( new WC_PowerTranz_Blocks_ApplePay() );
			}
		);
	}

	/**
	 * Convierte el motivo del ultimo rechazo en un aviso de WooCommerce cuando
	 * el cliente vuelve a la pagina de pago del pedido.
	 *
	 * El resultado de 3-D Secure llega en una peticion de origen cruzado sin
	 * cookies, por lo que el aviso no puede guardarse en la sesion en ese
	 * momento: se persiste en el pedido y se pasa a la sesion aqui (peticion
	 * normal del navegador, con cookies), una sola vez. Al quedar en la sesion
	 * se muestra en "Pagar pedido" o en el checkout si la tienda redirige ahi.
	 */
	public function maybe_show_last_error() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) || ! WC()->session ) {
			return;
		}

		$order_id = absint( get_query_var( 'order-pay' ) );

		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// La clave del pedido acredita el acceso, igual que en la propia pagina.
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return;
		}

		if ( ! in_array( $order->get_payment_method(), array( 'powertranz_cc', 'powertranz_applepay' ), true ) ) {
			return;
		}

		$error = (string) $order->get_meta( '_powertranz_last_error' );
		if ( '' === $error ) {
			return;
		}

		$order->delete_meta_data( '_powertranz_last_error' );
		$order->save();

		if ( ! wc_has_notice( $error, 'error' ) ) {
			wc_add_notice( $error, 'error' );
		}
	}

	/**
	 * Muestra al cliente el resultado del pago: aprobado o denegado.
	 *
	 * FAC exige que el resultado se comunique explicitamente al tarjetahabiente.
	 *
	 * @param int $order_id Id de pedido.
	 */
	public function render_payment_result( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$approved = $order->is_paid() || $order->has_status( array( 'on-hold', 'processing', 'completed' ) );
		$brand    = (string) $order->get_meta( '_powertranz_card_brand' );
		$last4    = (string) $order->get_meta( '_powertranz_card_last4' );
		$wallet   = (string) $order->get_meta( '_powertranz_wallet' );

		$rows = array();

		if ( $wallet ) {
			$rows[ __( 'Medio de pago', 'powertranz-woocommerce' ) ] = 'ApplePay' === $wallet
				? 'Apple Pay' . ( $order->get_meta( '_powertranz_applepay_card' ) ? ' - ' . $order->get_meta( '_powertranz_applepay_card' ) : '' )
				: $wallet;
		} elseif ( $brand || $last4 ) {
			$rows[ __( 'Medio de pago', 'powertranz-woocommerce' ) ] = trim( $brand . ( $last4 ? ' terminada en ' . $last4 : '' ) );
		}

		if ( $order->get_meta( '_powertranz_auth_code' ) ) {
			$rows[ __( 'Autorizacion', 'powertranz-woocommerce' ) ] = $order->get_meta( '_powertranz_auth_code' );
		}
		if ( $order->get_meta( '_powertranz_rrn' ) ) {
			$rows[ __( 'Referencia', 'powertranz-woocommerce' ) ] = $order->get_meta( '_powertranz_rrn' );
		}
		if ( (int) $order->get_meta( '_powertranz_installments' ) > 1 ) {
			$rows[ __( 'Cuotas', 'powertranz-woocommerce' ) ] = $order->get_meta( '_powertranz_installment_label' );
		}
		if ( $order->get_date_created() ) {
			$rows[ __( 'Fecha', 'powertranz-woocommerce' ) ] = wc_format_datetime( $order->get_date_created(), get_option( 'date_format' ) . ' H:i' );
		}

		$rows[ __( 'Importe', 'powertranz-woocommerce' ) ] = wp_strip_all_tags( $order->get_formatted_order_total() );

		echo '<div class="powertranz-result powertranz-result--' . ( $approved ? 'approved' : 'declined' ) . '">';
		echo '<p class="powertranz-result__status">';

		if ( $approved ) {
			echo '<span class="powertranz-result__badge" aria-hidden="true">&#10003;</span> ';
			echo '<strong>' . esc_html__( 'Transaccion aprobada', 'powertranz-woocommerce' ) . '</strong>';
		} else {
			echo '<span class="powertranz-result__badge" aria-hidden="true">&#10005;</span> ';
			echo '<strong>' . esc_html__( 'Transaccion denegada', 'powertranz-woocommerce' ) . '</strong>';
		}

		echo '</p>';

		if ( ! $approved ) {
			$reason = (string) $order->get_meta( '_powertranz_last_error' );
			if ( '' !== $reason ) {
				echo '<p class="powertranz-result__reason">' . esc_html( $reason ) . '</p>';
			}
			echo '<p class="powertranz-result__reason">' . esc_html__( 'No se realizo ningun cargo a su tarjeta. Puede intentar el pago de nuevo o usar otro medio de pago.', 'powertranz-woocommerce' ) . '</p>';
		}

		echo '<ul class="powertranz-result__details">';
		foreach ( $rows as $label => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			echo '<li><span>' . esc_html( $label ) . '</span> <strong>' . esc_html( $value ) . '</strong></li>';
		}
		echo '</ul>';

		$gateway = self::get_gateway( $order->get_payment_method() );
		if ( $gateway instanceof WC_PowerTranz_Gateway ) {
			echo WC_PowerTranz_Branding::render_support_contact( $gateway ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</div>';
	}

	/**
	 * Vacia el carrito cuando el pedido ya esta pagado.
	 *
	 * @param int $order_id Id de pedido.
	 */
	public function maybe_empty_cart( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! WC()->cart ) {
			return;
		}
		if ( $order->is_paid() || $order->has_status( array( 'on-hold', 'processing', 'completed' ) ) ) {
			WC()->cart->empty_cart();
		}
	}
}
