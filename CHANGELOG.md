# Changelog

## 1.1.0 - 2026-10-03
- Seguridad (`lm-limite-pedidos.php`): la IP ya no se toma de `X-Forwarded-For` / `X-Real-IP` (falseables). Nueva `lm_ip_real()`: `REMOTE_ADDR`, o `CF-Connecting-IP` solo desde rangos oficiales de Cloudflare (embebidos, 2026-10-03). Las cabeceras de proxy se igualan a la IP real para que WooCommerce guarde la misma IP en el pedido. Otros plugins pueden usar `lm_ip_real()`.

## 1.0.0
- Primera versión pública, en `src/`: `lm-limite-pedidos.php`, `lm-alerta-venta.php`, `lm-comunicaciones.php`, `lm-carrito-abandonado.php` y `lm-reactivacion.php`.
- Nuevo `lm-comunicaciones.php`: casilla de consentimiento en el checkout de bloques, baja de un clic firmada con HMAC (sin el correo en la URL) y con confirmación, `List-Unsubscribe` (RFC 8058), registro de envíos por huella y pie «quién escribe y por qué».
- Carrito abandonado y reactivación: solo con consentimiento y sin baja; no repiten (7 y 180 días).
- Textos y marca configurables (`lm_com_remitente`, `lm_com_color`, `lm_com_nota`, `lm_com_pie_extra`, filtros de asunto y cuerpo); textos con dominio `woo-tienda-segura`.
- Límite de pedidos: rutas REST extra por filtro (`lm_limite_pedidos_rutas_rest`) y frase de contacto configurable.
- Alerta de venta: filtro `lm_alerta_texto`; la nota del pedido ya no guarda mensajes de error.
