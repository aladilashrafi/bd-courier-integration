# RedX — OpenAPI (v1.0.0-beta)

Source: "RedX OpenAPI Documentation" (REST + webhook).

## Contents
- Environments and auth
- Endpoints
- Parcel creation rules
- Cancel / update
- Webhook
- Gotchas

## Environments and auth

| Env | Base URL |
|---|---|
| Sandbox | `sandbox.redx.com.bd/v1.0.0-beta` (use `https://`) |
| Production | `openapi.redx.com.bd/v1.0.0-beta` (use `https://`) |

Auth: a pre-issued JWT per environment (shown on the RedX API page), sent as
```
API-ACCESS-TOKEN: Bearer <jwt>
```
(Custom header name — *not* `Authorization`.) No token exchange documented. Sandbox transactions are simulated and do not touch real data; test fully there first.

## Endpoints

| Purpose | Method + path |
|---|---|
| Track parcel | `GET /parcel/track/{tracking_id}` → `tracking[]` (`message_en`, `message_bn`, `time`) |
| Parcel details | `GET /parcel/info/{tracking_id}` → `parcel{…}` |
| Create parcel | `POST /parcel` → `{ "tracking_id": "…" }` |
| Update/cancel parcel | `PATCH /parcels` |
| All areas | `GET /areas` |
| Areas by postcode | `GET /areas?post_code=1207` |
| Areas by district | `GET /areas?district_name=Dhaka` |
| Create pickup store | `POST /pickup/store` |
| List pickup stores | `GET /pickup/stores` |
| Pickup store details | `GET /pickup/store/info/{pickup_store_id}` |
| Charge calculator | `GET /charge/charge_calculator?delivery_area_id=&pickup_area_id=&cash_collection_amount=&weight=` → `{deliveryCharge, codCharge}` |

Areas: `{id, name, post_code, division_name, zone_id}`. Cache the area list (changes rarely) and resolve the customer's area from it — RedX *requires* `delivery_area` (name) and `delivery_area_id` on every parcel, so an address → area step is mandatory (post-code match first, then fuzzy name match, then ask the customer/staff to pick).

## Parcel creation (`POST /parcel`)

Required: `customer_name`, `customer_phone`, `delivery_area` (area **name**), `delivery_area_id` (int), `customer_address`, `cash_collection_amount` (BDT; sample shows a string), `parcel_weight`, `value` (declared value; basis for compensation).
Optional: `merchant_invoice_id`, `instruction`, `is_closed_box`, `pickup_store_id`, `type` (mainly for reverse shipments), `parcel_details_json` (array of `{name, category, value}` — all three required per item when present).

- Weight unit is **grams**: `parcel_weight` samples show `500`, and the charge calculator says grams. The create-parcel table says "appropriate units (e.g., kg, g)" — ambiguous; send integer grams and confirm in sandbox.
- Always send `Content-Type: application/json` (the `pickup/store` curl in the doc omits it; add it).
- `pickup_store_id` is optional in the table but you should always send it when the merchant has more than one store; otherwise RedX picks the default.
- Response is just `tracking_id` — no fee. Call the charge calculator separately if you need to show/store the delivery charge. `info` later returns `charge`, `status`, `delivery_type` (regular/express), `pickup_location`.
- Parcel `info.status` examples include `pickup-pending` (not in the webhook status list) — treat status strings as open-ended.

## Cancel / update

`PATCH /parcels`:
```json
{
  "entity_type": "parcel-tracking-id",
  "entity_id": "<tracking_id>",
  "update_details": { "property_name": "status", "new_value": "cancelled", "reason": "..." }
}
```
Response `{ "success": true, "message": "Request Accepted" }` — it's a request, not confirmation; verify via info/webhook. `property_name` can be other parcel properties (the doc mentions e.g. `delivery_address`) but only the status→cancelled example is spelled out; don't assume other properties/values are accepted — test in sandbox.

## Pickup store
`POST /pickup/store`: `name`, `phone`, `address`, `area_id` (all required) → `{id, name, address, area_name, area_id, phone}`. Pickup-store `area_id` comes from `/areas`. Phone in responses appears in `8801…` form.

## Webhook

Merchant supplies a callback URL (HTTPS recommended). RedX POSTs `application/json`. **Credentials go in the query string** (`https://example.com/callback?token=<token>`); there is *no* signature or auth header in the documented request. So:
- Make the token long and random, compare it in constant time, require HTTPS, and keep it out of logs/analytics (log the path, not the query).
- Rotate it if it leaks; consider additionally allow-listing nothing you can't verify — the docs publish no source IPs.

Payload: `tracking_number, timestamp, status, message_en, message_bn, invoice_number` (`invoice_number` = your `merchant_invoice_id`).

Status values:

| status | Meaning |
|---|---|
| `ready-for-delivery` | Parcel received from merchant |
| `delivery-in-progress` | Dispatched to rider |
| `delivered` | Delivered by rider |
| `agent-hold` | On hold with agent |
| `agent-returning` | Return in progress |
| `returned` | Returned |
| `agent-area-change` | Area change requested / in progress |

No retry policy, timeout, or expected response code is documented — return 200 quickly and be idempotent on `(tracking_number, status, timestamp)`.

## Gotchas
- Doc JSON samples contain trailing commas and `{{placeholders}}` unquoted — build payloads with a JSON encoder, not by copying the curl.
- `cash_collection_amount` and `parcel_weight` are typed `string` in the request table but integers in info/response; send numbers (or numeric strings consistently) and check sandbox.
- No documented settlement/invoice webhook or payout API — COD reconciliation must come from the RedX dashboard/statements.
- `agent-area-change` means the delivery area changed after booking — surface it to staff because the charge may change.
