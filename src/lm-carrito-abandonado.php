<?php
/**
 * Plugin Name: Carrito abandonado (con consentimiento)
 * Description: Guarda el carrito de quien autorizó correos comerciales y, si enciendes el envío, le manda UN recordatorio pasada una hora, con enlace que repuebla el carrito, baja de un clic y List-Unsubscribe; no repite en 7 días. Requiere lm-comunicaciones.php.
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
 * - NO guarda el correo de quien no dio consentimiento o se dio de baja (y borra su fila si existía).
 * - El envío está APAGADO por defecto: wp option update lm_cart_recovery_enabled yes
 * - Tabla wp_lm_abandoned_carts; cron lm_cart_recovery_cron (cada hora); enlace ?lm_recover=<token>.
 * - Los carritos se purgan a los 30 días.
 * Constantes opcionales (definir en wp-config.php): LM_CART_DELAY (segundos, 3600), LM_CART_EXPIRE (segundos, 30 días).
 */

defined( 'ABSPATH' ) || exit;

define( 'LM_CART_TABLE', 'lm_abandoned_carts' );
if ( ! defined( 'LM_CART_DELAY' ) ) {
	define( 'LM_CART_DELAY', HOUR_IN_SECONDS );      // esperar 1 h antes del recordatorio.
}
if ( ! defined( 'LM_CART_EXPIRE' ) ) {
	define( 'LM_CART_EXPIRE', 30 * DAY_IN_SECONDS ); // se conservan máx. 30 días y se purgan.
}

// ── Crear tabla al cargar (idempotente) ─────────────────────────────────────
add_action( 'init', function () {
	if ( get_option( 'lm_cart_table_ready' ) ) {
		return;
	}
	global $wpdb;
	$table   = $wpdb->prefix . LM_CART_TABLE;
	$charset = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE $table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		email VARCHAR(190) NOT NULL,
		name VARCHAR(190) DEFAULT '',
		token VARCHAR(40) NOT NULL,
		cart_json LONGTEXT NOT NULL,
		total VARCHAR(20) DEFAULT '',
		created_gmt DATETIME NOT NULL,
		reminded_gmt DATETIME DEFAULT NULL,
		recovered_gmt DATETIME DEFAULT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY email (email),
		KEY token (token)
	) $charset;" );
	update_option( 'lm_cart_table_ready', '1' );
} );

/** Email actual del visitante: logueado o el que escribió en el checkout. */
function lm_cart_current_email() {
	if ( is_user_logged_in() ) {
		$u = wp_get_current_user();
		if ( is_email( $u->user_email ) ) {
			return $u->user_email;
		}
	}
	// Email tecleado en el checkout clásico (llega por POST en el refresh AJAX).
	if ( ! empty( $_POST['post_data'] ) ) { // phpcs:ignore
		parse_str( wp_unslash( $_POST['post_data'] ), $pd ); // phpcs:ignore
		if ( ! empty( $pd['billing_email'] ) && is_email( $pd['billing_email'] ) ) {
			return sanitize_email( $pd['billing_email'] );
		}
	}
	if ( WC()->customer && is_email( WC()->customer->get_billing_email() ) ) {
		return WC()->customer->get_billing_email();
	}
	return '';
}

/**
 * ¿Se puede guardar el correo de este carrito? Solo con consentimiento de
 * comunicaciones y sin baja (lm-comunicaciones.php). Sin ese módulo: no.
 */
function lm_cart_puede_guardar( $email ) {
	static $memo = array();
	$k = strtolower( trim( (string) $email ) );
	if ( isset( $memo[ $k ] ) ) {
		return $memo[ $k ];
	}
	$si = function_exists( 'lm_com_tiene_consentimiento' ) && function_exists( 'lm_com_dado_de_baja' )
		&& ! lm_com_dado_de_baja( $k ) && lm_com_tiene_consentimiento( $k );
	return $memo[ $k ] = (bool) $si;
}

