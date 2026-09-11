<?php
/**
 * Desinstalacion: elimina unicamente los ajustes del plugin.
 *
 * Los metadatos de los pedidos NO se borran: son el registro contable de las
 * transacciones y deben conservarse.
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_powertranz_cc_settings' );
delete_option( 'woocommerce_powertranz_applepay_settings' );
delete_option( 'wc_powertranz_version' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_powertranz_%' OR option_name LIKE '_transient_timeout_powertranz_%'" );
