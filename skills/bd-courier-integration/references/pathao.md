# Pathao Courier — Merchant API

Source: "Pathao Courier Merchant API Integration Documentation" (OAuth 2.0, `/aladdin/api/v1/*`).

## Contents
- Environments and credentials
- Auth (token + refresh)
- Endpoints (stores, orders, bulk, price, locations)
- Field rules for order creation
- Webhook
- Gotchas and doc inconsistencies

## Environments and credentials

| Env | Base URL |
|---|---|
| Sandbox | `https://courier-api-sandbox.pathao.com` |
| Production | `https://api-hermes.pathao.com` |

Credentials needed per environment: `client_id`, `client_secret`, `username` (merchant login email), `password`, plus the webhook secret. The merchant panel's "Merchant API Credentials" page shows production `client_id`/`client_secret`; the sandbox set is published on the same docs page. Keep all of them in config/env, never in code or the skill.

## Auth

OAuth 2.0 password grant. Cache the token in a persistent store (option, transient, DB) — do not mint one per request.

**Issue token** — `POST {base}/aladdin/api/v1/issue-token`, JSON body:
`client_id, client_secret, grant_type:"password", username, password`
Response: `token_type:"Bearer", expires_in:432000 (5 days), access_token, refresh_token`.

**Refresh** — same endpoint, body: `client_id, client_secret, grant_type:"refresh_token", refresh_token`. Same response shape. Store the *new* refresh token each time.

Every other call: `Authorization: Bearer <access_token>`, `Content-Type: application/json`.

Strategy: store `expires_at = now + expires_in - 3600`. Before each call, refresh if past `expires_at`. On a 401, refresh once and retry once; if that fails, fall back to the password grant.

## Endpoints

| Purpose | Method + path |
|---|---|
| Create store | `POST /aladdin/api/v1/stores` |
| List stores | `GET /aladdin/api/v1/stores` |
| Create order | `POST /aladdin/api/v1/orders` |
| Create bulk orders | `POST /aladdin/api/v1/orders/bulk` (async, 202) |
| Order short info | `GET /aladdin/api/v1/orders/{consignment_id}/info` |
| Price calculation | `POST /aladdin/api/v1/merchant/price-plan` |
| Cities | `GET /aladdin/api/v1/city-list` |
| Zones in city | `GET /aladdin/api/v1/cities/{city_id}/zone-list` |
| Areas in zone | `GET /aladdin/api/v1/zones/{zone_id}/area-list` |

Location responses are nested: `data.data[]` with `city_id/city_name`, `zone_id/zone_name`, `area_id/area_name/home_delivery_available/pickup_available`.

No cancel, return-request, or exchange endpoint is documented. Do not invent one; tell the user cancellation has to go through the Pathao panel/support, or ask them for the endpoint.

### Create store
Body: `name` (3–50), `contact_name` (3–50), `contact_number` (11), `secondary_contact` (opt, 11), `otp_number` (opt), `address` (15–120), `city_id`, `zone_id`, `area_id` (all required ints). Response says approval takes about one hour, so a new store cannot take orders immediately. `store_id` for orders comes from `GET /stores` (`data.data[].store_id`, `is_active` must be 1).

### Create order
Required: `store_id` (int), `recipient_name` (3–100), `recipient_phone` (11 chars), `recipient_address` (10–220), `delivery_type` (48 normal, 12 on-demand), `item_type` (1 document, 2 parcel), `item_quantity` (int), `item_weight` (kg, float, 0.5–10), `amount_to_collect` (int BDT, 0 for non-COD).
Optional: `merchant_order_id`, `recipient_secondary_phone` (11), `recipient_city`, `recipient_zone`, `recipient_area` (ints; **omit rather than send null**; Pathao derives them from the address if absent), `special_instruction`, `item_description`.

Response 200: `data.consignment_id`, `data.merchant_order_id`, `data.order_status` ("Pending"), `data.delivery_fee`.

### Bulk orders
Body `{ "orders": [ {order object}, ... ] }`. Response is 202 `data:true` — accepted only. Per-order outcomes (consignment ids) arrive via `order.created` webhooks, correlated by `merchant_order_id`. So a bulk flow *requires* unique `merchant_order_id` per order and a working webhook. The doc's bulk sample reuses the same `{{merchant_order_id}}` placeholder twice — don't copy that.

### Price calculation
Body: `store_id, item_type, delivery_type, item_weight, recipient_city, recipient_zone`. Response `data`: `price, discount, promo_discount, plan_id, cod_enabled, cod_percentage (0.01 = 1%), additional_charge, final_price`.

### Order info
Response `data`: `consignment_id, merchant_order_id, order_status, order_status_slug, updated_at, invoice_id`.

## Webhook

Configured in the merchant panel: Callback URL + secret + event checkboxes. Events (names from the sample payloads): `order.created`, `order.updated`, `order.pickup-requested`, `order.assigned-for-pickup`, `order.picked`, `order.at-the-sorting-hub`, `order.in-transit`, `order.received-at-last-mile-hub`, `order.assigned-for-delivery`, `order.delivered`, `order.partial-delivery`, `order.returned`, `order.delivery-failed`, `order.on-hold`, `order.paid`, `order.paid-return`, `order.exchanged`. The panel also lists Pickup Failed, Pickup Cancelled, Store Created/Updated, Return Id Created, Return In Transit, Returned To Merchant, but the doc shows no sample payload for them — match their event names defensively (log unknown events, do not 500).

Sample payload fields: `consignment_id, merchant_order_id, updated_at, timestamp (ISO UTC), store_id, event`, plus per event: `delivery_fee` (created/updated/pickup-requested), `collected_amount` (delivered, partial-delivery, paid-return, exchanged), `reason` (partial-delivery, returned, delivery-failed, on-hold, paid-return, exchanged — "might not be present"), `invoice_id` (order.paid).

Request headers from Pathao: `X-PATHAO-Signature` (= the secret you configured — a shared secret, **not an HMAC**) and `Content-Type: application/json`.

Integration handshake (panel's TEST button): the endpoint must be reachable, resolve within 3 redirects, have valid TLS, answer within 10 seconds, and **return a response header `X-Pathao-Merchant-Webhook-Integration-Secret`** carrying the integration value shown in the panel for that webhook. That value is shown next to the TEST button; store it as config (`PATHAO_INTEGRATION_SECRET`), separate from the signature secret. The doc does not state the status code; return 202 and confirm using the panel's TEST result. Send this header on every webhook response — it is harmless on real events and required for the handshake.

Receiver rules: compare `X-PATHAO-Signature` to the stored secret with a constant-time compare (`hash_equals`), then ack fast and process asynchronously.

## Gotchas and doc inconsistencies
- Refresh-token curl in the doc has a JSON syntax slip (missing comma after `"grant_type"`); the field set is what matters.
- The city-list section is captioned "summary of your current stores" — copy/paste error, it returns cities.
- Price-calc sample uses `{{issue_token}}` where every other call uses `{{access_token}}`; same bearer token.
- `item_weight` appears as a quoted string (`"0.5"`) in samples and "float" in the table; send a number (or string) consistently and test in sandbox.
- Phone length is "11 characters" — normalise to `01XXXXXXXXX` first (see `scripts/validate_order.py`).
- Statuses: the docs only show `Pending` for order status; see `status-map.md` for webhook-event mapping. Treat `order_status`/`order_status_slug` strings as open-ended.
- Money: `delivery_fee` can be fractional in webhooks (e.g. 83.46) but is an int in the create response. Store as decimal.
