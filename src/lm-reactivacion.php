<?php
/**
 * Plugin Name: Reactivación de clientes (con consentimiento)
 * Description: Campaña manual «te extrañamos» + pedir reseña a clientes que no compran hace más de 60 días. Solo a quien dio consentimiento y no está de baja; no repite en 180 días. Requiere lm-comunicaciones.php y WooCommerce con HPOS.
 * Version: 1.0.0
 * Author: Andrés Valencia Tobón
 * Author URI: https://github.com/quijotevitruvio
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woo-tienda-segura
 * Requires Plugins: woocommerce
 *
 * LinkedIn: https://www.linkedin.com/in/andr%C3%A9s-valencia-tob%C3%B3n/
 * Donate link: https://paypal.me/bibliotecologo
 *
 * NO envía sola:
 *   wp eval "print_r(lm_reactivacion_send(true));"    solo cuenta
 *   wp eval "print_r(lm_reactivacion_send(false));"   envía de verdad (2.º parámetro opcional: límite)
 * Ajustes: lm_reactivacion_dias (60) días sin comprar; lm_reactivacion_repetir_dias (180) días sin repetir.
 */

defined( 'ABSPATH' ) || exit;

/**
 * @param bool $dry   true = no envía (solo cuenta). false = envía de verdad.
 * @param int  $limit límite opcional de envíos (para pruebas).
 */
function lm_reactivacion_send( $dry = true, $limit = 0 ) {
	// Sin el módulo común (consentimiento + bajas + registro) no se envía nada.
	if ( ! function_exists( 'lm_com_puede_escribir' ) ) {
		return array( 'error' => 'falta mu-plugins/lm-comunicaciones.php: no se envía nada' );
	}
	global $wpdb;
	$dias    = max( 1, (int) get_option( 'lm_reactivacion_dias', 60 ) );
	$repetir = max( 1, (int) get_option( 'lm_reactivacion_repetir_dias', 180 ) );
	// Segmento: 1 fila por email, último pedido completado hace más de $dias días.
	$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
		"SELECT billing_email AS email,
		        SUBSTRING_INDEX(GROUP_CONCAT(first_name ORDER BY date_created_gmt DESC), ',', 1) AS name,
		        MAX(date_created_gmt) AS last_order
		 FROM {$wpdb->prefix}wc_orders o
		 LEFT JOIN {$wpdb->prefix}wc_order_addresses a
		        ON a.order_id = o.id AND a.address_type = 'billing'
		 WHERE o.status = 'wc-completed' AND o.billing_email <> ''
		 GROUP BY billing_email
		 HAVING last_order < %s",
		gmdate( 'Y-m-d H:i:s', time() - $dias * DAY_IN_SECONDS )
	) );

	$sent    = 0;
	$skipped = 0;
	foreach ( $rows as $r ) {
		$email = strtolower( trim( $r->email ) );
		// Consentimiento, baja y no repetir.
		if ( ! is_email( $email ) || ! lm_com_puede_escribir( $email, 'reactivacion', $repetir ) ) {
			$skipped++;
			continue;
		}
		if ( $limit && $sent >= $limit ) {
			break;
		}
		if ( $dry ) {
			$sent++;
			continue;
		}
		if ( lm_reactivacion_email( $email, $r->name ) ) {
			lm_com_registrar_envio( $email, 'reactivacion' );
		}
		$sent++;
		if ( 0 === $sent % 25 ) {
			usleep( 400000 ); // respiro para no saturar el servidor de correo.
		}
	}
	return array( 'total_segmento' => count( $rows ), ( $dry ? 'dry_contados' : 'enviados' ) => $sent, 'omitidos' => $skipped );
}

/** Email de reactivación + reseña para un cliente. Textos editables con los filtros lm_reactivacion_asunto y lm_reactivacion_cuerpo. */
function lm_reactivacion_email( $email, $name ) {
	$name   = $name ? ' ' . esc_html( trim( $name ) ) : '';
	$tienda = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
	$resena = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : home_url( '/' );
	$color  = esc_attr( lm_com_color() );
	$sitio  = lm_com_remitente();

	$body = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#222;">'
		/* translators: %s: nombre del sitio. */
		. '<h2 style="color:' . $color . ';">' . esc_html( sprintf( __( 'Te extrañamos en %s', 'woo-tienda-segura' ), $sitio ) ) . $name . '</h2>'
		. '<p>' . esc_html__( 'Hace un tiempo que no nos vemos y queríamos saludarte.', 'woo-tienda-segura' ) . '</p>'
		. '<p style="text-align:center;margin:22px 0;"><a href="' . esc_url( $tienda ) . '" style="background:' . $color . ';color:#fff;padding:13px 28px;border-radius:999px;text-decoration:none;font-weight:700;">' . esc_html__( 'Ver novedades →', 'woo-tienda-segura' ) . '</a></p>'
		. '<hr style="border:none;border-top:1px solid #eee;margin:22px 0;">'
		. '<p><strong>' . esc_html__( '¿Qué te pareció tu última compra?', 'woo-tienda-segura' ) . '</strong> ' . esc_html__( 'Tu reseña ayuda a otros clientes.', 'woo-tienda-segura' ) . '</p>'
		. '<p style="text-align:center;margin:18px 0;"><a href="' . esc_url( $resena ) . '" style="color:' . $color . ';font-weight:700;text-decoration:none;">' . esc_html__( '⭐ Dejar mi reseña →', 'woo-tienda-segura' ) . '</a></p>'
		. lm_com_nota_html()
		. lm_com_firma_html()
		/* translators: %s: nombre del sitio. */
		. lm_com_pie_html( $email, sprintf( __( 'porque compraste en %s y nos autorizaste a enviarte novedades', 'woo-tienda-segura' ), $sitio ) )
		. '</div>';
	$body = (string) apply_filters( 'lm_reactivacion_cuerpo', $body, $email );

	$mailer = WC()->mailer();
	/* translators: %s: nombre del sitio. */
	$subject = (string) apply_filters( 'lm_reactivacion_asunto', sprintf( __( 'Te extrañamos en %s', 'woo-tienda-segura' ), $sitio ), $email );
	return (bool) $mailer->send( $email, $subject, $mailer->wrap_message( $subject, $body ), "Content-Type: text/html\r\n" . lm_com_cabeceras_baja( $email ) );
}
