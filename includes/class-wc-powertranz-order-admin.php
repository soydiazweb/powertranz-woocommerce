<?php
/**
 * Pantalla de pedido: registro de transacciones y acciones de captura/anulacion.
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PowerTranz_Order_Admin
 */
class WC_PowerTranz_Order_Admin {

	/**
	 * Pasarelas gestionadas.
	 *
	 * @var array
	 */
	protected $gateway_ids = array( 'powertranz_cc', 'powertranz_applepay' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 30 );
		add_filter( 'woocommerce_order_actions', array( $this, 'add_order_actions' ) );
		add_action( 'woocommerce_order_action_powertranz_capture', array( $this, 'action_capture' ) );
		add_action( 'woocommerce_order_action_powertranz_void', array( $this, 'action_void' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Estilos de la caja de registro.
	 */
	public function enqueue_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'powertranz-admin', WC_POWERTRANZ_URL . 'assets/css/powertranz-admin.css', array(), WC_PowerTranz::asset_version( 'assets/css/powertranz-admin.css' ) );
	}

	/**
	 * Pedido de la pantalla actual, compatible con HPOS.
	 *
	 * @param mixed $post_or_order Post o pedido.
	 * @return WC_Order|null
	 */
	protected function resolve_order( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( is_object( $post_or_order ) && isset( $post_or_order->ID ) ) {
			$order = wc_get_order( $post_or_order->ID );
			return $order ? $order : null;
		}
		return null;
	}

	/**
	 * Registra la caja de registro de transacciones.
	 */
	public function add_meta_box() {
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );

