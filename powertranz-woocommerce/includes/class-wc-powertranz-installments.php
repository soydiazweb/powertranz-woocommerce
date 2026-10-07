<?php
/**
 * Cuotas BAC Credomatic.
 *
 * PowerTranz no expone un campo de cuotas en su API: el adquirente (BAC) asigna
 * un PowerTranz Id / password distinto a cada plan de cuotas, de modo que la
 * transaccion se envia con las credenciales del plan elegido. Esta clase
 * gestiona esa tabla de planes.
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PowerTranz_Installments
 */
class WC_PowerTranz_Installments {

	/**
	 * Pasarela propietaria de los ajustes.
	 *
	 * @var WC_PowerTranz_Gateway
	 */
	protected $gateway;

	/**
	 * Constructor.
	 *
	 * @param WC_PowerTranz_Gateway $gateway Pasarela.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Indica si la funcion de cuotas esta activa.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return 'yes' === $this->gateway->get_option( 'installments_enabled', 'no' );
	}

	/**
	 * Etiqueta del selector en el checkout.
	 *
	 * @return string
	 */
	public function get_field_label() {
		$label = $this->gateway->get_option( 'installments_label' );
		return $label ? $label : __( 'Cuotas', 'powertranz-woocommerce' );
	}

	/**
	 * Etiqueta de la opcion de pago unico.
	 *
	 * @return string
	 */
	public function get_single_payment_label() {
		$label = $this->gateway->get_option( 'installments_single_label' );
		return $label ? $label : __( 'Un solo pago', 'powertranz-woocommerce' );
	}

	/**
	 * Devuelve el plan sintetico de pago unico, que usa las credenciales
	 * principales de la pasarela.
	 *
	 * @return array
	 */
	public function get_single_payment_plan() {
		return array(
			'id'            => 'single',
			'cuotas'        => 1,
			'label'         => $this->get_single_payment_label(),
			'test_id'       => '',
			'test_password' => '',
			'live_id'       => '',
			'live_password' => '',
			'min_amount'    => 0.0,
			'bins'          => array(),
			'extra'         => array(),
			'enabled'       => true,
		);
	}

	/**
	 * Planes configurados (sin el pago unico).
	 *
	 * @return array
	 */
	public function get_plans() {
		$raw = $this->gateway->get_option( 'installment_plans', array() );

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$plans = array();
		foreach ( $raw as $row ) {
			$plan = $this->normalize_plan( $row );
			if ( $plan ) {
				$plans[ $plan['id'] ] = $plan;
			}
		}

		uasort(
			$plans,
			static function ( $a, $b ) {
				return $a['cuotas'] <=> $b['cuotas'];
			}
		);

		return $plans;
	}

	/**
	 * Normaliza una fila de la tabla de planes.
	 *
	 * @param array $row Fila.
	 * @return array|null
	 */
	protected function normalize_plan( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$cuotas = isset( $row['cuotas'] ) ? absint( $row['cuotas'] ) : 0;
		if ( $cuotas < 2 ) {
			return null;
		}

		$bins = array();
		if ( ! empty( $row['bins'] ) ) {
			foreach ( preg_split( '/[\s,;]+/', (string) $row['bins'] ) as $bin ) {
				$bin = preg_replace( '/\D/', '', (string) $bin );
				if ( '' !== $bin ) {
					$bins[] = $bin;
				}
			}
		}

		$extra = array();
		if ( ! empty( $row['extra'] ) ) {
			$decoded = json_decode( (string) $row['extra'], true );
			if ( is_array( $decoded ) ) {
				$extra = $decoded;
			}
		}

		$label = isset( $row['label'] ) ? trim( (string) $row['label'] ) : '';
		if ( '' === $label ) {
			/* translators: %d: numero de cuotas */
			$label = sprintf( _n( '%d cuota', '%d cuotas', $cuotas, 'powertranz-woocommerce' ), $cuotas );
		}

		return array(
			'id'            => 'plan_' . $cuotas,
			'cuotas'        => $cuotas,
			'label'         => $label,
			'test_id'       => isset( $row['test_id'] ) ? trim( (string) $row['test_id'] ) : '',
			'test_password' => isset( $row['test_password'] ) ? trim( (string) $row['test_password'] ) : '',
			'live_id'       => isset( $row['live_id'] ) ? trim( (string) $row['live_id'] ) : '',
			'live_password' => isset( $row['live_password'] ) ? trim( (string) $row['live_password'] ) : '',
			'min_amount'    => isset( $row['min_amount'] ) ? (float) wc_format_decimal( $row['min_amount'] ) : 0.0,
			'bins'          => $bins,
			'extra'         => $extra,
			'enabled'       => ! isset( $row['enabled'] ) || 'no' !== $row['enabled'],
		);
	}

