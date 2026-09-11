<?php
/**
 * Pagina intermedia que entrega RedirectData al navegador del tarjetahabiente.
 *
 * Se puede sobrescribir con el filtro powertranz_3ds_template.
 *
 * @var array    $args          Argumentos.
 * @var WC_Order $order         Pedido.
 * @var string   $redirect_data HTML devuelto por PowerTranz.
 * @var string   $display_mode  iframe|redirect.
 * @var int      $window_size   ChallengeWindowSize (1-5).
 * @var string   $cancel_url    URL para reintentar el pago.
 * @var string   $support       Bloque de contacto (opcional).
 * @var string   $seals         Sellos del adquirente y la pasarela (opcional).
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

$order         = $args['order'];
$redirect_data = $args['redirect_data'];
$display_mode  = $args['display_mode'];
$window_size   = $args['window_size'];
$cancel_url    = $args['cancel_url'];

$sizes = array(
	1 => array( 250, 400 ),
	2 => array( 390, 400 ),
	3 => array( 500, 600 ),
	4 => array( 600, 400 ),
);

$frame_width  = isset( $sizes[ $window_size ] ) ? $sizes[ $window_size ][0] : 0;
$frame_height = isset( $sizes[ $window_size ] ) ? $sizes[ $window_size ][1] : 0;

if ( ! headers_sent() ) {
	header( 'Content-Type: text/html; charset=utf-8' );
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex,nofollow" />
	<title><?php esc_html_e( 'Verificacion de seguridad de su banco', 'powertranz-woocommerce' ); ?></title>
	<style>
		*,*::before,*::after{box-sizing:border-box}
		body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.25rem;background:#f6f7f7;color:#2c3338;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;line-height:1.5}
		.pt-wrap{width:100%;max-width:680px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.06)}
		.pt-head{text-align:center;margin-bottom:1.25rem}
		.pt-head h1{font-size:1.15rem;margin:0 0 .35rem}
		.pt-head p{margin:0;color:#50575e;font-size:.9rem}
		.pt-frame-holder{display:flex;justify-content:center}
		#powertranz-3ds-frame{width:100%;max-width:100%;border:0;display:block;background:#fff}
		<?php if ( $frame_width ) : ?>
		#powertranz-3ds-frame{width:<?php echo (int) $frame_width; ?>px;height:<?php echo (int) $frame_height; ?>px}
		@media (max-width:<?php echo (int) $frame_width + 80; ?>px){#powertranz-3ds-frame{width:100%}}
		<?php else : ?>
		#powertranz-3ds-frame{height:min(80vh,760px)}
		<?php endif; ?>
		.pt-spinner{width:32px;height:32px;margin:1rem auto;border:3px solid #dcdcde;border-top-color:#2271b1;border-radius:50%;animation:pt-spin .8s linear infinite}
		@keyframes pt-spin{to{transform:rotate(360deg)}}
		.pt-foot{margin-top:1.25rem;text-align:center;font-size:.82rem;color:#787c82}
		.pt-foot a{color:#2271b1}
		.pt-foot .powertranz-seals{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:center;margin:.75rem 0 0;padding:0;list-style:none;opacity:.75}
		.pt-foot .powertranz-seals img{height:26px;width:auto}
		.pt-foot .powertranz-support{margin:.6rem 0 0;font-size:.8rem}
		.pt-order{font-variant-numeric:tabular-nums}
	</style>
</head>
<body>
	<div class="pt-wrap">
		<div class="pt-head">
			<h1><?php esc_html_e( 'Verificacion de seguridad de su banco', 'powertranz-woocommerce' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: 1: numero de pedido, 2: importe */
					esc_html__( 'Pedido %1$s por %2$s. No cierre ni recargue esta ventana.', 'powertranz-woocommerce' ),
					'<span class="pt-order">' . esc_html( $order->get_order_number() ) . '</span>',
					wp_kses_post( $order->get_formatted_order_total() )
				);
				?>
			</p>
		</div>

		<?php if ( 'redirect' === $display_mode ) : ?>
			<div id="powertranz-3ds-inline">
				<div class="pt-spinner" aria-hidden="true"></div>
				<?php
				// El HTML lo genera PowerTranz y contiene el formulario y el script
				// que inician la autenticacion: debe imprimirse sin filtrar.
				echo $redirect_data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>
		<?php else : ?>
			<div class="pt-frame-holder">
				<iframe
					id="powertranz-3ds-frame"
					name="powertranz-3ds-frame"
					title="<?php esc_attr_e( 'Autenticacion 3-D Secure', 'powertranz-woocommerce' ); ?>"
					srcdoc="<?php echo esc_attr( $redirect_data ); ?>"
					frameborder="0"></iframe>
			</div>
			<noscript>
				<p><?php esc_html_e( 'Su navegador necesita JavaScript activado para completar la verificacion de seguridad.', 'powertranz-woocommerce' ); ?></p>
			</noscript>
		<?php endif; ?>

		<div class="pt-foot">
			<a href="<?php echo esc_url( $cancel_url ); ?>" target="_top"><?php esc_html_e( 'Cancelar y volver al pago', 'powertranz-woocommerce' ); ?></a>
			<?php
			if ( ! empty( $args['support'] ) ) {
				echo $args['support']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generado y escapado en la clase de marca.
			}
			if ( ! empty( $args['seals'] ) ) {
				echo $args['seals']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generado y escapado en la clase de marca.
			}
			?>
		</div>
	</div>

	<?php if ( 'redirect' !== $display_mode ) : ?>
	<script>
	(function () {
		var frame = document.getElementById('powertranz-3ds-frame');
		if (!frame) { return; }

		// Respaldo para navegadores que no aplican srcdoc: se inyecta el
		// documento directamente en el iframe.
		var payload = frame.getAttribute('srcdoc');
		window.setTimeout(function () {
			try {
				if (!('srcdoc' in frame) && payload) {
					var doc = frame.contentWindow.document;
					doc.open();
					doc.write(payload);
					doc.close();
				}
			} catch (e) {}
		}, 50);
	})();
	</script>
	<?php endif; ?>
</body>
</html>
