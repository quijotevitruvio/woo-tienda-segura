# woo-tienda-segura

Five WooCommerce *mu-plugins* that protect and alert the shop, and email customers only with their permission. They live in `src/`.

| File | What it does | Needs |
|---|---|---|
| `lm-limite-pedidos.php` | Stops anyone creating orders in bulk to drain stock (limit per IP or account) | — |
| `lm-alerta-venta.php` | Notifies you on Telegram and/or WhatsApp (CallMeBot) when an order arrives, independent of email | — |
| `lm-comunicaciones.php` | Common rules for marketing emails: consent checkbox at checkout, signed one-click unsubscribe, send log and a "who is writing and why" footer | — |
| `lm-carrito-abandonado.php` | Stores the cart of people who opted in and, **if you turn it on**, sends one reminder after an hour | `lm-comunicaciones.php` |
| `lm-reactivacion.php` | Manual email to opted-in customers who have not bought in over 60 days | `lm-comunicaciones.php` |

Without `lm-comunicaciones.php`, the cart and reactivation plugins **store no emails and send nothing** (fail closed).

## Requirements
WordPress 6.x, PHP 7.4+, WooCommerce with **High-Performance Order Storage (HPOS)**: `limite-pedidos` and `reactivacion` read the `wp_wc_orders` table directly. The consent checkbox uses **block checkout** additional fields (WooCommerce 8.9+). WP-CLI for setup.

## Installation
Copy the files you want from `src/` to `wp-content/mu-plugins/`. No activation needed.

## Settings
All are WordPress options (`wp option update <option> <value>`). Defaults are neutral. Base strings are Spanish (text domain `woo-tienda-segura`).

| Setting | Option / constant | Default | What it does |
|---|---|---|---|
| Limit: thresholds | `lm_limite_pedidos` (array) | `por_ventana` 5, `ventana_min` 10, `impagos_24h` 8 | Max orders per IP/account in the window and unpaid orders in 24 h |
| Limit: switch | `lm_limite_pedidos_activo` | `1` | `0` turns it off |
| Limit: contact | `lm_limite_pedidos_contacto` | "Si necesitas ayuda, contáctanos." | Sentence appended to the block message (e.g. your support channel) |
| Limit: REST routes | filter `lm_limite_pedidos_rutas_rest` | none | Your own REST routes (POST) that create orders and must also be limited |
| Alert: Telegram | `lm_alerta_telegram_token`, `lm_alerta_telegram_chat` (or constants `LM_ALERTA_TELEGRAM_TOKEN`, `LM_ALERTA_TELEGRAM_CHAT`) | empty | Bot and chat that receive the alert |
| Alert: WhatsApp | `lm_alerta_wa_phone`, `lm_alerta_wa_apikey` (or `LM_ALERTA_WA_PHONE`, `LM_ALERTA_WA_APIKEY`) | empty | Phone (country code, no "+") and CallMeBot apikey |
| Alert: text | filter `lm_alerta_texto` | order, total, payment, name, city, items | Trim or change what is sent to the third party |
| Emails: sender | `lm_com_remitente` | site name | Name signing the emails and the unsubscribe page |
| Emails: colour | `lm_com_color` | `#333333` | Headings and buttons |
| Emails: note | `lm_com_nota` | empty | Optional line under the content (e.g. "We ship nationwide") |
| Emails: footer extra | `lm_com_pie_extra` | empty | Optional text at the end of the footer (e.g. the law you follow) |
| Checkout checkbox | `lm_com_casilla_checkout` | `1` | `0` skips registering it (if another plugin already registers `lm/marketing_optin`) |
| Checkbox label | `lm_com_casilla_texto` | "Quiero recibir novedades y promociones de {site} por correo." | Checkbox label |
| Cart: sending | `lm_cart_recovery_enabled` | off | `yes` enables the reminder |
| Cart: delay | constant `LM_CART_DELAY` | 3600 s | Wait before the reminder |
| Cart: expiry | constant `LM_CART_EXPIRE` | 30 days | Carts are deleted afterwards |
| Cart: texts | filters `lm_cart_asunto`, `lm_cart_cuerpo` | — | Subject and HTML body |
| Reactivation: days | `lm_reactivacion_dias` | `60` | Days without buying to enter the segment |
| Reactivation: repeat | `lm_reactivacion_repetir_dias` | `180` | Never emails the same person again before this |
| Reactivation: texts | filters `lm_reactivacion_asunto`, `lm_reactivacion_cuerpo` | — | Subject and HTML body |
| Own consent source | filter `lm_com_tiene_consentimiento` | — | Hook in your own subscriptions plugin |
| Unsubscribes elsewhere | action `lm_com_baja_registrada` | — | Fires with (hash, user id, origin) when an unsubscribe is recorded |

