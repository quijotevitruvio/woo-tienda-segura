<?php
/**
 * Plugin Name: Comunicaciones comerciales (consentimiento, baja y registro)
 * Description: Reglas comunes para los correos comerciales propios (carrito abandonado, reactivación y los que añadas): solo a quien marcó la casilla de consentimiento, baja de un clic con enlace firmado, registro de a quién y cuándo se escribió (sin el contenido) y pie que dice quién escribe y por qué.
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
 * Uso desde otro envío comercial:
 *   if ( function_exists( 'lm_com_puede_escribir' ) && lm_com_puede_escribir( $email, 'mi-campana', 30 ) ) {
 *       $pie     = lm_com_pie_html( $email, 'porque …' );
 *       $headers = lm_com_cabeceras_baja( $email );   // List-Unsubscribe (un clic)
 *       … enviar …
 *       lm_com_registrar_envio( $email, 'mi-campana' );
 *   }
 * Si este archivo no está, lm-carrito-abandonado.php y lm-reactivacion.php no
 * guardan correos ni envían nada (fallan cerrados).
 *
 * Consentimiento: casilla opcional y desmarcada en el checkout de bloques
 * (campo adicional `lm/marketing_optin`, se guarda en el pedido) o la meta de
 * usuario `lm_marketing_optin` = '1' si otro plugin la gestiona. Sin dato: NO.
 *
 * Datos que guarda: la lista de bajas y el registro de envíos usan el SHA-256
 * del correo en minúsculas, no el correo (es una seudonimización, no un
 * anonimato: quien tenga el correo puede calcular su huella). Nada del contenido.
 *
 * Esto es una herramienta técnica; no garantiza por sí sola el cumplimiento de
 * ninguna ley de protección de datos. Revisa tu caso con un abogado.
 */

defined( 'ABSPATH' ) || exit;

define( 'LM_COM_BAJAS_OPTION', 'lm_comunicaciones_bajas' );
define( 'LM_COM_ENVIOS_TABLE', 'lm_comunicaciones_envios' );
define( 'LM_COM_DB_VERSION', '1' );

// ── Ajustes ─────────────────────────────────────────────────────────────────
/** Nombre de quien escribe (opción lm_com_remitente; por defecto, el nombre del sitio). */
function lm_com_remitente() {
	$n = trim( (string) get_option( 'lm_com_remitente', '' ) );
	return '' !== $n ? $n : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
}

/** Color de títulos y botones de los correos (opción lm_com_color; por defecto gris oscuro). */
function lm_com_color() {
	$c = sanitize_hex_color( (string) get_option( 'lm_com_color', '' ) );
	return $c ? $c : '#333333';
}

/** Línea opcional bajo el contenido de los correos (opción lm_com_nota, p. ej. «Envíos a todo el país»). */
function lm_com_nota_html() {
	$n = trim( (string) get_option( 'lm_com_nota', '' ) );
	return '' === $n ? '' : '<p style="font-size:13px;color:#666;">' . esc_html( $n ) . '</p>';
}

/** Firma de los correos: «— Nombre · dominio». */
function lm_com_firma_html() {
	$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	return '<p style="font-size:13px;color:#888;">— ' . esc_html( lm_com_remitente() )
		. ' · <a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( $host ) . '</a></p>';
}

/** Correo normalizado (minúsculas, sin espacios) o '' si no es válido. */
function lm_com_norm( $email ) {
	$email = strtolower( trim( (string) $email ) );
	return is_email( $email ) ? $email : '';
}

/** Huella del correo: lo único que se guarda en bajas y envíos. */
function lm_com_hash( $email ) {
	$email = lm_com_norm( $email );
	return '' === $email ? '' : hash( 'sha256', $email );
}

// ── Tabla de envíos (idempotente) ───────────────────────────────────────────
add_action( 'init', 'lm_com_crear_tabla', 5 );
function lm_com_crear_tabla() {
	if ( LM_COM_DB_VERSION === get_option( 'lm_com_db_version' ) ) {
		return;
	}
	global $wpdb;
	$table   = $wpdb->prefix . LM_COM_ENVIOS_TABLE;
	$charset = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE $table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		email_hash CHAR(64) NOT NULL,
		campana VARCHAR(40) NOT NULL,
		enviado_gmt DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY hash_campana (email_hash, campana)
	) $charset;" );
	update_option( 'lm_com_db_version', LM_COM_DB_VERSION, false );
}

