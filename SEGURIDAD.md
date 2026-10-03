# Seguridad

Revisión hecha antes de publicar 1.0.0; actualizada en 1.1.0 (2026-10-03). Gravedad: alta / media / baja / informativa.

## Arreglado en este repositorio
| Hallazgo | Archivo | Gravedad | Qué se hizo |
|---|---|---|---|
| El enlace de baja con GET daba de baja al instante: los antivirus de correo que abren enlaces podían dar de baja a la gente sin que lo pidiera | `src/lm-comunicaciones.php` (`lm_com_atender_baja`) | baja | GET muestra un botón de confirmación; la baja se registra por POST (formulario o RFC 8058) |
| Enlace de baja antiguo con el correo en la URL y un `md5` truncado a 10 caracteres | (eliminado; existía en la versión de origen de `lm-reactivacion.php`) | baja | Se quitó; solo queda el enlace firmado con HMAC, sin el correo |
| El mensaje de error de `wp_remote_*` se guardaba en la nota del pedido (podría incluir la URL con el token del bot) | `src/lm-alerta-venta.php` (`lm_alerta_enviar`) | baja | La nota guarda solo «error» o el código HTTP |
| Función global con prefijo ajeno `wc_price_to_text_safe` (choque con WooCommerce u otros plugins) | `src/lm-alerta-venta.php` | baja | Renombrada a `lm_alerta_precio_texto` |
| Ruta REST privada de la tienda de origen cableada en el límite de pedidos | `src/lm-limite-pedidos.php` | informativa | Sustituida por el filtro `lm_limite_pedidos_rutas_rest` (vacío por defecto) |
| Token `?lm_recover` sin validar formato antes de consultar | `src/lm-carrito-abandonado.php` | informativa | Se exige `^[A-Za-z0-9]{20}$` (ya iba con `$wpdb->prepare`) |
| (1.1.0) El límite por IP se saltaba enviando `X-Forwarded-For` / `X-Real-IP` distintos en cada pedido: la IP salía de `WC_Geolocation::get_ip_address()`, que confía en esas cabeceras | `src/lm-limite-pedidos.php` (`lm_lp_ip`, nuevo `lm_ip_real`) | media | IP de `REMOTE_ADDR`; `CF-Connecting-IP` solo si la conexión viene de un rango oficial de Cloudflare (lista embebida); esas cabeceras se igualan a la IP real para que el pedido guarde la misma. Probado en producción: con `X-Forwarded-For` y `CF-Connecting-IP` falsos, el límite y WooCommerce ven la IP real |

## Pendiente (no arreglado)
| Hallazgo | Archivo | Gravedad | Recomendación |
|---|---|---|---|
| Consentimiento por casilla sin doble confirmación: cualquiera puede marcarla con un correo ajeno en un pedido | `src/lm-comunicaciones.php` (`lm_com_tiene_consentimiento`) | baja | Añadir doble opt-in si la ley o el riesgo lo piden |
| Las bajas y el registro usan SHA-256 sin sal del correo: es seudonimización, no anonimato | `src/lm-comunicaciones.php` (`lm_com_hash`) | informativa | Documentado; no presentarlo como dato anónimo |

## Comprobado sin problemas
Sin AJAX ni rutas REST propias. Todo SQL con `$wpdb->prepare` o sin entrada del usuario. Salida de los correos con `esc_html`/`esc_url`/`wp_kses_post`. Redirección del carrito con `wp_safe_redirect`. Sin subida de archivos ni `unserialize` de entrada. Firma de baja con `hash_hmac` + `hash_equals`.
