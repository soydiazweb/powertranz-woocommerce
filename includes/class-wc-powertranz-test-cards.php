<?php
/**
 * Catalogo de tarjetas y casos de prueba de First Atlantic Commerce.
 *
 * Cada fila indica, ademas del dato de la tarjeta, que debe ocurrir en el
 * modulo, de modo que la tabla sirve como plan de pruebas y no solo como copia
 * de la documentacion.
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PowerTranz_Test_Cards
 */
class WC_PowerTranz_Test_Cards {

	/**
	 * Contrasena del desafio 3-D Secure en el entorno de pruebas.
	 */
	const CHALLENGE_PASSWORD = '3ds2';

	/**
	 * Tarjetas cuya autorizacion se aprueba.
	 *
	 * @return array
	 */
	public static function get_approved() {
		return array(
			array(
				'case'     => 'V2-01-YA',
				'pan'      => '4012000000020071',
				'brand'    => 'Visa',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Sin friccion, sin huella digital. Estatus Y.', 'powertranz-woocommerce' ),
				'expected' => __( 'El pedido se paga sin que el cliente vea ningun desafio.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'V2-02-AA',
				'pan'      => '4012000000020089',
				'brand'    => 'Visa',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Sin friccion. Estatus A: autentico la red, no el emisor.', 'powertranz-woocommerce' ),
				'expected' => __( 'Se paga y la nota indica traslado de responsabilidad: si.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'V2-03-YA',
				'pan'      => '4012000000020006',
				'brand'    => 'Visa',
				'three_ds' => '2.x',
				'password' => self::CHALLENGE_PASSWORD,
				'scenario' => __( 'Con desafio del emisor. Estatus Y.', 'powertranz-woocommerce' ),
				'expected' => __( 'Aparece el desafio en el iFrame; tras introducir la clave el pedido se paga.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'V2-04-YA',
				'pan'      => '4012010000020070',
				'brand'    => 'Visa',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Sin friccion con huella digital del dispositivo. Estatus Y.', 'powertranz-woocommerce' ),
				'expected' => __( 'El iFrame trabaja unos segundos sin mostrar nada y el pedido se paga.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'V2-05-AA',
				'pan'      => '4012010000020088',
				'brand'    => 'Visa',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Sin friccion con huella digital. Estatus A.', 'powertranz-woocommerce' ),
				'expected' => __( 'Se paga con traslado de responsabilidad.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'V2-06-YA',
				'pan'      => '4012010000020005',
				'brand'    => 'Visa',
				'three_ds' => '2.x',
				'password' => self::CHALLENGE_PASSWORD,
				'scenario' => __( 'Con desafio y huella digital. Estatus Y.', 'powertranz-woocommerce' ),
				'expected' => __( 'Huella digital seguida del desafio; luego se paga.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'M2-01-YA',
				'pan'      => '5100270000000023',
				'brand'    => 'Mastercard',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Sin friccion. Estatus Y.', 'powertranz-woocommerce' ),
				'expected' => __( 'Se paga. Compruebe que el ECI registrado es 02, no 05.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'M2-03-YA',
				'pan'      => '5100270000000031',
				'brand'    => 'Mastercard',
				'three_ds' => '2.x',
				'password' => self::CHALLENGE_PASSWORD,
				'scenario' => __( 'Con desafio del emisor. Estatus Y.', 'powertranz-woocommerce' ),
				'expected' => __( 'Desafio y pago correcto.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'M2-04-YA',
				'pan'      => '5100271000000120',
				'brand'    => 'Mastercard',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Sin friccion con huella digital. Estatus Y.', 'powertranz-woocommerce' ),
				'expected' => __( 'Se paga sin intervencion del cliente.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'A2-01-YA',
				'pan'      => '341111000000009',
				'brand'    => 'American Express',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Sin friccion. Estatus Y. CVV de 4 digitos.', 'powertranz-woocommerce' ),
				'expected' => __( 'Se paga. Confirme antes con BAC que su adquirente soporta 3DS2 en Amex.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'A2-02-AA',
				'pan'      => '341111000000011',
				'brand'    => 'American Express',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Sin friccion. Estatus A.', 'powertranz-woocommerce' ),
				'expected' => __( 'Se paga con traslado de responsabilidad.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'A2-03-YA',
				'pan'      => '341112000000001',
				'brand'    => 'American Express',
				'three_ds' => '2.x',
				'password' => self::CHALLENGE_PASSWORD,
				'scenario' => __( 'Con desafio y huella digital. Estatus Y.', 'powertranz-woocommerce' ),
				'expected' => __( 'Desafio y pago correcto.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'A2-04-YA',
				'pan'      => '341111000000037',
				'brand'    => 'American Express',
				'three_ds' => '2.x',
				'password' => self::CHALLENGE_PASSWORD,
				'scenario' => __( 'Con desafio. Estatus Y.', 'powertranz-woocommerce' ),
				'expected' => __( 'Desafio y pago correcto.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'A2-05-YA',
				'pan'      => '341112000008012',
				'brand'    => 'American Express',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Sin friccion con huella digital. Estatus Y.', 'powertranz-woocommerce' ),
				'expected' => __( 'Se paga sin intervencion del cliente.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'VI-01-0A',
				'pan'      => '4333333333332222',
				'brand'    => 'Visa',
				'three_ds' => 'no-3DS',
				'password' => '',
				'scenario' => __( 'Tarjeta sin soporte 3DS 2.x. Devuelve ISO 3D1.', 'powertranz-woocommerce' ),
				'expected' => __( 'Con "Tarjetas sin 3-D Secure" activo se cobra sin autenticacion y la nota avisa de que no hay traslado de responsabilidad. Si lo desactiva, el pedido debe quedar fallido.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'MC-01-0A',
				'pan'      => '5333333333332222',
				'brand'    => 'Mastercard',
				'three_ds' => 'no-3DS',
				'password' => '',
				'scenario' => __( 'Tarjeta sin soporte 3DS 2.x.', 'powertranz-woocommerce' ),
				'expected' => __( 'Mismo comportamiento que la Visa sin 3DS.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'AX-01-0A',
				'pan'      => '343333333333335',
				'brand'    => 'American Express',
				'three_ds' => 'no-3DS',
				'password' => '',
				'scenario' => __( 'Amex sin soporte 3DS.', 'powertranz-woocommerce' ),
				'expected' => __( 'Se cobra sin autenticacion.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'DS-01-0A',
				'pan'      => '6011111111111111',
				'brand'    => 'Discover',
				'three_ds' => 'no-3DS',
				'password' => '',
				'scenario' => __( 'Discover aprobada.', 'powertranz-woocommerce' ),
				'expected' => __( 'Recuerde anadir Discover en "Tarjetas aceptadas" o se rechazara antes de salir del navegador.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'JC-01-0A',
				'pan'      => '3528111111111108',
				'brand'    => 'JCB',
				'three_ds' => 'no-3DS',
				'password' => '',
				'scenario' => __( 'JCB aprobada.', 'powertranz-woocommerce' ),
				'expected' => __( 'Requiere JCB en "Tarjetas aceptadas".', 'powertranz-woocommerce' ),
			),
		);
	}

	/**
	 * Tarjetas cuya autorizacion se rechaza.
	 *
	 * @return array
	 */
	public static function get_declined() {
		return array(
			array(
				'case'     => 'V2-01-ND',
				'pan'      => '4012000000020121',
				'brand'    => 'Visa',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Sin friccion. Estatus N: no autenticado. Codigo de respuesta 12.', 'powertranz-woocommerce' ),
				'expected' => __( 'El pedido debe quedar Fallido y el modulo NO debe llamar a /spi/payment. Verifiquelo en el historial de transacciones.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'M2-01-ND',
				'pan'      => '5100270000000098',
				'brand'    => 'Mastercard',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Sin friccion. Estatus N.', 'powertranz-woocommerce' ),
				'expected' => __( 'Pedido fallido sin llamada de finalizacion.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'M2-02-ND',
				'pan'      => '5100270000000056',
				'brand'    => 'Mastercard',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Con desafio. Estatus N tras el desafio.', 'powertranz-woocommerce' ),
				'expected' => __( 'Se muestra el desafio y despues el pedido queda fallido.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'M2-02-RA',
				'pan'      => '5100270000000072',
				'brand'    => 'Mastercard',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Sin friccion. Estatus R: el emisor rechaza la autenticacion.', 'powertranz-woocommerce' ),
				'expected' => __( 'Pedido fallido con el mensaje de rechazo del emisor.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'M2-03-UD',
				'pan'      => '5555666666662222',
				'brand'    => 'Mastercard',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Estatus U: autenticacion no disponible. ISO 05.', 'powertranz-woocommerce' ),
				'expected' => __( 'Con la politica por defecto no se finaliza el cobro. Con la politica "Intentar siempre" se llama a /spi/payment y el emisor devuelve 05.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'V2-02-AD',
				'pan'      => '4666666666662222',
				'brand'    => 'Visa',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Estatus A pero el emisor rechaza: ISO 05 y respuesta de CVV = N.', 'powertranz-woocommerce' ),
				'expected' => __( 'Caso clave: la autenticacion tiene exito y la autorizacion falla. El pedido queda fallido y el resumen muestra CVV: N.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'V2-03-AD',
				'pan'      => '4111111111119999',
				'brand'    => 'Visa',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Estatus A. ISO 98: error del host procesador.', 'powertranz-woocommerce' ),
				'expected' => __( 'Pedido fallido con mensaje de error temporal, no de rechazo de tarjeta.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'M2-04-AD',
				'pan'      => '5111111111113333',
				'brand'    => 'Mastercard',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Estatus A. ISO 05.', 'powertranz-woocommerce' ),
				'expected' => __( 'Pedido fallido por rechazo del emisor.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'V2-04-YD',
				'pan'      => '4111111111110000',
				'brand'    => 'Visa',
				'three_ds' => '2.x',
				'password' => self::CHALLENGE_PASSWORD,
				'scenario' => __( 'Con desafio. Estatus Y. ISO 91: emisor no disponible.', 'powertranz-woocommerce' ),
				'expected' => __( 'El cliente supera el desafio y aun asi el pago falla. Compruebe que el mensaje invita a reintentar mas tarde.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'M2-05-YD',
				'pan'      => '5111111111110000',
				'brand'    => 'Mastercard',
				'three_ds' => '2.x',
				'password' => self::CHALLENGE_PASSWORD,
				'scenario' => __( 'Con desafio. Estatus Y. ISO 91.', 'powertranz-woocommerce' ),
				'expected' => __( 'Igual que el caso anterior en Mastercard.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'A2-01-ND',
				'pan'      => '341111000000029',
				'brand'    => 'American Express',
				'three_ds' => '2.x',
				'password' => '',
				'scenario' => __( 'Sin friccion. Estatus N.', 'powertranz-woocommerce' ),
				'expected' => __( 'Pedido fallido sin llamada de finalizacion.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'DS-01-0D',
				'pan'      => '6011111111111152',
				'brand'    => 'Discover',
				'three_ds' => 'no-3DS',
				'password' => '',
				'scenario' => __( 'Discover rechazada.', 'powertranz-woocommerce' ),
				'expected' => __( 'Pedido fallido.', 'powertranz-woocommerce' ),
			),
			array(
				'case'     => 'JC-01-0D',
				'pan'      => '3528111111111157',
				'brand'    => 'JCB',
				'three_ds' => 'no-3DS',
				'password' => '',
				'scenario' => __( 'JCB rechazada.', 'powertranz-woocommerce' ),
				'expected' => __( 'Pedido fallido.', 'powertranz-woocommerce' ),
			),
		);
	}

	/**
	 * Subconjunto reducido para la ayuda del checkout.
	 *
	 * @return array
	 */
	public static function get_checkout_shortlist() {
		$wanted = array(
			'4012000000020071',
			'4012000000020006',
			'4012010000020070',
			'5100270000000023',
			'4333333333332222',
			'4012000000020121',
			'4666666666662222',
		);

		$out = array();

		foreach ( array_merge( self::get_approved(), self::get_declined() ) as $card ) {
			if ( in_array( $card['pan'], $wanted, true ) ) {
				$out[] = $card;
			}
		}

		return $out;
	}

	/**
	 * Notas comunes a todas las pruebas.
	 *
	 * @return array
	 */
	public static function get_notes() {
		return array(
			sprintf(
				/* translators: %s: contrasena del desafio */
				__( 'Cuando aparezca el desafio del emisor, la contrasena es %s.', 'powertranz-woocommerce' ),
				'<code>' . self::CHALLENGE_PASSWORD . '</code>'
			),
			__( 'Use cualquier fecha de caducidad futura y un CVV de 3 digitos (4 en American Express). Si el entorno de pruebas rechazara la caducidad, pida a BAC el valor exacto que espera.', 'powertranz-woocommerce' ),
			__( 'Varias de estas tarjetas no cumplen el digito de control de Luhn. Por eso el modulo no aplica esa validacion en el entorno de pruebas; en produccion si la aplica.', 'powertranz-woocommerce' ),
			__( 'La marca de cada tarjeta debe estar marcada en "Tarjetas aceptadas" o el modulo la rechazara antes de llamar a PowerTranz.', 'powertranz-woocommerce' ),
			__( 'Para probar cuotas, use estas mismas tarjetas con el plan seleccionado en el checkout: lo que cambia es el PowerTranz Id con el que se transacciona, no la tarjeta.', 'powertranz-woocommerce' ),
			__( 'Apple Pay no se prueba con estas tarjetas: necesita una cuenta Sandbox Tester de Apple con una tarjeta de prueba anadida al Wallet, en un dispositivo Apple real.', 'powertranz-woocommerce' ),
			__( 'Confirme con BAC si su adquirente soporta autenticacion 3DS2 en American Express: algunos solo la tienen para Visa y Mastercard.', 'powertranz-woocommerce' ),
		);
	}

	/**
	 * Tabla de tarjetas para la pantalla de ajustes.
	 *
	 * @param array  $cards Tarjetas.
	 * @param string $title Titulo.
	 */
	protected static function render_table( $cards, $title ) {
		echo '<h4>' . esc_html( $title ) . '</h4>';
		echo '<table class="widefat striped powertranz-test-cards"><thead><tr>';
		echo '<th>' . esc_html__( 'Caso', 'powertranz-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Tarjeta', 'powertranz-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Marca', 'powertranz-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( '3DS', 'powertranz-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Clave', 'powertranz-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Escenario', 'powertranz-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Que debe ocurrir', 'powertranz-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $cards as $card ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $card['case'] ) . '</code></td>';
			echo '<td><code class="powertranz-pan">' . esc_html( $card['pan'] ) . '</code></td>';
			echo '<td>' . esc_html( $card['brand'] ) . '</td>';
			echo '<td>' . esc_html( $card['three_ds'] ) . '</td>';
			echo '<td>' . ( $card['password'] ? '<code>' . esc_html( $card['password'] ) . '</code>' : '&mdash;' ) . '</td>';
			echo '<td>' . esc_html( $card['scenario'] ) . '</td>';
			echo '<td>' . esc_html( $card['expected'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Panel completo para la pantalla de ajustes.
	 */
	public static function render_admin_panel() {
		echo '<details class="powertranz-test-cards-panel" open>';
		echo '<summary><strong>' . esc_html__( 'Tarjetas y casos de prueba', 'powertranz-woocommerce' ) . '</strong> &mdash; ';
		echo esc_html__( 'entorno de pruebas activo', 'powertranz-woocommerce' ) . '</summary>';

		echo '<ul class="powertranz-test-notes">';
		foreach ( self::get_notes() as $note ) {
			echo '<li>' . wp_kses( $note, array( 'code' => array() ) ) . '</li>';
		}
		echo '</ul>';

		self::render_table( self::get_approved(), __( 'Autorizaciones aprobadas', 'powertranz-woocommerce' ) );
		self::render_table( self::get_declined(), __( 'Autorizaciones rechazadas', 'powertranz-woocommerce' ) );

		echo '</details>';
	}
}
