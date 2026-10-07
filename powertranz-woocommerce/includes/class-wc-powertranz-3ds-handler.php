<?php
/**
 * Flujo 3-D Secure: pagina de desafio y MerchantResponseUrl.
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PowerTranz_3DS_Handler
 */
class WC_PowerTranz_3DS_Handler {

	/**
	 * Vigencia del token SPI segun la documentacion de PowerTranz.
	 */
	const SPI_TOKEN_TTL = 300;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_api_wc_gateway_powertranz_3ds', array( $this, 'render_challenge_page' ) );
		add_action( 'woocommerce_api_wc_gateway_powertranz_return', array( $this, 'handle_merchant_response' ) );
	}

	/* ---------------------------------------------------------------------
	 * URLs
	 * ------------------------------------------------------------------ */

	/**
	 * URL de la pagina intermedia que muestra RedirectData.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	public static function get_challenge_url( $order ) {
		return add_query_arg(
			array(
				'wc-api'   => 'wc_gateway_powertranz_3ds',
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}

	/**
	 * MerchantResponseUrl al que PowerTranz devuelve el resultado 3DS.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	public static function get_merchant_response_url( $order ) {
		/**
		 * Permite sobrescribir el MerchantResponseUrl.
		 *
		 * @param string   $url   URL.
		 * @param WC_Order $order Pedido.
		 */
		return apply_filters(
			'powertranz_merchant_response_url',
			add_query_arg(
				array(
					'wc-api'   => 'wc_gateway_powertranz_return',
					'order_id' => $order->get_id(),
					'key'      => $order->get_order_key(),
				),
				home_url( '/' )
			),
			$order
		);
	}

	/* ---------------------------------------------------------------------
	 * Almacenamiento temporal de RedirectData
	 * ------------------------------------------------------------------ */

	/**
	 * Clave del transient de RedirectData.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	protected static function get_transient_key( $order ) {
		return 'powertranz_rd_' . $order->get_id();
	}

	/**
	 * Guarda el HTML de redireccion.
	 *
	 * No se persiste en los metadatos del pedido: es un fragmento efimero de
	 * un solo uso que puede pesar decenas de kilobytes.
	 *
	 * @param WC_Order $order         Pedido.
	 * @param string   $redirect_data HTML.
	 */
	public static function store_redirect_data( $order, $redirect_data ) {
		set_transient( self::get_transient_key( $order ), $redirect_data, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Recupera el HTML de redireccion.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	public static function get_redirect_data( $order ) {
		$data = get_transient( self::get_transient_key( $order ) );
		return is_string( $data ) ? $data : '';
	}

	/**
	 * Elimina el HTML de redireccion.
	 *
	 * @param WC_Order $order Pedido.
	 */
	public static function clear_redirect_data( $order ) {
		delete_transient( self::get_transient_key( $order ) );
	}

	/* ---------------------------------------------------------------------
	 * Resolucion del pedido
	 * ------------------------------------------------------------------ */

	/**
	 * Resuelve el pedido a partir de la peticion.
	 *
	 * No se usa nonce: la respuesta llega en una peticion POST de origen
	 * cruzado desde el navegador del cliente y las cookies de sesion no
	 * viajan. La autorizacion se basa en la clave del pedido.
	 *
	 * @return WC_Order|null
	 */
	protected function resolve_order() {
		// phpcs:disable WordPress.Security.NonceVerification
		$order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
		$key      = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '';
		// phpcs:enable

		if ( ! $order_id || '' === $key ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}
		if ( ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * Devuelve la pasarela de tarjetas.
	 *
	 * @return WC_Gateway_PowerTranz_CC|null
	 */
	protected function get_gateway() {
		$gateway = WC_PowerTranz::get_gateway( 'powertranz_cc' );
		return $gateway instanceof WC_Gateway_PowerTranz_CC ? $gateway : null;
	}

	/* ---------------------------------------------------------------------
	 * Pagina de desafio
	 * ------------------------------------------------------------------ */

	/**
	 * Muestra RedirectData en el navegador del tarjetahabiente.
	 */
	public function render_challenge_page() {
		nocache_headers();

		$order = $this->resolve_order();

		if ( ! $order ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$gateway = $this->get_gateway();
		if ( ! $gateway ) {
			wp_safe_redirect( $order->get_checkout_payment_url( false ) );
			exit;
		}

		$redirect_data = self::get_redirect_data( $order );

		if ( '' === $redirect_data ) {
			wc_add_notice( __( 'La sesion de autenticacion 3-D Secure expiro. Intente el pago de nuevo.', 'powertranz-woocommerce' ), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url( false ) );
			exit;
		}

		// Un solo uso: si el cliente recarga, no se reinyecta el formulario.
		self::clear_redirect_data( $order );

		$args = array(
			'order'         => $order,
			'redirect_data' => $redirect_data,
			'display_mode'  => $gateway->get_option( 'three_ds_display', 'iframe' ),
			'window_size'   => (int) $gateway->get_option( 'challenge_window_size', 4 ),
			'cancel_url'    => $order->get_checkout_payment_url( false ),
			'support'       => WC_PowerTranz_Branding::render_support_contact( $gateway ),
			'seals'         => WC_PowerTranz_Branding::render_seals( $gateway ),
		);

		WC_PowerTranz_Logger::transaction(
			$order,
			'3ds_render',
			'RedirectData entregado al navegador (' . strlen( $redirect_data ) . ' bytes, modo ' . $args['display_mode'] . ').'
		);

		$template = apply_filters( 'powertranz_3ds_template', WC_POWERTRANZ_PATH . 'templates/3ds-challenge.php', $args );

		if ( file_exists( $template ) ) {
			include $template;
		}
		exit;
	}

	/* ---------------------------------------------------------------------
	 * MerchantResponseUrl
	 * ------------------------------------------------------------------ */

	/**
	 * Recibe el resultado de la autenticacion 3-D Secure y finaliza el cobro.
	 */
	public function handle_merchant_response() {
		nocache_headers();

		$order = $this->resolve_order();

		if ( ! $order ) {
			WC_PowerTranz_Logger::log( 'MerchantResponseUrl recibio una peticion que no corresponde a ningun pedido valido.', 'error' );
			$this->exit_frame( wc_get_checkout_url() );
		}

		$gateway = $this->get_gateway();
		if ( ! $gateway ) {
			$this->exit_frame( $order->get_checkout_payment_url( false ) );
		}

		// Idempotencia: si el pedido ya se resolvio, solo se redirige.
		if ( $order->is_paid() || $order->has_status( array( 'on-hold', 'processing', 'completed' ) ) ) {
			WC_PowerTranz_Logger::transaction( $order, '3ds_return', 'Respuesta 3DS repetida sobre un pedido ya resuelto; se ignora.' );
			$this->exit_frame( $gateway->get_return_url( $order ) );
		}

		$posted    = $this->read_posted_response();
		$spi_token = $posted['spi_token'];
		$result    = $posted['response'];

		WC_PowerTranz_Logger::debug(
			'MerchantResponseUrl recibido para el pedido #' . $order->get_order_number(),
			array(
				'spi_token' => $spi_token,
				'response'  => $result,
			)
		);

		if ( ! empty( $result ) ) {
			$auth_response = new WC_PowerTranz_Response( $result, 200 );
			$gateway->record_transaction( $order, '3ds_result', $auth_response );

			if ( '' === $spi_token ) {
				$spi_token = $auth_response->get_spi_token();
			}

			$cardholder_info = $this->get_cardholder_info( $auth_response );
			if ( '' !== $cardholder_info ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: mensaje del emisor */
						__( 'Mensaje del emisor para el tarjetahabiente: %s', 'powertranz-woocommerce' ),
						$cardholder_info
					)
				);
				wc_add_notice( $cardholder_info, 'notice' );
			}

			$decision = $this->decide( $gateway, $auth_response );

			if ( $decision['proceed'] && ! empty( $decision['log'] ) ) {
				$order->add_order_note( $decision['log'] );
				WC_PowerTranz_Logger::transaction( $order, '3ds_return', $decision['log'], array(), 'warning' );
			}

			if ( ! $decision['proceed'] ) {
				$order->update_status( 'failed', $decision['log'] );
				WC_PowerTranz_Logger::transaction( $order, '3ds_return', 'No se finaliza el cobro: ' . $decision['log'], array(), 'warning' );

				$order->delete_meta_data( '_powertranz_3ds_pending' );
				$order->save();

				wc_add_notice( $decision['message'], 'error' );
				$this->exit_frame( $order->get_checkout_payment_url( false ) );
			}
		} else {
			WC_PowerTranz_Logger::transaction(
				$order,
				'3ds_return',
				'MerchantResponseUrl no incluyo el objeto Response; se intenta finalizar con el token SPI guardado.',
				array(),
				'warning'
			);
		}

		if ( '' === $spi_token ) {
			$spi_token = (string) $order->get_meta( '_powertranz_spi_token' );
		}

		if ( '' === $spi_token ) {
			$message = __( 'No recibimos el token de autenticacion necesario para completar el pago.', 'powertranz-woocommerce' );
			$order->update_status( 'failed', $message );
			wc_add_notice( $message, 'error' );
			$this->exit_frame( $order->get_checkout_payment_url( false ) );
		}

		// El token SPI caduca a los 5 minutos.
		$issued_at = (int) $order->get_meta( '_powertranz_spi_issued_at' );
		if ( $issued_at && ( time() - $issued_at ) > self::SPI_TOKEN_TTL ) {
			$message = __( 'La autenticacion tardo demasiado y el token de pago expiro. Intente el pago de nuevo.', 'powertranz-woocommerce' );
			$order->update_status( 'failed', __( 'PowerTranz: el token SPI expiro antes de finalizar el cobro.', 'powertranz-woocommerce' ) );
			WC_PowerTranz_Logger::transaction( $order, 'spi_payment', 'Token SPI expirado (' . ( time() - $issued_at ) . ' s).', array(), 'warning' );
			wc_add_notice( $message, 'error' );
			$this->exit_frame( $order->get_checkout_payment_url( false ) );
		}

		$mode = 'auth' === $order->get_meta( '_powertranz_capture_mode' ) ? 'auth' : 'sale';
		$api  = $gateway->get_api_for_order( $order );

		$payment = $gateway->complete_spi_payment( $order, $api, $spi_token, $mode );

		if ( isset( $payment['result'] ) && 'success' === $payment['result'] ) {
			$this->exit_frame( $payment['redirect'] );
		}

		wc_add_notice( $payment['messages'] ?? __( 'El pago no pudo completarse.', 'powertranz-woocommerce' ), 'error' );
		$this->exit_frame( $order->get_checkout_payment_url( false ) );
	}

	/**
	 * Lee SpiToken y Response de la peticion.
	 *
	 * PowerTranz envia un POST de formulario, pero se contempla tambien un
	 * cuerpo JSON crudo.
	 *
	 * @return array {spi_token, response}
	 */
	protected function read_posted_response() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$spi_token = '';
		foreach ( array( 'SpiToken', 'spiToken', 'spitoken' ) as $key ) {
			if ( ! empty( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
				$spi_token = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
				break;
			}
		}

		$raw_response = '';
		foreach ( array( 'Response', 'response' ) as $key ) {
			if ( ! empty( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
				$raw_response = wp_unslash( $_POST[ $key ] );
				break;
			}
		}
		// phpcs:enable

		$decoded = array();

		if ( '' !== $raw_response ) {
			$parsed = json_decode( $raw_response, true );
			if ( is_array( $parsed ) ) {
				$decoded = $parsed;
			}
		}

		if ( empty( $decoded ) ) {
			$body = file_get_contents( 'php://input' );
			if ( is_string( $body ) && '' !== $body ) {
				$parsed = json_decode( $body, true );
				if ( is_array( $parsed ) ) {
					$decoded = $parsed;
					if ( '' === $spi_token && ! empty( $parsed['SpiToken'] ) ) {
						$spi_token = sanitize_text_field( (string) $parsed['SpiToken'] );
					}
				}
			}
		}

		return array(
			'spi_token' => $spi_token,
			'response'  => $decoded,
		);
	}

	/**
	 * Mensaje del emisor dirigido al tarjetahabiente.
	 *
	 * @param WC_PowerTranz_Response $response Respuesta.
	 * @return string
	 */
	protected function get_cardholder_info( $response ) {
		$three_ds = $response->get_three_ds();
		$raw      = $three_ds['raw'];

		foreach ( (array) $raw as $key => $value ) {
			if ( is_string( $key ) && 0 === strcasecmp( $key, 'CardholderInfo' ) && is_string( $value ) ) {
				return sanitize_text_field( $value );
			}
		}

		return '';
	}

	/**
	 * Decide si se finaliza el cobro segun el resultado de la autenticacion.
	 *
	 * @param WC_Gateway_PowerTranz_CC $gateway  Pasarela.
	 * @param WC_PowerTranz_Response   $response Respuesta de autenticacion.
	 * @return array {proceed, message, log}
	 */
	protected function decide( $gateway, $response ) {
		$iso      = $response->get_iso_code();
		$three_ds = $response->get_three_ds();
		$status   = $three_ds['status'];
		$policy   = $gateway->get_option( 'three_ds_policy', 'liability_shift' );

		// La tarjeta no soporta 3DS 2.x.
		if ( '3D1' === $iso ) {
			if ( 'yes' === $gateway->get_option( 'allow_non_3ds_cards', 'yes' ) ) {
				return array(
					'proceed' => true,
					'message' => '',
					'log'     => __( 'PowerTranz: la tarjeta no soporta 3DS 2.x (ISO 3D1); se continua sin autenticacion.', 'powertranz-woocommerce' ),
				);
			}
			return array(
				'proceed' => false,
				'message' => __( 'Esta tarjeta no admite la autenticacion 3-D Secure requerida por la tienda.', 'powertranz-woocommerce' ),
				'log'     => __( 'PowerTranz: la tarjeta no soporta 3DS 2.x (ISO 3D1) y la tienda exige autenticacion.', 'powertranz-woocommerce' ),
			);
		}

		// Etapa 3DS completada correctamente.
		if ( '3D0' === $iso ) {
			$protected  = WC_PowerTranz_Helper::get_protected_3ds_statuses();
			$completable = WC_PowerTranz_Helper::get_completable_3ds_statuses();

			$allowed = array();

			switch ( $policy ) {
				case 'any':
					// Se intenta incluso con N y R, que PowerTranz rechazara.
					$allowed = array( 'Y', 'A', 'U', 'N', 'R', '' );
					break;
				case 'gateway_allowed':
					$allowed = $completable;
					break;
				case 'liability_shift':
				default:
					$allowed = $protected;
					break;
			}

			if ( in_array( $status, $allowed, true ) ) {
				$log = '';

				// Diagnostico: cobrar con U es legitimo pero sin proteccion.
				if ( ! in_array( $status, $protected, true ) ) {
					$log = sprintf(
						/* translators: %s: estatus 3DS */
						__( 'PowerTranz: se finaliza el cobro con estatus 3DS %s, que no conserva proteccion frente a contracargos.', 'powertranz-woocommerce' ),
						$status ? $status : '-'
					);
				} elseif ( ! WC_PowerTranz_Helper::has_eci_corroboration( $three_ds ) ) {
					$log = __( 'PowerTranz: el estatus 3DS indica autenticacion pero el ECI o el CAVV no lo respaldan. Revise la configuracion del adquirente con BAC.', 'powertranz-woocommerce' );
				}

				return array(
					'proceed' => true,
					'message' => '',
					'log'     => $log,
				);
			}

			$messages = array(
				'N' => __( 'Su banco no autentico la tarjeta. Verifique los datos o use otro medio de pago.', 'powertranz-woocommerce' ),
				'R' => __( 'Su banco rechazo la autenticacion de esta tarjeta.', 'powertranz-woocommerce' ),
				'U' => __( 'No fue posible autenticar la tarjeta en este momento. Intente de nuevo mas tarde.', 'powertranz-woocommerce' ),
				'C' => __( 'La autenticacion quedo incompleta. Intente el pago de nuevo.', 'powertranz-woocommerce' ),
			);

			// Con N o R no se llama a /spi/payment: PowerTranz lo rechaza.
			$blocked_by_gateway = in_array( $status, array( 'N', 'R' ), true );

			return array(
				'proceed' => false,
				'message' => $messages[ $status ] ?? __( 'La autenticacion 3-D Secure no se completo correctamente.', 'powertranz-woocommerce' ),
				'log'     => $blocked_by_gateway
					? sprintf(
						/* translators: %s: estatus 3DS */
						__( 'PowerTranz: no se finaliza el cobro. Estatus 3DS %s; la pasarela no permite completar el pago con este valor.', 'powertranz-woocommerce' ),
						$status
					)
					: sprintf(
						/* translators: 1: estatus 3DS, 2: politica configurada */
						__( 'PowerTranz: no se finaliza el cobro. Estatus 3DS %1$s sin cumplir la politica "%2$s".', 'powertranz-woocommerce' ),
						$status ? $status : '-',
						$policy
					),
			);
		}

		// Cualquier otro codigo es un error o rechazo de la etapa 3DS.
		return array(
			'proceed' => false,
			'message' => $response->get_customer_message(),
			'log'     => sprintf(
				/* translators: %s: detalle tecnico */
				__( 'PowerTranz: la autenticacion 3-D Secure fallo. %s', 'powertranz-woocommerce' ),
				$response->get_log_message()
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Salida del iFrame
	 * ------------------------------------------------------------------ */

	/**
	 * Rompe el iFrame de 3-D Secure y lleva al cliente a la URL indicada.
	 *
	 * Funciona igual si la respuesta llego en el nivel superior de la pagina.
	 *
	 * @param string $url Destino.
	 */
	protected function exit_frame( $url ) {
		$url = wp_sanitize_redirect( $url );

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
	<title><?php esc_html_e( 'Finalizando el pago...', 'powertranz-woocommerce' ); ?></title>
	<style>
		body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#fff;color:#2c3338;text-align:center;padding:1.5rem}
		.pt-spinner{width:34px;height:34px;margin:0 auto 1rem;border:3px solid #dcdcde;border-top-color:#2271b1;border-radius:50%;animation:pt-spin .8s linear infinite}
		@keyframes pt-spin{to{transform:rotate(360deg)}}
	</style>
</head>
<body>
	<div>
		<div class="pt-spinner" aria-hidden="true"></div>
		<p><?php esc_html_e( 'Estamos finalizando el pago. No cierre esta ventana.', 'powertranz-woocommerce' ); ?></p>
		<p><a id="powertranz-continue" href="<?php echo esc_url( $url ); ?>" target="_top"><?php esc_html_e( 'Continuar', 'powertranz-woocommerce' ); ?></a></p>
	</div>
	<script>
	(function () {
		var url = <?php echo wp_json_encode( $url ); ?>;
		try {
			if (window.top && window.top !== window) {
				window.top.location.href = url;
			} else if (window.parent && window.parent !== window) {
				window.parent.location.href = url;
			} else {
				window.location.href = url;
			}
		} catch (e) {
			window.location.href = url;
		}
	})();
	</script>
</body>
</html>
		<?php
		exit;
	}
}
