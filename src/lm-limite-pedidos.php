<?php
/**
 * Plugin Name: Límite de pedidos por IP o cuenta
 * Description: Frena a quien crea pedidos en serie para agotar el inventario. Cuenta los pedidos reales ya creados desde la misma IP (o la misma cuenta) y, pasado un umbral holgado, no deja crear otro por un rato.
 * Version: 1.1.0
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
 *
 * IP real (1.1.0): la IP sale de lm_ip_real() (REMOTE_ADDR; CF-Connecting-IP solo
 * si REMOTE_ADDR es un nodo de Cloudflare), nunca de X-Forwarded-For / X-Real-IP,
 * que cualquiera puede inventar. Esas dos cabeceras se igualan a la IP real para
 * que WooCommerce guarde en el pedido la misma IP con la que se cuenta. Detrás de
 * otro proxy (no Cloudflare), configura el servidor para que reescriba REMOTE_ADDR.
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

/**
 * Rangos oficiales de Cloudflare (https://www.cloudflare.com/ips-v4 y /ips-v6,
 * copiados el 2026-10-03). Si Cloudflare añade rangos, actualizar esta lista;
 * mientras tanto, una petición desde un rango nuevo cuenta con la IP del nodo
 * de Cloudflare (más estricto, nunca más permisivo).
 */
function lm_ip_rangos_cloudflare() {
	return array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
		'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
		'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
		'2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	);
}

/** ¿Está $ip dentro del rango CIDR $cidr? (IPv4 e IPv6, sin dependencias). */
function lm_ip_en_rango( $ip, $cidr ) {
	list( $red, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, null );
	$a = @inet_pton( $ip );  // phpcs:ignore
	$b = @inet_pton( $red ); // phpcs:ignore
	if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
		return false;
	}
	$bits  = null === $bits ? strlen( $a ) * 8 : (int) $bits;
	$bytes = intdiv( $bits, 8 );
	if ( 0 !== substr_compare( $a, $b, 0, $bytes ) ) {
		return false;
	}
	$resto = $bits % 8;
	if ( 0 === $resto ) {
		return true;
	}
	$mask = ( 0xFF << ( 8 - $resto ) ) & 0xFF;
	return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
}

/**
 * IP real del visitante, imposible de falsear con cabeceras.
 *  - REMOTE_ADDR es la IP de quien abrió la conexión. En este hosting LiteSpeed
 *    ya la reescribe con la IP del visitante cuando la conexión viene de
 *    Cloudflare (comprobado el 2026-10-03), así que normalmente basta con ella.
 *  - Si REMOTE_ADDR es un nodo de Cloudflare (el servidor no la reescribió),
 *    se usa CF-Connecting-IP, y SOLO en ese caso: fuera de Cloudflare esa
 *    cabecera la puede escribir cualquiera.
 *  - X-Forwarded-For y X-Real-IP no se creen nunca.
 */
function lm_ip_real() {
	$remota = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore
	$remota = (string) filter_var( $remota, FILTER_VALIDATE_IP );
	if ( '' === $remota ) {
		return '';
	}
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		foreach ( lm_ip_rangos_cloudflare() as $rango ) {
			if ( lm_ip_en_rango( $remota, $rango ) ) {
				$cf = (string) filter_var( trim( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ), FILTER_VALIDATE_IP ); // phpcs:ignore
				return '' !== $cf ? $cf : $remota;
			}
		}
	}
	return $remota;
}

/**
 * WooCommerce guarda en el pedido la IP de WC_Geolocation::get_ip_address(), que
 * lee primero X-Real-IP y X-Forwarded-For. Se igualan a la IP real para que lo
 * guardado (y lo que lean otros plugins) no se pueda falsear y coincida con lo
 * que cuenta este límite.
 */
function lm_ip_normalizar_cabeceras() {
	$ip = lm_ip_real();
	if ( '' === $ip ) {
		return;
	}
	if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = $ip;
	}
	if ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
		$_SERVER['HTTP_X_REAL_IP'] = $ip;
	}
}
lm_ip_normalizar_cabeceras();

/** IP con la que se cuentan los pedidos (la misma que WooCommerce guarda tras normalizar). */
function lm_lp_ip() {
	return lm_ip_real();
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