/** Capturar/actualizar el carrito del visitante cuando hay email + items. */
function lm_cart_capture() {
	if ( is_admin() || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	$email = lm_cart_current_email();
	if ( ! $email || WC()->cart->is_empty() ) {
		return;
	}
	// El correo SOLO se guarda si la persona autorizó comunicaciones y no se dio
	// de baja. Si no, no se guarda nada y se borra lo que hubiera de ese correo.
	if ( ! lm_cart_puede_guardar( $email ) ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . LM_CART_TABLE, array( 'email' => $email ) );
		return;
	}
	$items = array();
	foreach ( WC()->cart->get_cart() as $ci ) {
		$items[] = array( 'id' => $ci['product_id'], 'qty' => $ci['quantity'], 'var' => $ci['variation_id'] ?? 0 );
	}
	global $wpdb;
	$table  = $wpdb->prefix . LM_CART_TABLE;
	$name   = WC()->customer ? trim( WC()->customer->get_billing_first_name() ) : '';
	$exists = $wpdb->get_row( $wpdb->prepare( "SELECT id, token, recovered_gmt FROM $table WHERE email=%s", $email ) ); // phpcs:ignore
	$data   = array(
		'email'     => $email,
		'name'      => $name,
		'cart_json' => wp_json_encode( $items ),
		'total'     => WC()->cart->get_total( 'edit' ),
	);
	if ( $exists ) {
		// Si ya se recuperó/recordó, reabrimos para un carrito nuevo.
		$data['reminded_gmt']  = null;
		$data['recovered_gmt'] = null;
		$data['created_gmt']   = current_time( 'mysql', true );
		$wpdb->update( $table, $data, array( 'id' => $exists->id ) );
	} else {
		$data['token']       = wp_generate_password( 20, false );
		$data['created_gmt'] = current_time( 'mysql', true );
		$wpdb->insert( $table, $data );
	}
}
add_action( 'woocommerce_checkout_update_order_review', 'lm_cart_capture', 20 );
add_action( 'woocommerce_add_to_cart', 'lm_cart_capture', 20 );

/** Al completar pedido, marcar recuperado (no molestar). */
add_action( 'woocommerce_thankyou', function ( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$email = $order->get_billing_email();
	if ( ! $email ) {
		return;
	}
	global $wpdb;
	$table = $wpdb->prefix . LM_CART_TABLE;
	$wpdb->update( $table, array( 'recovered_gmt' => current_time( 'mysql', true ) ), array( 'email' => $email ) );
} );

/** Enlace que repuebla el carrito y lleva al carrito. */
add_action( 'template_redirect', function () {
	if ( empty( $_GET['lm_recover'] ) || ! function_exists( 'WC' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$token = sanitize_text_field( wp_unslash( $_GET['lm_recover'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! preg_match( '/^[A-Za-z0-9]{20}$/', $token ) ) {
		return;
	}
	global $wpdb;
	$table = $wpdb->prefix . LM_CART_TABLE;
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE token=%s", $token ) ); // phpcs:ignore
	if ( ! $row ) {
		return;
	}
	$items = json_decode( $row->cart_json, true );
	if ( WC()->cart && is_array( $items ) ) {
		WC()->cart->empty_cart();
		foreach ( $items as $it ) {
			WC()->cart->add_to_cart( (int) $it['id'], (int) $it['qty'], (int) ( $it['var'] ?? 0 ) );
		}
	}
	wp_safe_redirect( wc_get_cart_url() );
	exit;
}, 5 );

// ── Cron: cada hora, recordatorio único a carritos abandonados ──────────────
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'lm_cart_recovery_cron' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'lm_cart_recovery_cron' );
	}
} );

add_action( 'lm_cart_recovery_cron', function () {
	global $wpdb;
	$table = $wpdb->prefix . LM_CART_TABLE;
	// Limpiar viejos.
	$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created_gmt < %s", gmdate( 'Y-m-d H:i:s', time() - LM_CART_EXPIRE ) ) ); // phpcs:ignore

	// El ENVÍO está apagado por defecto: solo captura. Encender con la opción.
	if ( 'yes' !== get_option( 'lm_cart_recovery_enabled' ) ) {
		return;
	}

	$cutoff = gmdate( 'Y-m-d H:i:s', time() - LM_CART_DELAY );
	$rows   = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
		"SELECT * FROM $table WHERE reminded_gmt IS NULL AND recovered_gmt IS NULL AND created_gmt < %s LIMIT 30",
		$cutoff
	) );
	foreach ( $rows as $row ) {
		lm_cart_procesar_fila( $row );
	}
} );

/**
 * Decide y, si procede, envía el recordatorio de UNA fila.
 * @return string 'recuperado' | 'sin-consentimiento' | 'enviado' | 'fallo'
 */
