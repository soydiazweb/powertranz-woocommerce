<?php
/**
 * Logos de marcas, sellos del adquirente y de la pasarela, y contactos de
 * soporte en el formulario de pago.
 *
 * First Atlantic Commerce exige mostrar en el formulario de pago las marcas
 * aceptadas y el aval del adquirente y de la pasarela, ademas de ofrecer al
 * cliente una via de contacto.
 *
 * @package PowerTranz_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PowerTranz_Branding
 */
class WC_PowerTranz_Branding {

	/**
	 * URL del logo de una marca de tarjeta.
	 *
	 * @param string $brand Slug de marca.
	 * @return string
	 */
	public static function get_brand_logo_url( $brand ) {
		$brand = sanitize_key( $brand );
		$file  = 'assets/images/cards/' . $brand . '.svg';

		$url = file_exists( WC_POWERTRANZ_PATH . $file )
			? WC_POWERTRANZ_URL . $file
			: '';

		/**
		 * Permite sustituir el logo de una marca por el archivo oficial.
		 *
		 * @param string $url   URL del logo.
		 * @param string $brand Slug de marca.
		 */
		return (string) apply_filters( 'powertranz_brand_logo_url', $url, $brand );
	}

	/**
	 * URL de un sello incluido en el modulo.
	 *
	 * @param string $seal bac-credomatic|first-atlantic-commerce|threeds.
	 * @return string
	 */
	public static function get_seal_url( $seal ) {
		$file = 'assets/images/seals/' . sanitize_file_name( $seal ) . '.svg';

		return file_exists( WC_POWERTRANZ_PATH . $file )
			? WC_POWERTRANZ_URL . $file
			: '';
	}

	/**
	 * Banda unica de logos suministrada por el adquirente.
	 *
	 * BAC y FAC entregan al comercio una sola imagen con las marcas y los
	 * avales. Cuando esta configurada sustituye a los logos individuales y a
	 * los sellos, que son aproximaciones.
	 *
	 * @param WC_PowerTranz_Gateway|null $gateway Pasarela.
	 * @param string                     $variant Clase extra ('footer').
	 * @return string HTML.
	 */
	public static function render_logo_strip( $gateway, $variant = '' ) {
		if ( ! $gateway || 'yes' !== $gateway->get_option( 'show_trust_logos', 'yes' ) ) {
			return '';
		}

		$url = trim( (string) $gateway->get_option( 'logo_strip_url', '' ) );

		if ( '' === $url ) {
			return '';
		}

		$width = absint( $gateway->get_option( 'logo_strip_max_width', 320 ) );
		$class = 'powertranz-logo-strip' . ( $variant ? ' powertranz-logo-strip--' . sanitize_html_class( $variant ) : '' );

		return '<div class="' . esc_attr( $class ) . '">'
			. '<img src="' . esc_url( $url ) . '"'
			. ' alt="' . esc_attr__( 'Tarjetas aceptadas, procesado por BAC Credomatic y First Atlantic Commerce', 'powertranz-woocommerce' ) . '"'
			. ( $width ? ' style="max-width:' . $width . 'px"' : '' )
			. ' loading="lazy" decoding="async" />'
			. '</div>';
	}

	/**
	 * Indica si hay una banda de logos configurada.
	 *
	 * @param WC_PowerTranz_Gateway|null $gateway Pasarela.
	 * @return bool
	 */
	public static function has_logo_strip( $gateway ) {
		return $gateway && '' !== trim( (string) $gateway->get_option( 'logo_strip_url', '' ) );
	}

	/**
	 * Fila de logos de las marcas aceptadas.
	 *
	 * @param array                      $accepted Slugs de marcas aceptadas.
	 * @param WC_PowerTranz_Gateway|null $gateway  Pasarela, para respetar la banda.
	 * @return string HTML.
	 */
	public static function render_accepted_cards( $accepted, $gateway = null ) {
		if ( self::has_logo_strip( $gateway ) ) {
			return self::render_logo_strip( $gateway );
		}

		if ( $gateway && 'yes' !== $gateway->get_option( 'show_trust_logos', 'yes' ) ) {
			return '';
		}

		if ( empty( $accepted ) ) {
			return '';
		}

		$labels = WC_PowerTranz_Helper::get_brand_labels();
		$items  = '';

		foreach ( $accepted as $brand ) {
			if ( ! isset( $labels[ $brand ] ) ) {
				continue;
			}

			$url   = self::get_brand_logo_url( $brand );
			$label = $labels[ $brand ];

			$items .= '<li class="powertranz-brand powertranz-brand--' . esc_attr( $brand ) . '" data-brand="' . esc_attr( $brand ) . '">';

			if ( $url ) {
				$items .= '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $label ) . '" width="52" height="32" loading="lazy" decoding="async" />';
			} else {
				$items .= '<span class="powertranz-brand-text">' . esc_html( $label ) . '</span>';
			}

			$items .= '</li>';
		}

		if ( '' === $items ) {
			return '';
		}

		return '<ul class="powertranz-brands" aria-label="' . esc_attr__( 'Tarjetas aceptadas', 'powertranz-woocommerce' ) . '">' . $items . '</ul>';
	}

