# Carrybee — Developers' API v2.0

Source: "Carrybee Developers' API v2.0" (`/api/v2/*`, JSON over HTTPS).

## Contents
- Environments and auth
- Conventions (units, enums)
- Endpoints
- Field rules
- Webhook
- Gotchas and doc inconsistencies

## Environments and auth

| Env | Base URL |
|---|---|
| Production | `https://developers.carrybee.com` |
| Sandbox | `https://sandbox.carrybee.com` |

Each environment has its own credentials. Three values, sent on **every** request:
```
Client-ID: <id>
Client-Secret: <secret>
Client-Context: <context>
Content-Type: application/json   (POST/PUT)
```
No token exchange. Keep the secret server-side (never in browser JS or a client-visible option).

Errors: `{ "error": true, "message": "..." }`; validation (422): `{ "error": true, "message": "Validation error", "causes": { "<field>": [ { "type": "...", "attribute": {} } ] } }`. Success responses carry `"error": false`. Status codes: 200, 201 created, 202 accepted (async), 404, 422, 4xx/5xx.

## Conventions

| Field | Values |
|---|---|
| `delivery_type` | 1 = Normal, 2 = Express |
| `product_type` | 1 = Parcel, 2 = Book, 3 = Document |
| `item_weight` | **grams**, integer 1–25000 |
| `item_quantity` | 1–200 |
| `collectable_amount` | BDT, 0–100000 (note the ceiling — higher COD must be split or rejected up front) |
| timestamps | ISO 8601 with timezone |

## Endpoints

| Purpose | Method + path |
|---|---|
| Cities | `GET /api/v2/cities` |
| Zones | `GET /api/v2/cities/{city_id}/zones` |
| Areas | `GET /api/v2/cities/{city_id}/zones/{zone_id}/areas` |
| Area suggestion | `GET /api/v2/area-suggestion?search=…` (min 3 chars) → `data.items[]` with city/zone/area ids+names |
| Resolve address | `POST /api/v2/address-details` body `{query}` (min 10 chars) → `data.city_id, data.zone_id` |
| Create store | `POST /api/v2/stores` (201) |
| List stores | `GET /api/v2/stores` → `data.stores[]` incl. `is_active`, `is_approved`, `is_default_pickup_store` |
| Create order | `POST /api/v2/orders` (201) |
| Bulk orders | `POST /api/v2/orders-bulk` (202, body is a JSON **array**) |
| Order details | `GET /api/v2/orders/{consignment_id}/details` (accepts consignment id **or** merchant tracking id) |
| Cancel order | `POST /api/v2/orders/{consignment_id}/cancel` body `{cancellation_reason (<200)}` (202) |
| Reverse pickup (standalone) | `POST /api/v2/orders/reverse-pickup` (201) |
| Reverse pickup from order | `POST /api/v2/orders/{consignment_id}/reverse-pickup` (order must be delivered/partially delivered; omitted fields default to the original) |
| Exchange | `POST /api/v2/orders/{consignment_id}/exchange` (new consignment id prefixed `EX`) |

No price-quote endpoint is documented; fees come back on order creation (`cod_fee`, `delivery_fee`).

## Field rules

**Store**: `name` 3–30, `contact_person_name` 3–30, `contact_person_number` valid phone, `address` 3–100, `city_id`/`zone_id`/`area_id` required; optional `contact_person_secondary_number`, `lat`, `lng`. Response `data` is null — fetch `GET /stores` for the new `id`, and wait for `is_approved`.

**Create order**: required `store_id` (string), `delivery_type`, `product_type`, `recipient_phone`, `recipient_name` (2–99), `recipient_address` (10–200), `item_weight` (g). Optional: `merchant_order_id` (<50), `city_id`/`zone_id`/`area_id` (ints; if omitted, resolve via `address-details` or let the server decide — test in sandbox which it accepts), `special_instruction` (<255), `product_description` (<255), `item_quantity`, `collectable_amount`, `is_closed_box` (false), `is_exchange` (false), secondary phone (see gotcha).
Response `data.order`: `consignment_id, store_id, merchant_order_id, collectable_amount, cod_fee, delivery_fee`.