function lm_cart_procesar_fila( $row ) {
	global $wpdb;
	$table = $wpdb->prefix . LM_CART_TABLE;
	// Si ya compró tras crear el carrito, saltar.
	$has_order = wc_get_orders( array(
		'billing_email' => $row->email,
		'date_created'  => '>' . strtotime( $row->created_gmt . ' UTC' ),
		'limit'         => 1,
		'return'        => 'ids',
	) );
	if ( $has_order ) {
		$wpdb->update( $table, array( 'recovered_gmt' => current_time( 'mysql', true ) ), array( 'id' => $row->id ) );
		return 'recuperado';
	}
	// Consentimiento + baja + no repetir (7 días). Sin el módulo común: no se envía.
	if ( ! function_exists( 'lm_com_puede_escribir' ) || ! lm_com_puede_escribir( $row->email, 'carrito', 7 ) ) {
		// Sin consentimiento o dado de baja: la fila se BORRA. Si solo es por
		// repetición, se marca como atendida (el registro real de envíos está en
		// la tabla lm_comunicaciones_envios).
		if ( ! lm_cart_puede_guardar( $row->email ) ) {
			$wpdb->delete( $table, array( 'id' => $row->id ) );
		} else {
			$wpdb->update( $table, array( 'reminded_gmt' => current_time( 'mysql', true ) ), array( 'id' => $row->id ) );
		}
		return 'sin-consentimiento';
	}
	$enviado = lm_cart_send_reminder( $row );
	$wpdb->update( $table, array( 'reminded_gmt' => current_time( 'mysql', true ) ), array( 'id' => $row->id ) );
	if ( $enviado ) {
		lm_com_registrar_envio( $row->email, 'carrito' );
		return 'enviado';
	}
	return 'fallo';
}

/** Construye y envía el email de recordatorio. Textos editables con los filtros lm_cart_asunto y lm_cart_cuerpo. */
function lm_cart_send_reminder( $row ) {
	$items = json_decode( $row->cart_json, true );
	if ( ! is_array( $items ) || empty( $items ) ) {
		return false;
	}
	if ( ! function_exists( 'lm_com_pie_html' ) ) {
		return false;
	}
	$recover_url = add_query_arg( 'lm_recover', $row->token, home_url( '/' ) );
	$name        = $row->name ? ' ' . esc_html( $row->name ) : '';
	$color       = esc_attr( lm_com_color() );
	$sitio       = lm_com_remitente();

	$rows_html = '';
	foreach ( $items as $it ) {
		$p = wc_get_product( (int) $it['id'] );
		if ( ! $p ) {
			continue;
		}
		$img        = $p->get_image( 'thumbnail', array( 'style' => 'width:60px;height:auto;border-radius:6px;vertical-align:middle;' ) );
		$rows_html .= '<tr><td style="padding:8px 0;">' . $img . '</td>'
			. '<td style="padding:8px 10px;font-size:14px;">' . esc_html( wp_strip_all_tags( $p->get_name() ) ) . '</td>'
			. '<td style="padding:8px 0;font-size:14px;color:' . $color . ';font-weight:700;white-space:nowrap;">' . wp_kses_post( $p->get_price_html() ) . '</td></tr>';
	}

	$body = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#222;">'
		. '<h2 style="color:' . $color . ';">' . esc_html__( '¿Se te quedó algo en el carrito?', 'woo-tienda-segura' ) . $name . '</h2>'
		. '<p>' . esc_html__( 'Termina tu compra en un clic:', 'woo-tienda-segura' ) . '</p>'
		. '<table style="width:100%;border-collapse:collapse;margin:14px 0;">' . $rows_html . '</table>'
		. '<p style="text-align:center;margin:24px 0;"><a href="' . esc_url( $recover_url ) . '" style="background:' . $color . ';color:#fff;padding:13px 28px;border-radius:999px;text-decoration:none;font-weight:700;">' . esc_html__( 'Recuperar mi carrito →', 'woo-tienda-segura' ) . '</a></p>'
		. lm_com_nota_html()
		. '<p style="font-size:13px;color:#666;">' . esc_html__( 'Si ya compraste, ignora este mensaje.', 'woo-tienda-segura' ) . '</p>'
		. lm_com_firma_html()
		/* translators: %s: nombre del sitio. */
		. lm_com_pie_html( $row->email, sprintf( __( 'porque dejaste productos en tu carrito de %s y nos autorizaste a enviarte correos', 'woo-tienda-segura' ), $sitio ) )
		. '</div>';
	$body = (string) apply_filters( 'lm_cart_cuerpo', $body, $row );

	$mailer = WC()->mailer();
	/* translators: %s: nombre del sitio. */
	$subject = (string) apply_filters( 'lm_cart_asunto', sprintf( __( 'Tu carrito te espera en %s', 'woo-tienda-segura' ), $sitio ), $row );
	$wrapped = $mailer->wrap_message( $subject, $body );
	return (bool) $mailer->send( $row->email, $subject, $wrapped, "Content-Type: text/html\r\n" . lm_com_cabeceras_baja( $row->email ) );
}
