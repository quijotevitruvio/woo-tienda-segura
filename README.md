# woo-tienda-segura

Cinco *mu-plugins* para WooCommerce que protegen y alertan a la tienda, y le escriben a los clientes solo con su permiso. Están en `src/`.

| Archivo | Qué hace | Depende de |
|---|---|---|
| `lm-limite-pedidos.php` | Frena a quien crea pedidos en serie para agotar el inventario (límite por IP o cuenta) | — |
| `lm-alerta-venta.php` | Avisa por Telegram y/o WhatsApp (CallMeBot) cuando entra un pedido, sin depender del correo | — |
| `lm-comunicaciones.php` | Reglas comunes de los correos comerciales: casilla de consentimiento en el checkout, baja de un clic firmada, registro de envíos y pie «quién escribe y por qué» | — |
| `lm-carrito-abandonado.php` | Guarda el carrito de quien autorizó correos y, **si lo enciendes**, le manda un recordatorio a la hora | `lm-comunicaciones.php` |
| `lm-reactivacion.php` | Correo manual a clientes que no compran hace más de 60 días y autorizaron correos | `lm-comunicaciones.php` |

Sin `lm-comunicaciones.php`, el carrito y la reactivación **no guardan correos ni envían nada** (fallan cerrados).

## Requisitos
WordPress 6.x, PHP 7.4+, WooCommerce con **almacenamiento de pedidos de alto rendimiento (HPOS)**: `limite-pedidos` y `reactivacion` leen la tabla `wp_wc_orders` directamente. La casilla de consentimiento usa los campos adicionales del **checkout de bloques** (WooCommerce 8.9+). WP-CLI para configurar.

## Instalación
Copia los archivos que quieras de `src/` a `wp-content/mu-plugins/`. No hay que activarlos.

## Ajustes
Todos son opciones de WordPress (`wp option update <opción> <valor>`). Valores por defecto neutros.

| Ajuste | Opción / constante | Por defecto | Qué hace |
|---|---|---|---|
| Límite: umbrales | `lm_limite_pedidos` (array) | `por_ventana` 5, `ventana_min` 10, `impagos_24h` 8 | Pedidos máximos por IP/cuenta en la ventana y pedidos sin pagar en 24 h |
| Límite: interruptor | `lm_limite_pedidos_activo` | `1` | `0` lo apaga |
| Límite: contacto | `lm_limite_pedidos_contacto` | «Si necesitas ayuda, contáctanos.» | Frase que se añade al mensaje de bloqueo (p. ej. tu canal de soporte) |
| Límite: rutas REST | filtro `lm_limite_pedidos_rutas_rest` | ninguna | Rutas REST propias (POST) que crean pedidos y también se deben limitar |
| Alerta: Telegram | `lm_alerta_telegram_token`, `lm_alerta_telegram_chat` (o constantes `LM_ALERTA_TELEGRAM_TOKEN`, `LM_ALERTA_TELEGRAM_CHAT`) | vacío | Bot y chat que reciben el aviso |
| Alerta: WhatsApp | `lm_alerta_wa_phone`, `lm_alerta_wa_apikey` (o `LM_ALERTA_WA_PHONE`, `LM_ALERTA_WA_APIKEY`) | vacío | Número (con indicativo, sin «+») y apikey de CallMeBot |
| Alerta: texto | filtro `lm_alerta_texto` | pedido, total, pago, nombre, ciudad, productos | Recorta o cambia lo que se manda al tercero |
| Correos: remitente | `lm_com_remitente` | nombre del sitio | Nombre que firma los correos y la página de baja |
| Correos: color | `lm_com_color` | `#333333` | Color de títulos y botones |
| Correos: nota | `lm_com_nota` | vacío | Línea opcional bajo el contenido (p. ej. «Envíos a todo el país») |
| Correos: pie extra | `lm_com_pie_extra` | vacío | Texto opcional al final del pie (p. ej. la ley que aplicas) |
| Casilla en el checkout | `lm_com_casilla_checkout` | `1` | `0` no la registra (si otro plugin ya registra `lm/marketing_optin`) |
| Texto de la casilla | `lm_com_casilla_texto` | «Quiero recibir novedades y promociones de {sitio} por correo.» | Etiqueta de la casilla |
| Carrito: envío | `lm_cart_recovery_enabled` | apagado | `yes` enciende el recordatorio |
| Carrito: espera | constante `LM_CART_DELAY` | 3600 s | Tiempo antes del recordatorio |
| Carrito: caducidad | constante `LM_CART_EXPIRE` | 30 días | Los carritos se borran después |
| Carrito: textos | filtros `lm_cart_asunto`, `lm_cart_cuerpo` | — | Cambian asunto y HTML del correo |
| Reactivación: días | `lm_reactivacion_dias` | `60` | Días sin comprar para entrar en el segmento |
| Reactivación: repetir | `lm_reactivacion_repetir_dias` | `180` | No vuelve a escribir a la misma persona antes |
| Reactivación: textos | filtros `lm_reactivacion_asunto`, `lm_reactivacion_cuerpo` | — | Cambian asunto y HTML del correo |
| Consentimiento propio | filtro `lm_com_tiene_consentimiento` | — | Para conectar tu propio plugin de suscripciones |
| Bajas en otro sistema | acción `lm_com_baja_registrada` | — | Se dispara con (huella, id de usuario, origen) al registrar una baja |