// ── Casilla de consentimiento en el checkout de bloques ─────────────────────
/**
 * Casilla opcional y desmarcada. Se apaga con la opción lm_com_casilla_checkout = 0
 * (p. ej. si otro plugin ya registra el campo lm/marketing_optin).
 * Debe registrarse en cada carga para que el schema de la Store API la incluya.
 */
add_action( 'woocommerce_init', function () {
	if ( '0' === (string) get_option( 'lm_com_casilla_checkout', '1' ) || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
		return;
	}
	$texto = trim( (string) get_option( 'lm_com_casilla_texto', '' ) );
	if ( '' === $texto ) {
		/* translators: %s: nombre del sitio. */
		$texto = sprintf( __( 'Quiero recibir novedades y promociones de %s por correo.', 'woo-tienda-segura' ), lm_com_remitente() );
	}
	woocommerce_register_additional_checkout_field( array(
		'id'         => 'lm/marketing_optin',
		'label'      => $texto,
		'location'   => 'contact',
		'type'       => 'checkbox',
		'required'   => false,
		'attributes' => array(
			'title' => __( 'Puedes darte de baja cuando quieras.', 'woo-tienda-segura' ),
		),
	) );
} );

// ── (a) Consentimiento ──────────────────────────────────────────────────────
/**
 * ¿Esta persona autorizó recibir comunicaciones comerciales?
 * - Con cuenta: user meta lm_marketing_optin = '1' → sí; si hay
 *   lm_optin_baja_fecha → no.
 * - Sin cuenta (o cuenta sin decisión registrada): la casilla del checkout
 *   (lm/marketing_optin) de su pedido MÁS RECIENTE con ese correo.
 * - Si no hay forma de saberlo: NO.
 */
function lm_com_tiene_consentimiento( $email ) {
	$email = lm_com_norm( $email );
	if ( '' === $email ) {
		return false;
	}
	$si       = false;
	$decidido = false;
	$user     = get_user_by( 'email', $email );
	if ( $user ) {
		$optin = (string) get_user_meta( $user->ID, 'lm_marketing_optin', true );
		if ( '1' === $optin ) {
			$si       = true;
			$decidido = true;
		} elseif ( '' !== (string) get_user_meta( $user->ID, 'lm_optin_baja_fecha', true ) ) {
			$si       = false;
			$decidido = true;
		}
	}
	if ( ! $decidido && function_exists( 'wc_get_orders' ) ) {
		$ids = wc_get_orders( array(
			'billing_email' => $email,
			'type'          => 'shop_order',
			'orderby'       => 'date',
			'order'         => 'DESC',
			'limit'         => 1,
			'return'        => 'ids',
		) );
		if ( $ids ) {
			$pedido = wc_get_order( $ids[0] );
			$si     = $pedido && '1' === (string) $pedido->get_meta( '_wc_other/lm/marketing_optin' );
		}
	}
	/** Filtro para reglas propias (p. ej. tu plugin de suscripciones). */
	return (bool) apply_filters( 'lm_com_tiene_consentimiento', $si, $email );
}

// ── (b) Bajas ───────────────────────────────────────────────────────────────
/** ¿Se dio de baja? */
function lm_com_dado_de_baja( $email ) {
	$h = lm_com_hash( $email );
	if ( '' === $h ) {
		return true; // correo inválido: tratar como no escribible.
	}
	$bajas = get_option( LM_COM_BAJAS_OPTION, array() );
	return is_array( $bajas ) && isset( $bajas[ $h ] );
}

/** Registra una baja por huella. Si hay cuenta, también retira su consentimiento. */
function lm_com_registrar_baja_hash( $hash, $origen = 'enlace', $uid = 0 ) {
	if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $hash ) ) {
		return false;
	}
	$bajas = get_option( LM_COM_BAJAS_OPTION, array() );
	if ( ! is_array( $bajas ) ) {
		$bajas = array();
	}
	if ( ! isset( $bajas[ $hash ] ) ) {
		$bajas[ $hash ] = array( 'fecha_gmt' => gmdate( 'Y-m-d H:i:s' ), 'origen' => sanitize_key( $origen ) );
		update_option( LM_COM_BAJAS_OPTION, $bajas, false );
	}
	$uid = (int) $uid;
	if ( $uid > 0 ) {
		$u = get_userdata( $uid );
		if ( $u && hash_equals( lm_com_hash( $u->user_email ), $hash ) ) {
			update_user_meta( $uid, 'lm_marketing_optin', '' );
			update_user_meta( $uid, 'lm_optin_baja_fecha', gmdate( 'Y-m-d H:i:s' ) );
		} else {
			$uid = 0;
		}
	}
	/** Para que otro plugin (p. ej. de suscripciones) registre la baja a su modo. */
	do_action( 'lm_com_baja_registrada', $hash, $uid, $origen );
	return true;
}

