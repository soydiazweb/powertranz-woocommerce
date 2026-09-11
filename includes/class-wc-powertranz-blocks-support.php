<?php
/**
 * Integracion con los bloques de carrito y checkout.
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Base compartida por las integraciones de bloques.
 */
abstract class WC_PowerTranz_Blocks_Base extends AbstractPaymentMethodType {

	/**
	 * Handle del script de bloques.
	 */
	const SCRIPT_HANDLE = 'powertranz-blocks';

	/**
	 * Carga los ajustes de la pasarela.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	/**
	 * Pasarela asociada.
	 *
	 * @return WC_Payment_Gateway|null
	 */
	protected function gateway() {
		return WC_PowerTranz::get_gateway( $this->name );
	}

	/**
	 * Indica si el metodo esta activo.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = $this->gateway();
		return $gateway ? $gateway->is_available() : false;
	}

	/**
	 * Registra y devuelve los handles del script.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		if ( ! wp_script_is( 'powertranz-applepay-core', 'registered' ) ) {
			WC_PowerTranz::register_applepay_sdk();
			wp_register_script(
				'powertranz-applepay-core',
				WC_POWERTRANZ_URL . 'assets/js/powertranz-applepay-core.js',
				array( 'powertranz-apple-sdk' ),
				WC_PowerTranz::asset_version( 'assets/js/powertranz-applepay-core.js' ),
				true
			);
		}

		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::SCRIPT_HANDLE,
				WC_POWERTRANZ_URL . 'assets/js/powertranz-blocks.js',
				array(
					'wc-blocks-registry',
					'wc-settings',
					'wp-element',
					'wp-i18n',
					'wp-html-entities',
					'powertranz-applepay-core',
				),
				WC_PowerTranz::asset_version( 'assets/js/powertranz-blocks.js' ),
				true
			);

			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations( self::SCRIPT_HANDLE, 'powertranz-woocommerce', WC_POWERTRANZ_PATH . 'languages' );
			}
		}

		if ( ! wp_style_is( 'powertranz', 'registered' ) ) {
			wp_register_style(
				'powertranz',
				WC_POWERTRANZ_URL . 'assets/css/powertranz.css',
				array(),
				WC_PowerTranz::asset_version( 'assets/css/powertranz.css' )
			);
		}
		wp_enqueue_style( 'powertranz' );

		return array( self::SCRIPT_HANDLE );
	}
}

/**
 * Tarjetas.
 */
class WC_PowerTranz_Blocks_CC extends WC_PowerTranz_Blocks_Base {

	/**
	 * Nombre del metodo.
	 *
	 * @var string
	 */
	protected $name = 'powertranz_cc';

	/**
	 * Datos expuestos al cliente.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		/** @var WC_Gateway_PowerTranz_CC|null $gateway */
		$gateway = $this->gateway();

		if ( ! $gateway instanceof WC_Gateway_PowerTranz_CC ) {
			return array( 'title' => $this->get_setting( 'title' ) );
		}

		$params = $gateway->get_frontend_params();

		return array_merge(
			$params,
			array(
				'title'       => $gateway->get_title(),
				'description' => $gateway->get_description(),
				'supports'    => array_filter( $gateway->supports, array( $gateway, 'supports' ) ),
				'installments_label' => $gateway->installments->get_field_label(),
				'installment_options' => $gateway->installments->is_enabled()
					? $gateway->installments->get_select_options( $params['amount'] )
					: array(),
			)
		);
	}
}

/**
 * Apple Pay.
 */
class WC_PowerTranz_Blocks_ApplePay extends WC_PowerTranz_Blocks_Base {

	/**
	 * Nombre del metodo.
	 *
	 * @var string
	 */
	protected $name = 'powertranz_applepay';

	/**
	 * Datos expuestos al cliente.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		/** @var WC_Gateway_PowerTranz_ApplePay|null $gateway */
		$gateway = $this->gateway();

		if ( ! $gateway instanceof WC_Gateway_PowerTranz_ApplePay ) {
			return array( 'title' => $this->get_setting( 'title' ) );
		}

		return array_merge(
			$gateway->get_frontend_params(),
			array(
				'title'       => $gateway->get_title(),
				'description' => $gateway->get_description(),
				'supports'    => array_filter( $gateway->supports, array( $gateway, 'supports' ) ),
			)
		);
	}
}