## Cómo funciona
**Límite de pedidos.** Cubre el checkout de bloques (Store API, responde 429), el checkout clásico y las rutas REST que declares por filtro. Administradores y gestores de tienda nunca se limitan. Cuenta los bloqueos por día en `lm_limite_pedidos_bloqueos` (sin IP).

**Alerta de venta.** Inerte hasta configurarla. Se dispara una vez por pedido (meta `_lm_alerta_enviada`) al pasar a *procesando*, *en espera* o *completado*; ignora pedidos de $0. El resultado (solo el código HTTP) queda en una nota del pedido. Prueba: `wp eval "lm_alerta_probar();"`.

**Consentimiento y bajas (`lm-comunicaciones.php`).**
- Casilla opcional y desmarcada en la sección de contacto del checkout de bloques; el valor queda en el pedido (`_wc_other/lm/marketing_optin`). Con cuenta, manda la meta de usuario `lm_marketing_optin` = `1` (o la baja en `lm_optin_baja_fecha`). Sin dato: **no** se escribe.
- Enlace de baja `?lm_com_baja=<huella>-<id>-<firma HMAC>`: no lleva el correo. Al abrirlo pide confirmar con un botón (los antivirus de correo que abren enlaces no dan de baja a nadie); el «cancelar suscripción» de Gmail/Outlook (RFC 8058, `List-Unsubscribe-Post`) da de baja directamente.
- Las bajas (`lm_comunicaciones_bajas`) y el registro de envíos (tabla `wp_lm_comunicaciones_envios`) guardan el SHA-256 del correo, no el correo, y nunca el contenido.

**Carrito abandonado.** Solo guarda el correo si la persona autorizó y no está de baja; si no, borra su fila. Tabla `wp_lm_abandoned_carts`; cron `lm_cart_recovery_cron` cada hora; un recordatorio tras 1 hora y no repite en 7 días; enlace `?lm_recover=<token>` que repuebla el carrito.

**Reactivación.** No envía sola. Prueba sin enviar: `wp eval "print_r(lm_reactivacion_send(true));"`. Envío real: `wp eval "print_r(lm_reactivacion_send(false));"` (2.º parámetro opcional: límite). Solo a quien autorizó, sin baja y sin correo de esta campaña en 180 días.

## Correos a clientes y datos personales (léelo antes de encender)
Esto es información técnica, **no asesoría legal**, y el código **no garantiza por sí solo** el cumplimiento de ninguna ley (en Colombia, Ley 1581 de 2012; en la UE, GDPR/ePrivacy). Consulta a un abogado de tu país y actualiza tu política de privacidad.
- El consentimiento es una casilla sin doble confirmación: alguien puede marcarla con un correo ajeno. Si lo necesitas, añade doble opt-in.
- El carrito guarda el correo en claro (solo con consentimiento) hasta 30 días.
- `lm-alerta-venta` envía nombre, ciudad, total y productos del pedido a Telegram o CallMeBot (terceros): menciónalo en tu política o recorta el texto con `lm_alerta_texto`.
- `lm-limite-pedidos` usa la IP que WooCommerce guarda en el pedido; una IP compartida (oficina, red móvil) puede bloquear a clientes legítimos.

## Cómo deshacer
Borra el archivo. Quedan opciones y datos: `lm_limite_pedidos*`, `lm_alerta_*`, `lm_com_*`, `lm_comunicaciones_bajas`, `lm_cart_*`, `lm_reactivacion_*`; además `DROP TABLE wp_lm_abandoned_carts, wp_lm_comunicaciones_envios` y `wp cron event delete lm_cart_recovery_cron`. Las metas `_lm_alerta_enviada` quedan en los pedidos.

## FAQ
**¿Por qué existe la alerta de venta?** Si falla el correo saliente, los avisos de pedido nuevo dejan de llegar y las ventas pueden pasar inadvertidas; un segundo canal lo evita.
**¿Qué pasa si CallMeBot o Telegram caen?** Nunca lanza excepciones ni bloquea el checkout; el resultado queda en una nota del pedido.
**¿Puedo cambiar el texto de los correos?** Sí: con los filtros de la tabla o traduciendo el dominio `woo-tienda-segura` (los textos base están en español).
**¿El límite de pedidos afecta a clientes normales?** Con los valores por defecto, muy rara vez; ajusta los umbrales a tu tienda.

## Limitaciones
Sin HPOS no funcionan límite ni reactivación. Correos HTML con textos base en español. Sin pruebas automatizadas. Ver `SEGURIDAD.md`.

## Licencia
GPL-2.0-or-later. Ver `LICENSE`.

## Autor y apoyo
Andrés Valencia Tobón · [GitHub](https://github.com/quijotevitruvio) · [LinkedIn](https://www.linkedin.com/in/andr%C3%A9s-valencia-tob%C3%B3n/)
Apoya / Support: https://paypal.me/bibliotecologo
Desarrollado para librosmedellin.com.