	/**
	 * Sellos del adquirente, de la pasarela y de 3-D Secure.
	 *
	 * Los logos de BAC Credomatic y de First Atlantic Commerce incluidos son
	 * aproximaciones utilizables; si su adquirente le entrego los archivos
	 * oficiales, indique su URL en los ajustes.
	 *
	 * @param WC_PowerTranz_Gateway $gateway   Pasarela.
	 * @param bool                  $with_3ds  Incluir el sello de 3-D Secure.
	 * @return string HTML.
	 */
	public static function render_seals( $gateway, $with_3ds = true ) {
		if ( 'yes' !== $gateway->get_option( 'show_trust_logos', 'yes' ) ) {
			return '';
		}

		// La banda ya incluye los avales del adquirente y de la pasarela.
		if ( self::has_logo_strip( $gateway ) ) {
			return '';
		}

		$acquirer = trim( (string) $gateway->get_option( 'acquirer_logo_url', '' ) );
		$provider = trim( (string) $gateway->get_option( 'gateway_logo_url', '' ) );

		$seals = array(
			array(
				'url'    => $acquirer ? $acquirer : self::get_seal_url( 'bac-credomatic' ),
				'alt'    => __( 'BAC Credomatic', 'powertranz-woocommerce' ),
				'width'  => 132,
				'height' => 32,
			),
			array(
				'url'    => $provider ? $provider : self::get_seal_url( 'first-atlantic-commerce' ),
				'alt'    => __( 'Procesado por First Atlantic Commerce', 'powertranz-woocommerce' ),
				'width'  => 152,
				'height' => 32,
			),
		);

		if ( $with_3ds ) {
			$seals[] = array(
				'url'    => self::get_seal_url( 'threeds' ),
				'alt'    => __( 'Protegido con 3-D Secure EMV 2.x', 'powertranz-woocommerce' ),
				'width'  => 92,
				'height' => 32,
			);
		}

		/**
		 * Permite anadir, quitar o reordenar los sellos del formulario de pago.
		 *
		 * @param array                 $seals   Sellos.
		 * @param WC_PowerTranz_Gateway $gateway Pasarela.
		 */
		$seals = apply_filters( 'powertranz_payment_seals', $seals, $gateway );

		$items = '';
		foreach ( $seals as $seal ) {
			if ( empty( $seal['url'] ) ) {
				continue;
			}
			$items .= '<li><img src="' . esc_url( $seal['url'] ) . '" alt="' . esc_attr( $seal['alt'] ) . '"'
				. ' width="' . absint( $seal['width'] ) . '" height="' . absint( $seal['height'] ) . '"'
				. ' loading="lazy" decoding="async" /></li>';
		}

		if ( '' === $items ) {
			return '';
		}

		return '<ul class="powertranz-seals" aria-label="' . esc_attr__( 'Pagos procesados y avalados por', 'powertranz-woocommerce' ) . '">' . $items . '</ul>';
	}

	/**
	 * Bloque de contacto para el cliente.
	 *
	 * @param WC_PowerTranz_Gateway $gateway Pasarela.
	 * @return string HTML.
	 */
	public static function render_support_contact( $gateway ) {
		$phone = trim( (string) $gateway->get_option( 'support_phone', '' ) );
		$email = trim( (string) $gateway->get_option( 'support_email', '' ) );
		$hours = trim( (string) $gateway->get_option( 'support_hours', '' ) );

		$parts = array();

		if ( '' !== $phone ) {
			$digits = preg_replace( '/[^0-9+]/', '', $phone );
			if ( '' !== $digits ) {
				$parts[] = '<a href="tel:' . esc_attr( $digits ) . '">' . esc_html( $phone ) . '</a>';
			}
		}
		if ( '' !== $email && is_email( $email ) ) {
			$parts[] = '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
		}

		// Si nada quedo utilizable no se imprime un bloque vacio.
		if ( ! $parts ) {
			return '';
		}

		$html = '<p class="powertranz-support">'
			. esc_html__( 'Dudas con su pago:', 'powertranz-woocommerce' ) . ' '
			. implode( ' &middot; ', $parts );

		if ( '' !== $hours ) {
			$html .= ' <span class="powertranz-support-hours">(' . esc_html( $hours ) . ')</span>';
		}

		$html .= '</p>';

		return $html;
	}

