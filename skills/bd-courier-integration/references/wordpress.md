# WordPress / WooCommerce implementation notes

Use when the target is a WordPress plugin, WooCommerce store, or a SaaS with a WP front (e.g. a logistics dashboard exposing `/wp-json/<namespace>/v1/webhook/<courier>`). The ready-to-adapt code is in `assets/php/`.

## Contents
- Architecture
- Credentials and settings
- HTTP client rules
- Token caching (Pathao)
- Webhook routes
- Async work and idempotency
- WooCommerce specifics
- Data model

## Architecture

```
Settings (encrypted/option) ──► Courier_Factory ──► Courier_Adapter (interface)
                                                    ├─ Pathao_Adapter
                                                    ├─ Carrybee_Adapter
                                                    ├─ Steadfast_Adapter
                                                    └─ RedX_Adapter
WC order ──► Shipment_Service::book($order, $courier) ──► adapter->create_shipment()
REST route /webhook/{courier} ──► verify ──► store raw ──► as_enqueue_async_action('process_event')
Status_Mapper (canonical states) ──► WC order status/notes
```

Keep courier-specific field names inside adapters; everything above the adapter speaks one normalized array (`name, phone, address, cod_amount, weight_grams, quantity, reference, note, description, city_id, zone_id, area_id, area_name, store_id`).

## Credentials and settings

- Store in options (`autoload=no`) or constants in `wp-config.php`; allow `define('PATHAO_CLIENT_SECRET', …)` to override. Never put secrets in JS, `wp_localize_script`, REST responses, or logs.
- Mask secrets in the settings UI (`type="password"`, show only whether set) and keep the old value when the field is submitted blank.
- Separate sandbox/production settings and an explicit "Environment" switch; show a visible banner when sandbox is on so nobody books test parcels on a live account (Steadfast has no sandbox — warn instead).
- Capability checks (`manage_woocommerce`) and nonces on every admin action (book, cancel, refresh status).

## HTTP client rules

- Use `wp_remote_request()` with `timeout` 15–30 s, `sslverify` true, `headers`, JSON body via `wp_json_encode`.
- Treat `WP_Error`, non-2xx, and invalid JSON as distinct failures; surface the courier's `message`/`errors` to the admin (not to customers).
- **Do not auto-retry booking POSTs on timeout** except Steadfast (unique `invoice` makes retry safe). For Pathao/Carrybee/RedX no dedupe is documented, so a retry after an ambiguous timeout can double-book. Instead: record `booking_attempted` on the order, and on timeout check the courier (Carrybee details by merchant id; Pathao/RedX via panel or list) or let staff confirm before retrying.
- Rate limit: Steadfast 1,000/min (6,000 booking) and 10 auth failures → 60 min lockout; never loop on 401.
- Redact `Api-Key`, `Secret-Key`, `Client-Secret`, `Authorization`, `API-ACCESS-TOKEN` from debug logs (`wc_get_logger()` with a source per courier).

## Token caching (Pathao)

```php
$tok = get_option('myplugin_pathao_token'); // ['access','refresh','expires_at']
if (!$tok || $tok['expires_at'] < time() + 300) { /* refresh, else password grant */ }
```
Guard refresh with a short lock (`set_transient('pathao_refresh_lock', 1, 30)`) so concurrent requests don't each refresh and invalidate each other's refresh token.

## Webhook routes

```php
register_rest_route('myplugin/v1', '/webhook/(?P<courier>pathao|carrybee|steadfast|redx)', [
  'methods' => 'POST',
  'callback' => [$handler, 'handle'],
  'permission_callback' => '__return_true', // auth is verified inside, against the raw body
]);
```
- `permission_callback` must be explicit (WP 5.5+ warns otherwise) — authenticate inside the callback instead, since signatures need the raw body (`$request->get_body()`).
- Don't use `$request->get_json_params()` before verifying.
- Return `new WP_REST_Response($body, 202|200)`; add handshake headers with `$response->header()`.
- Exclude the route from: page cache/CDN rules, security plugins that block unauthenticated REST (Wordfence/iThemes "disable REST API"), Cloudflare bot rules, maintenance mode, basic auth.

## Async work and idempotency

- Use Action Scheduler (bundled with WooCommerce): `as_enqueue_async_action('myplugin_process_courier_event', [$event_id], 'myplugin')`.
- Events table (not post-meta) with `UNIQUE KEY dedupe (courier, dedupe_key)`; insert with `INSERT IGNORE`/`$wpdb->insert` and check rows affected.
- Index shipments by `(courier, consignment_id)` and by `reference`.

## WooCommerce specifics

- Use CRUD (`wc_get_order`, `$order->update_meta_data()`, `$order->save()`) — not `update_post_meta` — so HPOS stores work; declare HPOS compatibility with `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)`.
- Courier reference per order: use `"{prefix}-{$order->get_order_number()}"` sanitized to `[A-Za-z0-9_-]` (Steadfast invoice charset; safest for all). Don't use order *ID* if sequential-order-number plugins are active — pick one and stay consistent.
- COD amount: `0` if the order is already paid (payment method not COD), otherwise `$order->get_total()` rounded to int taka (Pathao `amount_to_collect` int; Carrybee int 0–100000; Steadfast up to 1,000,000; RedX numeric). Reject/flag Carrybee COD > 100000 before calling.
- Weight: sum line weights via `wc_get_weight($w, 'g')`; Pathao needs kg (0.5–10); clamp, but flag clamped orders to staff.
- Address: Bangladesh stores often use state = district. Courier area/zone ids: cache the courier location trees (transients/option) and let staff override on the order screen; don't geocode on every booking.
- Phone: normalize `+8801XXXXXXXXX`/`8801…`/`01…` → `01XXXXXXXXX` (11 digits); reject landlines/invalid prefixes with a clear admin notice.
- Bulk booking from the Orders list via bulk actions: queue each order through Action Scheduler (Steadfast/Pathao/Carrybee bulk endpoints exist, but per-order error handling is simpler to reason about; use bulk endpoints only for large batches and map results by reference).
- Order notes for every API call outcome (booked, failed + reason, status change); customers never see raw courier errors.

## Data model (minimal)

`wp_<prefix>_shipments`: id, order_id, courier, consignment_id, tracking_code, reference, store_id, status_raw, status, cod_expected, cod_collected, delivery_fee, invoice_id, last_event_at, created_at, raw_last (json).
`wp_<prefix>_courier_events`: id, courier, dedupe_key (unique), consignment_id, event, payload (longtext), received_at, processed_at, error.
