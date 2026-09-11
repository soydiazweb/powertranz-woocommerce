<?php
/**
 * Plugin Name:          PowerTranz para WooCommerce
 * Plugin URI:           https://www.soydiaz.com
 * Description:          Pasarela de pagos First Atlantic Commerce (PowerTranz) para WooCommerce: 3-D Secure EMV 2.x mediante SPI, Apple Pay en la Web y cuotas BAC Credomatic. Registra cada transaccion en el pedido y en los logs de WooCommerce.
 * Version:              1.0.0
 * Author:               Jonathan Diaz
 * Author URI:           https://www.soydiaz.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          powertranz-woocommerce
 * Domain Path:          /languages
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * WC requires at least: 7.0
 * WC tested up to:      9.6
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_POWERTRANZ_VERSION', '1.0.0' );
define( 'WC_POWERTRANZ_FILE', __FILE__ );
define( 'WC_POWERTRANZ_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_POWERTRANZ_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_POWERTRANZ_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declara compatibilidad con HPOS (tablas de pedidos personalizadas) y con
 * los bloques de carrito/checkout.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WC_POWERTRANZ_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WC_POWERTRANZ_FILE, true );
	}
);

/**
 * Arranque del plugin.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					esc_html_e( 'PowerTranz para WooCommerce requiere que WooCommerce este instalado y activo.', 'powertranz-woocommerce' );
					echo '</p></div>';
				}
			);
			return;
		}

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		require_once WC_POWERTRANZ_PATH . 'includes/class-wc-powertranz.php';
		WC_PowerTranz::instance();
	},
	11
);

/**
 * Enlace rapido a los ajustes desde la lista de plugins.
 */
add_filter(
	'plugin_action_links_' . WC_POWERTRANZ_BASENAME,
	static function ( $links ) {
		$settings = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=powertranz_cc' ) ) . '">' . esc_html__( 'Ajustes', 'powertranz-woocommerce' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . esc_html__( 'Logs', 'powertranz-woocommerce' ) . '</a>',
		);
		return array_merge( $settings, $links );
	}
);