	/**
	 * Datos de marca para el checkout por bloques.
	 *
	 * @param WC_PowerTranz_Gateway $gateway  Pasarela.
	 * @param bool                  $with_3ds Incluir el sello de 3-D Secure.
	 * @return array
	 */
	public static function get_frontend_data( $gateway, $with_3ds = true ) {
		if ( 'yes' !== $gateway->get_option( 'show_trust_logos', 'yes' ) ) {
			return array(
				'logo_base_url' => '',
				'seals'         => array(),
				'strip_html'    => '',
				'support_html'  => self::render_support_contact( $gateway ),
			);
		}

		$acquirer = trim( (string) $gateway->get_option( 'acquirer_logo_url', '' ) );
		$provider = trim( (string) $gateway->get_option( 'gateway_logo_url', '' ) );

		$seals = array(
			array(
				'url' => $acquirer ? $acquirer : self::get_seal_url( 'bac-credomatic' ),
				'alt' => __( 'BAC Credomatic', 'powertranz-woocommerce' ),
			),
			array(
				'url' => $provider ? $provider : self::get_seal_url( 'first-atlantic-commerce' ),
				'alt' => __( 'Procesado por First Atlantic Commerce', 'powertranz-woocommerce' ),
			),
		);

		if ( $with_3ds ) {
			$seals[] = array(
				'url' => self::get_seal_url( 'threeds' ),
				'alt' => __( 'Protegido con 3-D Secure EMV 2.x', 'powertranz-woocommerce' ),
			);
		}

		if ( self::has_logo_strip( $gateway ) ) {
			return array(
				'logo_base_url' => '',
				'seals'         => array(),
				'strip_html'    => self::render_logo_strip( $gateway ),
				'support_html'  => self::render_support_contact( $gateway ),
			);
		}

		return array(
			'logo_base_url' => WC_POWERTRANZ_URL . 'assets/images/cards/',
			'seals'         => array_values(
				array_filter(
					$seals,
					static function ( $seal ) {
						return ! empty( $seal['url'] );
					}
				)
			),
			'support_html'  => self::render_support_contact( $gateway ),
		);
	}

	/**
	 * Campos de ajustes compartidos por las dos pasarelas.
	 *
	 * @return array
	 */
	public static function get_settings_fields() {
		return array(
			'branding_section' => array(
				'title'       => __( 'Sellos y contacto (requisitos de FAC)', 'powertranz-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'First Atlantic Commerce exige mostrar en el formulario de pago las marcas aceptadas, el aval del adquirente y de la pasarela, y una via de contacto para el cliente.', 'powertranz-woocommerce' ),
			),
			'show_trust_logos' => array(
				'title'   => __( 'Mostrar sellos', 'powertranz-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Mostrar los logos de las marcas, de BAC Credomatic, de First Atlantic Commerce y de 3-D Secure', 'powertranz-woocommerce' ),
				'default' => 'yes',
			),
			'logo_strip_url' => array(
				'title'       => __( 'Banda de logos del adquirente', 'powertranz-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'https://...',
				'description' => __( 'URL de la imagen unica con las marcas y los avales que le entrego BAC o FAC. Es la opcion recomendada: si la indica, sustituye a los logos individuales y a los sellos incluidos en el modulo, que son aproximaciones. Subala a la Biblioteca de medios y pegue aqui su URL.', 'powertranz-woocommerce' ),
			),
			'logo_strip_max_width' => array(
				'title'             => __( 'Ancho maximo de la banda (px)', 'powertranz-woocommerce' ),
				'type'              => 'number',
				'default'           => '320',
				'custom_attributes' => array(
					'min' => '120',
					'max' => '800',
				),
			),
			'acquirer_logo_url' => array(
				'title'       => __( 'Logo del adquirente', 'powertranz-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'https://...',
				'description' => __( 'URL del logo oficial de BAC Credomatic. Si se deja vacio se usa el incluido en el modulo, que es una aproximacion: sustituyalo por el archivo que le entrego su adquirente.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'gateway_logo_url' => array(
				'title'       => __( 'Logo de la pasarela', 'powertranz-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'https://...',
				'description' => __( 'URL del logo oficial "Powered by First Atlantic Commerce". Si se deja vacio se usa el incluido en el modulo.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'support_phone' => array(
				'title'       => __( 'Telefono de atencion', 'powertranz-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Se muestra en el formulario de pago y en la pagina de verificacion 3-D Secure.', 'powertranz-woocommerce' ),
				'desc_tip'    => true,
			),
			'support_email' => array(
				'title'   => __( 'Correo de atencion', 'powertranz-woocommerce' ),
				'type'    => 'email',
				'default' => '',
			),
			'support_hours' => array(
				'title'       => __( 'Horario de atencion', 'powertranz-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => __( 'Lunes a viernes, 8:00 a 17:00', 'powertranz-woocommerce' ),
			),
		);
	}
}