function lm_com_registrar_baja( $email, $origen = 'enlace' ) {
	$user = get_user_by( 'email', lm_com_norm( $email ) );
	return lm_com_registrar_baja_hash( lm_com_hash( $email ), $origen, $user ? $user->ID : 0 );
}

/** Firma HMAC (clave derivada de wp_salt) de la huella + id de cuenta. */
function lm_com_firma( $hash, $uid ) {
	return substr( hash_hmac( 'sha256', 'lm-com-baja|' . $hash . '|' . (int) $uid, wp_salt( 'auth' ) ), 0, 32 );
}

/** Enlace de baja de un clic. No lleva el correo: solo su huella firmada. */
function lm_com_url_baja( $email ) {
	$h = lm_com_hash( $email );
	if ( '' === $h ) {
		return '';
	}
	$user  = get_user_by( 'email', lm_com_norm( $email ) );
	$uid   = $user ? (int) $user->ID : 0;
	$token = $h . '-' . $uid . '-' . lm_com_firma( $h, $uid );
	return add_query_arg( 'lm_com_baja', $token, home_url( '/' ) );
}

/** Valida un token; devuelve array( hash, uid ) o null. */
function lm_com_validar_token( $token ) {
	if ( ! preg_match( '/^([a-f0-9]{64})-(\d{1,20})-([a-f0-9]{32})$/', (string) $token, $m ) ) {
		return null;
	}
	if ( ! hash_equals( lm_com_firma( $m[1], (int) $m[2] ), $m[3] ) ) {
		return null;
	}
	return array( $m[1], (int) $m[2] );
}

/** Cabeceras List-Unsubscribe (RFC 8058, un clic desde Gmail/Outlook). */
function lm_com_cabeceras_baja( $email ) {
	$url = lm_com_url_baja( $email );
	if ( '' === $url ) {
		return '';
	}
	return 'List-Unsubscribe: <' . esc_url_raw( $url ) . ">\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
}

/**
 * Atiende /?lm_com_baja=TOKEN. Sin login. Respuesta sin caché.
 * - GET (enlace del correo): muestra un botón de confirmación. No da de baja por
 *   sí solo, para que los antivirus de correo que abren los enlaces no lo hagan.
 * - POST (botón de confirmación o «cancelar suscripción» del cliente de correo,
 *   RFC 8058): registra la baja.
 */
