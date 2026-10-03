<?php
/**
 * Plugin Name: Alerta de venta (WhatsApp / Telegram)
 * Description: Avisa por Telegram y/o WhatsApp (CallMeBot) cuando entra un pedido, sin depender del correo.
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
 * POR QUÉ EXISTE
 * Si el servidor de correo falla, los avisos de «nuevo pedido» dejan de llegar y las
 * ventas pueden pasar días sin que nadie las vea. Este mu-plugin es un segundo canal,
 * independiente del correo, para enterarse de cada venta.
 *
 * CÓMO SE CONFIGURA (elige uno o los dos). Las constantes de wp-config.php con el mismo
 * nombre en MAYÚSCULAS (p. ej. LM_ALERTA_TELEGRAM_TOKEN) tienen prioridad sobre las opciones.
 *
 *   Telegram — gratis:
 *     1. En Telegram, habla con @BotFather y usa /newbot. Te da un token.
 *     2. Escríbele algo a tu bot para abrir la conversación.
 *     3. Abre https://api.telegram.org/bot<TU_TOKEN>/getUpdates y copia el "chat":{"id":...}
 *     4. Guarda ambos valores (los de abajo son ejemplos ficticios):
 *        wp option update lm_alerta_telegram_token "123456:ABC-ejemplo"
 *        wp option update lm_alerta_telegram_chat  "987654321"
 *
 *   WhatsApp vía CallMeBot — gratis para uso personal:
 *     1. Sigue las instrucciones de https://www.callmebot.com/blog/free-api-whatsapp-messages/
 *        para autorizar tu número y obtener una apikey.
 *     2. Guarda (ejemplos ficticios; el teléfono va con indicativo de país, sin «+»):
 *        wp option update lm_alerta_wa_phone  "573000000000"
 *        wp option update lm_alerta_wa_apikey "123456"
 *
 * Sin configurar no hace absolutamente nada: es inerte hasta que existan las claves.
 *
 * DATOS PERSONALES: el aviso incluye número de pedido, total, medio de pago, nombre y
 * ciudad del cliente y los productos, y se envía a un tercero (Telegram o CallMeBot).
 * Menciónalo en tu política de privacidad o recorta el texto con el filtro `lm_alerta_texto`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lee un ajuste, dando prioridad a una constante de wp-config.php si existe.
 */
function lm_alerta_cfg( $clave ) {
	$constante = strtoupper( $clave );
	if ( defined( $constante ) ) {
		return (string) constant( $constante );
	}
	return (string) get_option( $clave, '' );
}

/**
 * ¿Hay al menos un canal configurado?
 */
function lm_alerta_activa() {
	$tg = lm_alerta_cfg( 'lm_alerta_telegram_token' ) && lm_alerta_cfg( 'lm_alerta_telegram_chat' );
	$wa = lm_alerta_cfg( 'lm_alerta_wa_phone' ) && lm_alerta_cfg( 'lm_alerta_wa_apikey' );
	return $tg || $wa;
}

/**
 * Envía el texto por los canales configurados. Nunca lanza excepciones ni bloquea el checkout.
 *
 * @return array Resultado por canal, para dejar constancia en la nota del pedido.
 */
function lm_alerta_enviar( $texto ) {
	$resultado = array();

	$tg_token = lm_alerta_cfg( 'lm_alerta_telegram_token' );
	$tg_chat  = lm_alerta_cfg( 'lm_alerta_telegram_chat' );
	if ( $tg_token && $tg_chat ) {
		$r = wp_remote_post(
			'https://api.telegram.org/bot' . $tg_token . '/sendMessage',
			array(
				'timeout'  => 8,
				'blocking' => true,
				'body'     => array(
					'chat_id'                  => $tg_chat,
					'text'                     => $texto,
					'disable_web_page_preview' => 'true',
				),
			)
		);
		// Solo el código HTTP: el mensaje de error podría contener la URL con el token.
		$resultado['telegram'] = is_wp_error( $r ) ? 'error' : 'HTTP ' . wp_remote_retrieve_response_code( $r );
	}

	$wa_phone  = lm_alerta_cfg( 'lm_alerta_wa_phone' );
	$wa_apikey = lm_alerta_cfg( 'lm_alerta_wa_apikey' );
	if ( $wa_phone && $wa_apikey ) {
		$url = add_query_arg(
			array(
				'phone'  => rawurlencode( $wa_phone ),
				'text'   => rawurlencode( $texto ),
				'apikey' => rawurlencode( $wa_apikey ),
			),
			'https://api.callmebot.com/whatsapp.php'
		);
		$r = wp_remote_get( $url, array( 'timeout' => 8, 'blocking' => true ) );
		$resultado['whatsapp'] = is_wp_error( $r ) ? 'error' : 'HTTP ' . wp_remote_retrieve_response_code( $r );
	}

	return $resultado;
}