		foreach ( $screens as $screen ) {
			add_meta_box(
				'powertranz_transaction_log',
				__( 'PowerTranz: registro de transacciones', 'powertranz-woocommerce' ),
				array( $this, 'render_meta_box' ),
				$screen,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Contenido de la caja.
	 *
	 * @param mixed $post_or_order Post o pedido.
	 */
	public function render_meta_box( $post_or_order ) {
		$order = $this->resolve_order( $post_or_order );

		if ( ! $order ) {
			return;
		}

		if ( ! in_array( $order->get_payment_method(), $this->gateway_ids, true ) ) {
			echo '<p>' . esc_html__( 'Este pedido no se pago con PowerTranz.', 'powertranz-woocommerce' ) . '</p>';
			return;
		}

		$this->render_summary( $order );
		$this->render_audit( $order );
	}

	/**
	 * Resumen de la transaccion.
	 *
	 * @param WC_Order $order Pedido.
	 */
	protected function render_summary( $order ) {
		$captured   = $order->get_meta( '_powertranz_captured' );
		$three_ds   = $order->get_meta( '_powertranz_3ds_status' );
		$cuotas     = (int) $order->get_meta( '_powertranz_installments' );
		$environment = $order->get_meta( '_powertranz_environment' );

		$rows = array(
			__( 'Entorno', 'powertranz-woocommerce' ) => 'live' === $environment
				? __( 'Produccion', 'powertranz-woocommerce' )
				: __( 'Pruebas', 'powertranz-woocommerce' ),
			__( 'Transaccion', 'powertranz-woocommerce' )   => $order->get_meta( '_powertranz_transaction_id' ),
			__( 'Autorizacion', 'powertranz-woocommerce' )  => $order->get_meta( '_powertranz_auth_code' ),
			__( 'RRN', 'powertranz-woocommerce' )           => $order->get_meta( '_powertranz_rrn' ),
			__( 'Codigo ISO', 'powertranz-woocommerce' )    => $order->get_meta( '_powertranz_iso_code' ),
			__( 'Respuesta', 'powertranz-woocommerce' )     => $order->get_meta( '_powertranz_response_message' ),
		);

		$brand = $order->get_meta( '_powertranz_card_brand' );
		$last4 = $order->get_meta( '_powertranz_card_last4' );
		$wallet = $order->get_meta( '_powertranz_wallet' );

		if ( $wallet ) {
			$rows[ __( 'Billetera', 'powertranz-woocommerce' ) ] = $wallet . ( $order->get_meta( '_powertranz_applepay_card' ) ? ' - ' . $order->get_meta( '_powertranz_applepay_card' ) : '' );
		}
		if ( $brand || $last4 ) {
			$rows[ __( 'Tarjeta', 'powertranz-woocommerce' ) ] = trim( $brand . ( $last4 ? ' ****' . $last4 : '' ) );
		}
		if ( $order->get_meta( '_powertranz_card_expiry' ) ) {
			$yymm = (string) $order->get_meta( '_powertranz_card_expiry' );
			$rows[ __( 'Caducidad', 'powertranz-woocommerce' ) ] = substr( $yymm, 2, 2 ) . '/' . substr( $yymm, 0, 2 );
		}
		if ( $order->get_meta( '_powertranz_cardholder' ) ) {
			$rows[ __( 'Titular', 'powertranz-woocommerce' ) ] = $order->get_meta( '_powertranz_cardholder' );
		}
		if ( $order->get_meta( '_powertranz_pan_token' ) ) {
			$rows[ __( 'PanToken', 'powertranz-woocommerce' ) ] = $order->get_meta( '_powertranz_pan_token' );
		}

		if ( $cuotas > 1 ) {
			$rows[ __( 'Cuotas', 'powertranz-woocommerce' ) ] = sprintf(
				/* translators: 1: numero de cuotas, 2: etiqueta del plan */
				__( '%1$d (%2$s)', 'powertranz-woocommerce' ),
				$cuotas,
				$order->get_meta( '_powertranz_installment_label' )
			);
		}

		if ( $three_ds ) {
			$rows[ __( 'Estatus 3-D Secure', 'powertranz-woocommerce' ) ] = WC_PowerTranz_Helper::get_3ds_status_label( $three_ds );
			$rows[ __( 'ECI', 'powertranz-woocommerce' ) ]                = WC_PowerTranz_Helper::get_eci_label(
				(string) $order->get_meta( '_powertranz_3ds_eci' ),
				(string) $brand
			);
			$rows[ __( 'Version del protocolo', 'powertranz-woocommerce' ) ] = $order->get_meta( '_powertranz_3ds_version' );
			$rows[ __( 'DsTransId', 'powertranz-woocommerce' ) ]             = $order->get_meta( '_powertranz_3ds_ds_trans_id' );
			$rows[ __( 'CAVV presente', 'powertranz-woocommerce' ) ]         = 'yes' === $order->get_meta( '_powertranz_3ds_cavv_present' )
				? __( 'Si', 'powertranz-woocommerce' )
				: __( 'No', 'powertranz-woocommerce' );
			$rows[ __( 'Traslado de responsabilidad', 'powertranz-woocommerce' ) ] = 'yes' === $order->get_meta( '_powertranz_3ds_liability_shift' )
				? __( 'Si, al emisor', 'powertranz-woocommerce' )
				: __( 'No, la asume el comercio', 'powertranz-woocommerce' );
		} elseif ( ! $wallet ) {
			$rows[ __( 'Estatus 3-D Secure', 'powertranz-woocommerce' ) ] = __( 'Sin autenticacion 3-D Secure', 'powertranz-woocommerce' );
		}

		if ( $order->get_meta( '_powertranz_avs_response' ) ) {
			$rows[ __( 'AVS', 'powertranz-woocommerce' ) ] = $order->get_meta( '_powertranz_avs_response' );
		}
		if ( $order->get_meta( '_powertranz_cvv_response' ) ) {
			$rows[ __( 'CVV', 'powertranz-woocommerce' ) ] = $order->get_meta( '_powertranz_cvv_response' );
		}

		if ( '' !== (string) $captured ) {
			$rows[ __( 'Captura', 'powertranz-woocommerce' ) ] = 'yes' === $captured
				? __( 'Capturada', 'powertranz-woocommerce' )
				: __( 'Solo autorizada (pendiente de captura)', 'powertranz-woocommerce' );
		}
		if ( 'yes' === $order->get_meta( '_powertranz_voided' ) ) {
			$rows[ __( 'Anulacion', 'powertranz-woocommerce' ) ] = __( 'Autorizacion anulada', 'powertranz-woocommerce' );
		}
		if ( (float) $order->get_meta( '_powertranz_refunded_amount' ) > 0 ) {
			$rows[ __( 'Reembolsado en PowerTranz', 'powertranz-woocommerce' ) ] = wp_strip_all_tags(
				wc_price( $order->get_meta( '_powertranz_refunded_amount' ), array( 'currency' => $order->get_currency() ) )
			);
		}

		echo '<table class="powertranz-summary widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			if ( '' === (string) $value || null === $value ) {
				continue;
			}
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><code>' . esc_html( $value ) . '</code></td></tr>';
		}
		echo '</tbody></table>';

		$this->render_operations( $order );
	}

