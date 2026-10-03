<?php
/**
 * Plugin Name: Límite de pedidos por IP o cuenta
 * Description: Frena a quien crea pedidos en serie para agotar el inventario. Cuenta los pedidos reales ya creados desde la misma IP (o la misma cuenta) y, pasado un umbral holgado, no deja crear otro por un rato.
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
 * Cubre las puertas por las que nace un pedido de cliente:
 *   1. Checkout de bloques (Store API POST /wc/store/v1/checkout): responde 429.
 *   2. Checkout clásico (shortcode).
 *   3. Rutas REST propias que creen pedidos, si las declaras con el filtro
 *      `lm_limite_pedidos_rutas_rest` (por defecto ninguna). Ejemplo:
 *        add_filter( 'lm_limite_pedidos_rutas_rest', function ( $r ) { $r[] = '/mi-plugin/v1/pedido'; return $r; } );
 *
 * Umbrales (opción `lm_limite_pedidos`, array):
 *   - por_ventana / ventana_min: máx. pedidos creados por IP en la ventana (5 en 10 min).
 *   - impagos_24h: máx. pedidos SIN pagar (pendiente / en espera) por IP en 24 h (8).
 * Apagar del todo:  wp option update lm_limite_pedidos_activo 0
 * Encender:         wp option update lm_limite_pedidos_activo 1
 * Los administradores y gestores de la tienda nunca se limitan.
 * Cada bloqueo suma 1 en la opción `lm_limite_pedidos_bloqueos` (contador por día, sin IPs).
 * Requiere WooCommerce con HPOS (lee la tabla wc_orders).
 */

defined( 'ABSPATH' ) || exit;

function lm_lp_config() {
	$d = array(
		'por_ventana' => 5,
		'ventana_min' => 10,
		'impagos_24h' => 8,
	);
	$o = get_option( 'lm_limite_pedidos', array() );
	return array_merge( $d, is_array( $o ) ? array_intersect_key( $o, $d ) : array() );
}

function lm_lp_activo() {
	return '0' !== (string) get_option( 'lm_limite_pedidos_activo', '1' );
}

/** IP del cliente con el mismo criterio con que WooCommerce la guarda en el pedido. */
function lm_lp_ip() {
	if ( class_exists( 'WC_Geolocation' ) ) {
		return (string) WC_Geolocation::get_ip_address();
	}
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/** Texto final de los mensajes de bloqueo (p. ej. «escríbenos por WhatsApp»). Opción `lm_limite_pedidos_contacto`. */
function lm_lp_contacto() {
	$c = trim( (string) get_option( 'lm_limite_pedidos_contacto', '' ) );
	return '' !== $c ? ' ' . $c : ' ' . __( 'Si necesitas ayuda, contáctanos.', 'woo-tienda-segura' );
}

/**
 * Devuelve un mensaje de error si esta IP/cuenta ya pasó el umbral; '' si puede seguir.
 */
function lm_lp_excedido() {
	if ( ! lm_lp_activo() ) {
		return '';
	}
	if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' ) ) {
		return '';
	}
	global $wpdb;
	$ip  = lm_lp_ip();
	$uid = get_current_user_id();
	if ( '' === $ip && ! $uid ) {
		return '';
	}
	$cfg   = lm_lp_config();
	$tabla = $wpdb->prefix . 'wc_orders';

	// Pedidos de esta IP o de esta cuenta (los borradores del checkout de bloques no cuentan).
	$quien = array();
	$args  = array();
	if ( '' !== $ip ) {
		$quien[] = 'ip_address = %s';
		$args[]  = $ip;
	}
	if ( $uid ) {
		$quien[] = 'customer_id = %d';
		$args[]  = $uid;
	}
	$quien = '(' . implode( ' OR ', $quien ) . ')';
	$base  = "SELECT COUNT(*) FROM {$tabla} WHERE type = 'shop_order' AND status NOT IN ('wc-checkout-draft','auto-draft','trash') AND {$quien}";

	$desde = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $cfg['ventana_min'] ) * MINUTE_IN_SECONDS );
	$n     = (int) $wpdb->get_var( $wpdb->prepare( $base . ' AND date_created_gmt >= %s', array_merge( $args, array( $desde ) ) ) ); // phpcs:ignore
	if ( $n >= (int) $cfg['por_ventana'] ) {
		/* translators: %d: minutos de la ventana. */
		return lm_lp_bloquear( sprintf( __( 'Ya hiciste varios pedidos en los últimos %d minutos. Espera un momento.', 'woo-tienda-segura' ), (int) $cfg['ventana_min'] ) . lm_lp_contacto() );
	}

	$desde = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
	$n     = (int) $wpdb->get_var( $wpdb->prepare( $base . " AND status IN ('wc-pending','wc-on-hold') AND date_created_gmt >= %s", array_merge( $args, array( $desde ) ) ) ); // phpcs:ignore
	if ( $n >= (int) $cfg['impagos_24h'] ) {
		return lm_lp_bloquear( __( 'Tienes varios pedidos pendientes de pago. Termina uno de ellos antes de crear otro.', 'woo-tienda-segura' ) . lm_lp_contacto() );
	}
	return '';
}

function lm_lp_bloquear( $msg ) {
	$c   = get_option( 'lm_limite_pedidos_bloqueos', array() );
	$c   = is_array( $c ) ? $c : array();
	$dia = gmdate( 'Y-m-d' );
	$c[ $dia ] = ( $c[ $dia ] ?? 0 ) + 1;
	$c   = array_slice( $c, -30, null, true );
	update_option( 'lm_limite_pedidos_bloqueos', $c, false );
	return $msg;
}

/* 1) Checkout de bloques: se corta ANTES de procesar el pago. */
add_action( 'woocommerce_store_api_checkout_update_order_from_request', function ( $order, $request ) {
	$msg = lm_lp_excedido();
	if ( '' !== $msg && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'lm_limite_pedidos', $msg, 429 );
	}
}, 5, 2 );

/* 2) Checkout clásico. */
add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	$msg = lm_lp_excedido();
	if ( '' !== $msg ) {
		$errors->add( 'lm_limite_pedidos', $msg );
	}
}, 5, 2 );

/* 3) Rutas REST propias que crean pedidos (declaradas por filtro; ninguna por defecto). */
add_filter( 'rest_request_before_callbacks', function ( $response, $handler, $request ) {
	if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request || 'POST' !== $request->get_method() ) {
		return $response;
	}
	$rutas = (array) apply_filters( 'lm_limite_pedidos_rutas_rest', array() );
	if ( ! $rutas || ! in_array( $request->get_route(), $rutas, true ) ) {
		return $response;
	}
	$msg = lm_lp_excedido();
	if ( '' !== $msg ) {
		return new WP_Error( 'lm_limite_pedidos', $msg, array( 'status' => 429 ) );
	}
	return $response;
}, 5, 3 );