## How it works
**Order limit.** Covers block checkout (Store API, answers 429), classic checkout and any REST routes you declare via filter. Administrators and shop managers are never limited. Blocks are counted per day in `lm_limite_pedidos_bloqueos` (no IPs).

**Sale alert.** Inert until configured. Fires once per order (meta `_lm_alerta_enviada`) when it reaches *processing*, *on-hold* or *completed*; skips $0 orders. The outcome (HTTP code only) is saved as an order note. Test: `wp eval "lm_alerta_probar();"`.

**Consent and unsubscribes (`lm-comunicaciones.php`).**
- Optional, unticked checkbox in the contact section of block checkout; the value is stored on the order (`_wc_other/lm/marketing_optin`). For account holders the user meta `lm_marketing_optin` = `1` wins (or the opt-out in `lm_optin_baja_fecha`). No data: **no** email.
- Unsubscribe link `?lm_com_baja=<hash>-<id>-<HMAC signature>`: it does not carry the email. Opening it asks for confirmation with a button (mail scanners that open links do not unsubscribe anyone); the Gmail/Outlook "unsubscribe" button (RFC 8058, `List-Unsubscribe-Post`) unsubscribes directly.
- Unsubscribes (`lm_comunicaciones_bajas`) and the send log (table `wp_lm_comunicaciones_envios`) store the SHA-256 of the email, not the email, and never the content.

**Abandoned cart.** Stores the email only if the person opted in and has not unsubscribed; otherwise deletes their row. Table `wp_lm_abandoned_carts`; cron `lm_cart_recovery_cron` hourly; one reminder after 1 hour and no repeat within 7 days; a `?lm_recover=<token>` link refills the cart.

**Reactivation.** Never sends by itself. Dry run: `wp eval "print_r(lm_reactivacion_send(true));"`. Real send: `wp eval "print_r(lm_reactivacion_send(false));"` (optional 2nd argument: limit). Only opted-in, not unsubscribed, and not emailed by this campaign in 180 days.

## Customer emails and personal data (read before enabling)
This is technical information, **not legal advice**, and the code **does not by itself guarantee** compliance with any law (Colombia's Law 1581 of 2012, EU GDPR/ePrivacy, …). Ask a lawyer in your country and update your privacy policy.
- Consent is a checkbox without double opt-in: someone may tick it with another person's email. Add double opt-in if you need it.
- The cart table stores the email in clear text (only with consent) for up to 30 days.
- `lm-alerta-venta` sends the customer name, city, total and products to Telegram or CallMeBot (third parties): mention it in your privacy policy or trim it with `lm_alerta_texto`.
- `lm-limite-pedidos` uses the IP WooCommerce stores on the order; a shared IP (office, mobile carrier) may block legitimate customers.

## How to undo
Delete the file. Options and data remain: `lm_limite_pedidos*`, `lm_alerta_*`, `lm_com_*`, `lm_comunicaciones_bajas`, `lm_cart_*`, `lm_reactivacion_*`; also `DROP TABLE wp_lm_abandoned_carts, wp_lm_comunicaciones_envios` and `wp cron event delete lm_cart_recovery_cron`. `_lm_alerta_enviada` metas stay on orders.

## FAQ
**Why a sale alert?** If outgoing email fails, new-order notifications stop and sales can go unnoticed; a second channel prevents that.
**What if CallMeBot or Telegram is down?** It never throws or blocks checkout; the outcome is saved as an order note.
**Can I change the email text?** Yes: with the filters above or by translating the `woo-tienda-segura` text domain (base strings are Spanish).
**Does the order limit affect normal customers?** Very rarely at the defaults; tune the thresholds to your shop.

## Limitations
Without HPOS the limit and reactivation do not work. HTML emails with Spanish base strings. No automated tests. See `SEGURIDAD.md` (Spanish).

## License
GPL-2.0-or-later. See `LICENSE`.

## Author and support
Andrés Valencia Tobón · [GitHub](https://github.com/quijotevitruvio) · [LinkedIn](https://www.linkedin.com/in/andr%C3%A9s-valencia-tob%C3%B3n/)
Apoya / Support: https://paypal.me/bibliotecologo
Developed for librosmedellin.com.