	/**
	 * Planes aplicables a un importe y, opcionalmente, a un PAN.
	 *
	 * Incluye siempre el plan de pago unico en primera posicion.
	 *
	 * @param float  $amount Importe del pedido.
	 * @param string $pan    Numero de tarjeta (opcional, para filtrar por BIN).
	 * @return array
	 */
	public function get_available_plans( $amount, $pan = '' ) {
		$available = array( 'single' => $this->get_single_payment_plan() );

		if ( ! $this->is_enabled() ) {
			return $available;
		}

		$amount = (float) $amount;
		$pan    = preg_replace( '/\D/', '', (string) $pan );

		foreach ( $this->get_plans() as $id => $plan ) {
			if ( ! $plan['enabled'] ) {
				continue;
			}
			if ( $plan['min_amount'] > 0 && $amount < $plan['min_amount'] ) {
				continue;
			}
			if ( ! $this->plan_accepts_pan( $plan, $pan ) ) {
				continue;
			}
			$available[ $id ] = $plan;
		}

		/**
		 * Permite filtrar los planes de cuotas ofrecidos.
		 *
		 * @param array  $available Planes disponibles.
		 * @param float  $amount    Importe.
		 * @param string $pan       PAN (puede estar vacio).
		 */
		return apply_filters( 'powertranz_available_installment_plans', $available, $amount, $pan );
	}

	/**
	 * Comprueba si un plan acepta un PAN concreto por su BIN.
	 *
	 * @param array  $plan Plan.
	 * @param string $pan  PAN (solo digitos) o cadena vacia.
	 * @return bool
	 */
	public function plan_accepts_pan( $plan, $pan ) {
		if ( empty( $plan['bins'] ) ) {
			return true;
		}
		if ( '' === $pan ) {
			// Sin PAN todavia no se puede descartar: se valida en el servidor.
			return true;
		}

		foreach ( $plan['bins'] as $bin ) {
			if ( 0 === strpos( $pan, $bin ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Devuelve un plan por id.
	 *
	 * @param string $id Id de plan.
	 * @return array|null
	 */
	public function get_plan( $id ) {
		$id = (string) $id;

		if ( '' === $id || 'single' === $id ) {
			return $this->get_single_payment_plan();
		}

		$plans = $this->get_plans();
		return $plans[ $id ] ?? null;
	}

	/**
	 * Valida y devuelve el plan solicitado en el checkout.
	 *
	 * @param string $id     Id de plan recibido.
	 * @param float  $amount Importe del pedido.
	 * @param string $pan    PAN.
	 * @return array Plan valido.
	 * @throws Exception Si el plan no es aplicable.
	 */
	public function resolve_requested_plan( $id, $amount, $pan ) {
		$id = (string) $id;

		if ( '' === $id || 'single' === $id || ! $this->is_enabled() ) {
			return $this->get_single_payment_plan();
		}

		$plan = $this->get_plan( $id );
		if ( ! $plan ) {
			throw new Exception( esc_html__( 'El plan de cuotas seleccionado no existe.', 'powertranz-woocommerce' ) );
		}
		if ( ! $plan['enabled'] ) {
			throw new Exception( esc_html__( 'El plan de cuotas seleccionado no esta disponible.', 'powertranz-woocommerce' ) );
		}
		if ( $plan['min_amount'] > 0 && (float) $amount < $plan['min_amount'] ) {
			throw new Exception(
				sprintf(
					/* translators: %s: importe minimo formateado */
					esc_html__( 'El plan de cuotas seleccionado requiere un importe minimo de %s.', 'powertranz-woocommerce' ),
					wp_strip_all_tags( wc_price( $plan['min_amount'] ) )
				)
			);
		}
		if ( ! $this->plan_accepts_pan( $plan, preg_replace( '/\D/', '', (string) $pan ) ) ) {
			throw new Exception( esc_html__( 'La tarjeta ingresada no admite el plan de cuotas seleccionado.', 'powertranz-woocommerce' ) );
		}

		return $plan;
	}

	/**
	 * Opciones para un select del checkout.
	 *
	 * @param float  $amount Importe.
	 * @param string $pan    PAN.
	 * @return array id => etiqueta.
	 */
	public function get_select_options( $amount, $pan = '' ) {
		$options = array();

		foreach ( $this->get_available_plans( $amount, $pan ) as $id => $plan ) {
			if ( 'single' === $id || $plan['cuotas'] < 2 ) {
				$options[ $id ] = $plan['label'];
				continue;
			}

			$per_installment = $amount > 0 ? $amount / $plan['cuotas'] : 0;
			$options[ $id ]  = sprintf(
				/* translators: 1: etiqueta del plan, 2: importe por cuota */
				__( '%1$s (aprox. %2$s por cuota)', 'powertranz-woocommerce' ),
				$plan['label'],
				wp_strip_all_tags( wc_price( $per_installment ) )
			);
		}

		return $options;
	}

	/**
	 * Datos de los planes para el frontend (sin credenciales).
	 *
	 * @param float $amount Importe.
	 * @return array
	 */
	public function get_frontend_plans( $amount ) {
		$out = array();

		foreach ( $this->get_available_plans( $amount ) as $id => $plan ) {
			$out[] = array(
				'id'         => $id,
				'cuotas'     => $plan['cuotas'],
				'label'      => $plan['label'],
				'min_amount' => $plan['min_amount'],
				'bins'       => $plan['bins'],
				'per'        => ( $plan['cuotas'] > 1 && $amount > 0 )
					? wp_strip_all_tags( wc_price( $amount / $plan['cuotas'] ) )
					: '',
			);
		}

		return $out;
	}
}