/**
 * Arma y manda el aviso de un pedido. Una sola vez por pedido.
 */
function lm_alerta_pedido( $order_id, $order = null ) {
	if ( ! lm_alerta_activa() ) {
		return;
	}
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	// Una sola alerta por pedido, aunque cambie de estado varias veces.
	if ( $order->get_meta( '_lm_alerta_enviada' ) ) {
		return;
	}

	// Los pedidos de $0 (productos gratuitos) no son ventas y ahogarían el canal.
	if ( (float) $order->get_total() <= 0 ) {
		return;
	}

	$lineas = array();
	foreach ( $order->get_items() as $item ) {
		$lineas[] = '• ' . $item->get_quantity() . '× ' . wp_strip_all_tags( $item->get_name() );
	}

	$texto = '🛒 ' . __( 'PEDIDO', 'woo-tienda-segura' ) . ' #' . $order->get_order_number() . "\n"
		. lm_alerta_precio_texto( $order->get_total() ) . ' · ' . $order->get_payment_method_title() . "\n"
		. trim( $order->get_formatted_billing_full_name() ) . "\n"
		. ( $order->get_billing_city() ? $order->get_billing_city() . "\n" : '' )
		. "\n" . implode( "\n", $lineas ) . "\n\n"
		. admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order->get_id() );

	/** Filtro para recortar o cambiar el texto del aviso (p. ej. quitar nombre y ciudad). */
	$texto = (string) apply_filters( 'lm_alerta_texto', $texto, $order );

	$resultado = lm_alerta_enviar( $texto );

	$order->update_meta_data( '_lm_alerta_enviada', current_time( 'mysql' ) );
	$partes = array();
	foreach ( $resultado as $canal => $estado ) {
		$partes[] = $canal . ': ' . $estado;
	}
	if ( $partes ) {
		$order->add_order_note( '🔔 ' . __( 'Alerta de venta enviada', 'woo-tienda-segura' ) . ' (' . implode( ', ', $partes ) . ').' );
	}
	$order->save();
}

/**
 * Formatea el total sin etiquetas HTML, apto para mensajes de texto plano.
 */
function lm_alerta_precio_texto( $monto ) {
	return html_entity_decode( wp_strip_all_tags( wc_price( $monto ) ), ENT_QUOTES, 'UTF-8' );
}

// Se dispara cuando el pedido llega a un estado que significa "hay una venta real".
add_action( 'woocommerce_order_status_processing', 'lm_alerta_pedido', 10, 2 );
add_action( 'woocommerce_order_status_on-hold', 'lm_alerta_pedido', 10, 2 );
add_action( 'woocommerce_order_status_completed', 'lm_alerta_pedido', 10, 2 );

/**
 * Comando de prueba (solo WP-CLI): wp eval "lm_alerta_probar();"
 */
function lm_alerta_probar() {
	if ( ! lm_alerta_activa() ) {
		echo "Sin configurar: falta el token de Telegram o la apikey de WhatsApp.\n";
		return;
	}
	$r = lm_alerta_enviar( '🔔 ' . sprintf( 'Prueba de alerta de venta desde %s.', wp_parse_url( home_url(), PHP_URL_HOST ) ) . "\nSi ves esto, el canal funciona." );
	foreach ( $r as $canal => $estado ) {
		echo esc_html( $canal . ': ' . $estado ) . "\n";
	}
}