**Bulk**: array of the same order objects; the response only says "Accepted for processing". Each order's result comes by webhook (`order.created` or `order.create-failed` with `position`, `reason`, `causes`). Validation errors are keyed by array position, e.g. `"0.city_id"`. Use unique `merchant_order_id`s and keep a `position → merchant_order_id` map.

**Reverse pickup** (standalone): `store_id, product_type, customer_phone, customer_name, customer_address, city_id, zone_id, item_weight` required; optional `area_id`, `merchant_order_id`, `item_quantity`, `product_value` (1–100000), `product_description`, `special_instruction`, secondary phone.

**Exchange**: `collectable_amount`, `item_quantity` (default 1), `item_weight`, `merchant_order_id`, `product_description`, `special_instruction` all optional.

## Webhook

Configured on the "Webhook Integration" page (URL + secret + events). Carrybee sends the secret in the header **`X-CB-Webhook-Integration-Header`** — it is a shared secret, not an HMAC. Verify it on every request (constant-time compare).

Integration handshake: Carrybee POSTs `{"event":"webhook.integration"}`. The endpoint must be reachable, resolve within 3 redirects, have valid TLS, answer within the timeout, **return HTTP 202**, and **echo the header `X-CB-Webhook-Integration-Header` with the exact secret value** in the response.

Payload common fields: `event, store_id, consignment_id, merchant_order_id, timestamptz`. Events and extra fields:
- `order.created`: `collectable_amount, cod_fee, delivery_fee` (no `consignment_id` on failures)
- `order.create-failed`: `position, reason, causes` (no consignment id)
- `order.updated`: `collectable_amount, cod_fee, delivery_fee`
- `order.pickup-requested`, `order.assigned-for-pickup`, `order.picked`, `order.pickup-failed`, `order.pickup-cancelled`, `order.at-the-sorting-hub`, `order.on-the-way-to-central-warehouse`, `order.at-central-warehouse`, `order.in-transit`, `order.received-at-last-mile-hub`
- `order.assigned-for-delivery`: `attempt, agent_name, agent_phone`
- `order.delivery-on-hold`, `order.delivery-failed`: `attempt, reason, remarks` (both may be empty)
- `order.delivered`: `collected_amount, attempt, remarks`
- `order.partial-delivery`, `order.paid-return`: `collected_amount, attempt, reason, remarks`
- `order.returned`, `order.exchange`: `reason, remarks` (exchange also `collected_amount`)
- `order.paid`: `invoice_id`
- `order.returned-at-sorting`, `order.returned-in-transit`, `order.returned-to-merchant`

Consignment ids look like `FX1212124433`; exchange ids start `EX`; invoice ids like `IX…`.

## Gotchas and doc inconsistencies
- **Create-order secondary phone is spelled `recipient_secendary_phone` in the doc's field table** (typo) but `recipient_secondary_phone` in the order-details response. Test both in sandbox before shipping; don't silently pick one.
- `product_description` on create vs `item_description`/`product_description` naming differs from Steadfast/Pathao — use the adapter, not shared field names.
- `store_id` is a string here, an int for Pathao/RedX.
- The bulk body is a bare array (not `{orders:[…]}` like Pathao).
- Webhook timestamps are `timestamptz`, not `timestamp`.
- Order details response uses `transfer_status` (not `status`) for the state string; also `attempt`, `reason`, `invoice_id`, `payment_status`.
- Cancel is async (202) — wait for the state change via details/webhook rather than assuming it succeeded.
- For events that don't carry a consignment id (`order.create-failed`), match on `merchant_order_id` / `position`.
- Money fields are sometimes strings (`collectable_amount`, `delivery_fee` in responses) and sometimes numbers (webhooks) — cast before arithmetic.