add_action( 'init', 'lm_com_atender_baja', 1 );
function lm_com_atender_baja() {
	if ( empty( $_GET['lm_com_baja'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	do_action( 'litespeed_control_set_nocache', 'baja de correos comerciales: respuesta personal' );
	nocache_headers();
	$token   = sanitize_text_field( wp_unslash( $_GET['lm_com_baja'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$datos   = lm_com_validar_token( $token );
	$es_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] );
	$quien   = esc_html( lm_com_remitente() );
	if ( ! $datos ) {
		if ( $es_post ) {
			status_header( 400 );
			exit;
		}
		wp_die( esc_html__( 'Este enlace de baja no es válido. Escríbenos y te damos de baja a mano.', 'woo-tienda-segura' ), esc_html__( 'Enlace no válido', 'woo-tienda-segura' ), array( 'response' => 400 ) );
	}
	if ( ! $es_post ) {
		wp_die(
			'<h1>' . esc_html__( '¿Darte de baja?', 'woo-tienda-segura' ) . '</h1>'
			/* translators: %s: nombre del sitio. */
			. '<p>' . sprintf( esc_html__( 'Dejarás de recibir correos comerciales de %s. Los correos de tus pedidos te seguirán llegando.', 'woo-tienda-segura' ), $quien ) . '</p>'
			. '<form method="post" action="' . esc_url( add_query_arg( 'lm_com_baja', $token, home_url( '/' ) ) ) . '">'
			. '<input type="hidden" name="lm_com_confirmar" value="1">'
			. '<button type="submit">' . esc_html__( 'Sí, darme de baja', 'woo-tienda-segura' ) . '</button></form>',
			esc_html__( 'Confirmar baja', 'woo-tienda-segura' ),
			array( 'response' => 200 )
		);
	}
	$desde_formulario = ! empty( $_POST['lm_com_confirmar'] ); // phpcs:ignore WordPress.Security.NonceVerification
	lm_com_registrar_baja_hash( $datos[0], $desde_formulario ? 'enlace-correo' : 'list-unsubscribe', $datos[1] );
	if ( ! $desde_formulario ) {
		status_header( 200 );
		exit;
	}
	wp_die(
		'<h1>' . esc_html__( 'Listo, te dimos de baja', 'woo-tienda-segura' ) . '</h1>'
		/* translators: %s: nombre del sitio. */
		. '<p>' . sprintf( esc_html__( 'No volverás a recibir correos comerciales de %s. Los correos de tus pedidos sí te seguirán llegando.', 'woo-tienda-segura' ), $quien ) . '</p>'
		. '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Volver a la tienda', 'woo-tienda-segura' ) . '</a></p>',
		esc_html__( 'Baja confirmada', 'woo-tienda-segura' ),
		array( 'response' => 200 )
	);
}

// ── (c) Registro de envíos ──────────────────────────────────────────────────
/** ¿Ya se le escribió de esta campaña en los últimos $dias días? */
function lm_com_escrito_hace( $email, $campana, $dias ) {
	global $wpdb;
	$h = lm_com_hash( $email );
	if ( '' === $h ) {
		return true;
	}
	$table = $wpdb->prefix . LM_COM_ENVIOS_TABLE;
	$desde = gmdate( 'Y-m-d H:i:s', time() - max( 0, (int) $dias ) * DAY_IN_SECONDS );
	$n     = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE email_hash=%s AND campana=%s AND enviado_gmt >= %s", $h, (string) $campana, $desde ) ); // phpcs:ignore
	return null === $n ? true : ( (int) $n > 0 ); // si la tabla falla: no escribir.
}

/** Apunta a quién (huella) y cuándo; nunca el contenido. */
function lm_com_registrar_envio( $email, $campana ) {
	global $wpdb;
	$h = lm_com_hash( $email );
	if ( '' === $h ) {
		return false;
	}
	return (bool) $wpdb->insert(
		$wpdb->prefix . LM_COM_ENVIOS_TABLE,
		array( 'email_hash' => $h, 'campana' => substr( (string) $campana, 0, 40 ), 'enviado_gmt' => gmdate( 'Y-m-d H:i:s' ) ),
		array( '%s', '%s', '%s' )
	);
}

/** Todas las reglas juntas: consentimiento, sin baja y sin repetir. */
function lm_com_puede_escribir( $email, $campana, $dias_sin_repetir = 7 ) {
	if ( '' === lm_com_norm( $email ) ) {
		return false;
	}
	if ( lm_com_dado_de_baja( $email ) ) {
		return false;
	}
	if ( ! lm_com_tiene_consentimiento( $email ) ) {
		return false;
	}
	if ( lm_com_escrito_hace( $email, $campana, $dias_sin_repetir ) ) {
		return false;
	}
	return true;
}

// ── (d) Quién escribe y por qué ─────────────────────────────────────────────
function lm_com_pie_html( $email, $porque ) {
	$baja  = lm_com_url_baja( $email );
	$priv  = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
	$host  = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	$extra = trim( (string) get_option( 'lm_com_pie_extra', '' ) );
	$html  = '<p style="font-size:12px;color:#888;line-height:1.5;margin-top:22px;border-top:1px solid #eee;padding-top:12px;">'
		/* translators: 1: nombre del sitio, 2: dominio, 3: motivo («porque …»). */
		. sprintf( esc_html__( 'Te escribe %1$s (%2$s) %3$s.', 'woo-tienda-segura' ), '<strong>' . esc_html( lm_com_remitente() ) . '</strong>', esc_html( $host ), esc_html( $porque ) ) . ' '
		. '<a href="' . esc_url( $baja ) . '" style="color:#888;">' . esc_html__( 'Date de baja con un clic', 'woo-tienda-segura' ) . '</a>; '
		. esc_html__( 'los correos de tus pedidos te seguirán llegando.', 'woo-tienda-segura' );
	if ( $priv ) {
		$html .= ' <a href="' . esc_url( $priv ) . '" style="color:#888;">' . esc_html__( 'Política de privacidad', 'woo-tienda-segura' ) . '</a>.';
	}
	if ( '' !== $extra ) {
		$html .= ' ' . esc_html( $extra );
	}
	return $html . '</p>';
}