	/**
	 * Explica que operaciones admite el pedido en su estado actual y como se
	 * ejecutan desde la propia pantalla.
	 *
	 * @param WC_Order $order Pedido.
	 */
	protected function render_operations( $order ) {
		$transaction = (string) $order->get_meta( '_powertranz_transaction_id' );

		if ( '' === $transaction ) {
			echo '<p class="powertranz-hint">' . esc_html__( 'Todavia no hay una transaccion de PowerTranz sobre la que operar.', 'powertranz-woocommerce' ) . '</p>';
			return;
		}

		$captured = $order->get_meta( '_powertranz_captured' );
		$voided   = 'yes' === $order->get_meta( '_powertranz_voided' );
		$currency = $order->get_currency();
		$total    = (float) $order->get_total();
		$refunded = (float) $order->get_meta( '_powertranz_refunded_amount' );

		echo '<h4>' . esc_html__( 'Operaciones disponibles', 'powertranz-woocommerce' ) . '</h4>';

		if ( $voided ) {
			echo '<p class="powertranz-hint">' . esc_html__( 'La autorizacion fue anulada. No quedan operaciones pendientes: no hubo cobro, asi que tampoco hay nada que reembolsar.', 'powertranz-woocommerce' ) . '</p>';
			return;
		}

		echo '<ul class="powertranz-operations">';

		if ( 'no' === $captured ) {
			// Preautorizado: solo caben capturar o anular.
			echo '<li><strong>' . esc_html__( 'Capturar', 'powertranz-woocommerce' ) . '</strong> &mdash; ';
			printf(
				/* translators: %s: importe autorizado */
				esc_html__( 'cobra los %s reservados. En el recuadro "Acciones del pedido" de la columna derecha elija "PowerTranz: capturar la autorizacion" y pulse el boton de flecha. El pedido pasara a pagado.', 'powertranz-woocommerce' ),
				esc_html( wp_strip_all_tags( wc_price( $total, array( 'currency' => $currency ) ) ) )
			);
			echo '</li>';

			echo '<li><strong>' . esc_html__( 'Anular', 'powertranz-woocommerce' ) . '</strong> &mdash; ';
			esc_html_e( 'libera la reserva sin cobrar nada. Misma ruta, eligiendo "PowerTranz: anular la autorizacion". El pedido pasara a cancelado. PowerTranz no admite anulaciones parciales: se anula el importe completo.', 'powertranz-woocommerce' );
			echo '</li>';

			echo '<li><strong>' . esc_html__( 'Reembolsar', 'powertranz-woocommerce' ) . '</strong> &mdash; ';
			esc_html_e( 'todavia no aplica. Mientras no haya captura no ha salido dinero de la tarjeta; si pide un reembolso total el modulo hara una anulacion en su lugar.', 'powertranz-woocommerce' );
			echo '</li>';

			echo '</ul>';
			echo '<p class="powertranz-hint">' . esc_html__( 'Importante: sin captura no hay cobro. La reserva caduca por si sola en unos dias segun el emisor y el dinero nunca llega al comercio.', 'powertranz-woocommerce' ) . '</p>';
			return;
		}

		// Capturado: cabe reembolsar.
		$pending = max( 0, $total - $refunded );

		echo '<li><strong>' . esc_html__( 'Reembolsar', 'powertranz-woocommerce' ) . '</strong> &mdash; ';
		esc_html_e( 'en la lista de articulos de arriba pulse "Reembolsar", indique importe o cantidades y pulse el boton "Reembolsar ... via PowerTranz". Admite reembolsos parciales y varios reembolsos sucesivos.', 'powertranz-woocommerce' );
		echo '</li>';

		if ( $refunded > 0 ) {
			echo '<li><strong>' . esc_html__( 'Ya reembolsado', 'powertranz-woocommerce' ) . '</strong> &mdash; ';
			printf(
				/* translators: 1: importe reembolsado, 2: importe pendiente */
				esc_html__( '%1$s de %2$s disponibles.', 'powertranz-woocommerce' ),
				esc_html( wp_strip_all_tags( wc_price( $refunded, array( 'currency' => $currency ) ) ) ),
				esc_html( wp_strip_all_tags( wc_price( $total, array( 'currency' => $currency ) ) ) )
			);
			echo '</li>';
		}

		echo '<li><strong>' . esc_html__( 'Capturar', 'powertranz-woocommerce' ) . '</strong> &mdash; ';
		esc_html_e( 'ya no aplica: esta transaccion esta capturada.', 'powertranz-woocommerce' );
		echo '</li>';

		echo '<li><strong>' . esc_html__( 'Anular', 'powertranz-woocommerce' ) . '</strong> &mdash; ';
		esc_html_e( 'ya no aplica. La anulacion solo sirve antes de la captura; despues corresponde un reembolso.', 'powertranz-woocommerce' );
		echo '</li>';

		echo '</ul>';

		if ( $pending > 0 ) {
			echo '<p class="powertranz-hint">' . sprintf(
				/* translators: %s: importe reembolsable */
				esc_html__( 'Reembolsable ahora mismo: %s. El reembolso se envia con las credenciales con las que se cobro el pedido, incluidas las del plan de cuotas si se uso uno.', 'powertranz-woocommerce' ),
				esc_html( wp_strip_all_tags( wc_price( $pending, array( 'currency' => $currency ) ) ) )
			) . '</p>';
		}
	}

	/**
	 * Historial auditable de pasos.
	 *
	 * @param WC_Order $order Pedido.
	 */
	protected function render_audit( $order ) {
		$audit = $order->get_meta( '_powertranz_audit' );

		if ( ! is_array( $audit ) || ! $audit ) {
			echo '<p>' . esc_html__( 'Todavia no hay pasos registrados para este pedido.', 'powertranz-woocommerce' ) . '</p>';
			return;
		}

		echo '<h4>' . esc_html__( 'Historial de llamadas al API', 'powertranz-woocommerce' ) . '</h4>';
		echo '<table class="powertranz-audit widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Fecha', 'powertranz-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Paso', 'powertranz-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'ISO', 'powertranz-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Resultado', 'powertranz-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( '3DS', 'powertranz-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Importe', 'powertranz-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'HTTP', 'powertranz-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( array_reverse( $audit ) as $entry ) {
			$approved = 'yes' === ( $entry['approved'] ?? 'no' );
			$detail   = trim( (string) ( $entry['message'] ?? '' ) );

			if ( ! empty( $entry['errors'] ) ) {
				$detail .= ( $detail ? ' | ' : '' ) . $entry['errors'];
			}
			if ( ! empty( $entry['auth_code'] ) ) {
				$detail .= ( $detail ? ' | ' : '' ) . __( 'Aut. ', 'powertranz-woocommerce' ) . $entry['auth_code'];
			}

			echo '<tr class="' . ( $approved ? 'powertranz-ok' : '' ) . '">';
			echo '<td>' . esc_html( $entry['time'] ?? '' ) . '</td>';
			echo '<td><code>' . esc_html( $entry['step'] ?? '' ) . '</code></td>';
			echo '<td><code>' . esc_html( $entry['iso'] ?? '' ) . '</code></td>';
			echo '<td>' . esc_html( $detail ) . '</td>';
			echo '<td>' . esc_html( ( $entry['3ds_status'] ?? '' ) ? $entry['3ds_status'] . ( ! empty( $entry['3ds_eci'] ) ? ' / ECI ' . $entry['3ds_eci'] : '' ) : '-' ) . '</td>';
			echo '<td>' . esc_html( $entry['amount'] ? wp_strip_all_tags( wc_price( $entry['amount'], array( 'currency' => $order->get_currency() ) ) ) : '-' ) . '</td>';
			echo '<td>' . esc_html( $entry['http'] ?? '' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="powertranz-hint">' . sprintf(
			/* translators: %s: enlace a los logs */
			wp_kses_post( __( 'El detalle tecnico completo esta en %s, en el archivo de origen <code>powertranz</code>.', 'powertranz-woocommerce' ) ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . esc_html__( 'WooCommerce &rarr; Estado &rarr; Logs', 'powertranz-woocommerce' ) . '</a>'
		) . '</p>';
	}

	/* ---------------------------------------------------------------------
	 * Acciones de pedido
	 * ------------------------------------------------------------------ */

	/**
	 * Anade capturar y anular al selector de acciones del pedido.
	 *
	 * @param array $actions Acciones.
	 * @return array
	 */
	public function add_order_actions( $actions ) {
		global $theorder;

		$order = $theorder instanceof WC_Order ? $theorder : null;

		if ( ! $order || ! in_array( $order->get_payment_method(), $this->gateway_ids, true ) ) {
			return $actions;
		}
		if ( ! $order->get_meta( '_powertranz_transaction_id' ) ) {
			return $actions;
		}
		if ( 'no' !== $order->get_meta( '_powertranz_captured' ) ) {
			return $actions;
		}
		if ( 'yes' === $order->get_meta( '_powertranz_voided' ) ) {
			return $actions;
		}

		$actions['powertranz_capture'] = __( 'PowerTranz: capturar la autorizacion', 'powertranz-woocommerce' );
		$actions['powertranz_void']    = __( 'PowerTranz: anular la autorizacion', 'powertranz-woocommerce' );

		return $actions;
	}

	/**
	 * Ejecuta la captura.
	 *
	 * @param WC_Order $order Pedido.
	 */
	public function action_capture( $order ) {
		$gateway = WC_PowerTranz::get_gateway( $order->get_payment_method() );

		if ( ! $gateway instanceof WC_PowerTranz_Gateway ) {
			return;
		}

		$result = $gateway->capture_payment( $order );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: motivo */
					__( 'PowerTranz: la captura fallo. %s', 'powertranz-woocommerce' ),
					$result->get_error_message()
				)
			);
		}
	}

	/**
	 * Ejecuta la anulacion.
	 *
	 * @param WC_Order $order Pedido.
	 */
	public function action_void( $order ) {
		$gateway = WC_PowerTranz::get_gateway( $order->get_payment_method() );

		if ( ! $gateway instanceof WC_PowerTranz_Gateway ) {
			return;
		}

		$result = $gateway->void_payment( $order );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: motivo */
					__( 'PowerTranz: la anulacion fallo. %s', 'powertranz-woocommerce' ),
					$result->get_error_message()
				)
			);
		}
	}
}
