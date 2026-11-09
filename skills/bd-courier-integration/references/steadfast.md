# Steadfast Courier (Packzy API v1)

Source: "Steadfast API guide" (two uploaded PDFs, identical content). Base URL: `https://portal.packzy.com/api/v1`.

## Contents
- Auth and limits
- Endpoints
- Booking rules
- Status values
- Fraud / customer check
- Webhook
- Gotchas

## Auth and limits

Two headers on every call (except `/ping`): `Api-Key`, `Secret-Key`; `Content-Type: application/json` on POST. No login or token refresh. Keys belong to one *profile* — a business profile's key only sees that business's parcels and payouts; a profile can hold several named keys (one per integration), issued and revoked on the "Your API keys" page. Use one key per integration so retiring one doesn't break another.

Failure handling:
- Wrong/missing credentials → `401`. **10 failures in 5 minutes from one address+key → `429` for 60 minutes, even with the correct key afterwards.** Never retry a 401 in a loop; fix the keys first.
- Rate limits: 1,000 req/min general, 6,000/min for booking.
- Other codes: `400` malformed (e.g. missing `data` on bulk), `403` inactive account or KYC resubmission needed, `422` field rejected (read `errors`), `500` Steadfast's fault — retry with the same invoice; duplicates are refused so a retry cannot double-book.
- Some endpoints return per-item failures inside a `200` — always read the body.
- `GET /ping` needs no credentials; use it for connectivity checks only. Use `GET /get_balance` as the cheap "are my keys valid" probe.

## Endpoints (18)

| Purpose | Method + path |
|---|---|
| Connectivity | `GET /ping` |
| Book one parcel | `POST /create_order` |
| Bulk (≤500) | `POST /create_order/bulk-order` |
| Bulk, per-field messages (**prefer for new work**) | `POST /create_order/bulk-order/extended` |
| Status by consignment id | `GET /status_by_cid/{consignment_id}` |
| Status incl. return progress | `GET /status_with_return_status_by_cid/{consignment_id}` |
| Status by invoice | `GET /status_by_invoice/{invoice}` |
| Status by tracking code | `GET /status_by_trackingcode/{tracking_code}` |
| Full tracking history | `GET /trackings_by_invoice/{invoice}` |
| Pickup request | `POST /create_pickup_request` |
| Return request | `POST /create_return_request` |
| List / one return request | `GET /get_return_requests` (`?page=n`, 10/page), `GET /get_return_request/{id}` |
| Balance | `GET /get_balance` → `current_balance` |
| Payouts | `GET /payments`, `GET /payments/{payment_id}` (digits only; `SFC-88213` ≡ `88213`) |
| Thanas | `GET /police_stations` (changes rarely; cache it) |
| Customer record | `GET /fraud_check/score/{phone}` |

## Booking rules (`POST /create_order`)

Required: `invoice`, `recipient_name`, `recipient_phone`, `recipient_address`, `cod_amount`.
Optional: `note` (≤480), `alternative_phone`, `recipient_email` (domain is checked for deliverability), `item_description` (≤255), `total_lot` (default 1), `delivery_type` (0 home — default, 1 point).

- `invoice`: your own order number; letters, digits, hyphens, underscores only; **must be unique** — that uniqueness is what makes retries safe. Stored to 100 chars.
- `recipient_phone`: exactly 11 digits starting `01`.
- `cod_amount`: BDT, 0 for prepaid, max 1,000,000.
- There is **no weight field and no area/zone field** — the address string is the only location input.
- Response 200: `consignment.{consignment_id, invoice, tracking_code, tracking_link, status:"in_review", cod_amount, ...}`. Persist `consignment_id` and `tracking_code`. Rejections: `{"status":400,"errors":{field:[...]}}` — parcel not booked.
- Every parcel starts `in_review` and is approved shortly after.

Silent mutation — Steadfast does not reject, it changes your data:
- It replaces each of `{ } ; < > $` with a space in names, addresses and notes (the doc's character list is garbled by HTML; treat that set as the minimum and avoid other markup/special characters too).
- It **truncates** over-long values: invoice 100, recipient_name 100, recipient_phone 40, recipient_address 490, note 480.
Pre-clean and pre-truncate on your side if the exact text matters (labels printed from your system should match what the rider sees).

Bulk: body `{ "data": [ {order}, … ] }`, at most 500. Per-item result in `data[]`: `invoice, status ("error"/success), error[] (sentences), consignment_id, tracking_code`. The extended endpoint also accepts `recipient_email, alternative_phone, item_description, total_lot` per order; the older bulk endpoint reads those from the top level of the request instead. Always map results back by `invoice`.

Pickup: `POST /create_pickup_request` — `address_id` (from the portal's Pickup Addresses page), `police_station_id` (from `/police_stations`), `address` (≤255), `contact_number` (11 digits, prefix 013–019), optional `note` (≤500), `estim_qty`. Returns **201**. A second request for the same address while one is pending returns `409 PICKUP_REQUEST_EXISTS` — safe to treat as success.

Returns: `POST /create_return_request` with one of `consignment_id | invoice | tracking_code`, optional `reason` (≤500). 201. `422` if the parcel is already delivered, already coming back, or a request is still open. Statuses: `pending, approved, processing, completed, cancelled`. This is the way to pull a parcel back *before delivery* — there is no separate cancel endpoint.

## Status values

Status endpoints are cached 60 seconds; polling faster is pointless — use the webhook.

Delivery statuses: `pending`, `in_review`, `hold` (address/payment issue → support), `delivered_approval_pending`, `partial_delivered_approval_pending`, `cancelled_approval_pending`, `unknown_approval_pending`, `delivered`, `partial_delivered`, `cancelled`, `exceptional` (lost/damaged → support), `unknown`.

**Anything ending `_approval_pending` is the rider's word, not Steadfast's confirmation. Don't settle books on it** — wait for `delivered`, `partial_delivered` or `cancelled`.

Return statuses (only from `/status_with_return_status_by_cid/{id}`, only for parcels that did not fully arrive; **spelling is `proccessing` with two c's — match it exactly**): `partial_delivered_return_proccessing | _rider_assigned | _received`, `cancelled_return_proccessing | _rider_assigned | _received`. This is the only endpoint that tells you a returned parcel is physically back — use it for stock reconciliation.

## Fraud / customer check

`GET /fraud_check/score/{phone}` returns: `delivery_ratio`, `cancellation_ratio` (whole-percent, rounded down independently so they can sum to 99), `volume_band` (none/low/medium/high/very_high), `volume_range` ("7", "10+", "25+", "50+", "100+"), `delivered_count`, `cancelled_count`, `total_reports`, `fraud_categories` (code→count), plus `score`/`level`/`reasons` which are **permanently null/empty** (`scoring_disabled: true`).
- Ratios are `null` (not 0) when nothing has finished for that number. **Never render null as a clean record.**
- `delivered_count`/`cancelled_count` are only filled for merchants booking regularly (parcels on ≥3 of the last 7 days, or >3 approved in the last 24 h); otherwise null means "not shown".
- It is built from every merchant's history with that number. Treat it as a signal, never a verdict; don't auto-reject orders on it alone, and be careful about showing raw third-party data to staff beyond the ratios.
- Rate limit ≈ 4 checks per parcel booked on your busiest day last week + 10; over it → 429.
- **The older `GET /fraud_check/{phone}` stopped returning parcel counts on 27 Sept 2026 — that date has passed.** Any code still reading `total_delivered`/`total_cancelled` from it is broken; migrate to `/score/`.

## Webhook

Configured in the portal: Callback URL (https, answer 2xx within 5 s) and an optional auth token (letters, digits, `- _ . =` only). Request headers: `Content-Type: application/json`, `Authorization: Bearer <token>`, **`X-Signature`** = hex HMAC-SHA256 of the *raw* body keyed with the token, `Idempotency-Key` (same value on retries of the same event), `User-Agent: Steadfast-Webhook/1.0`.

Verification: compute `hash_hmac('sha256', $rawBody, $token)` and compare with `X-Signature` in constant time (`hash_equals`). Check against the raw bytes, before JSON-decoding or any framework re-serialisation. Dedupe on `Idempotency-Key`.

Delivery/retries: any 2xx = success. 5xx or unreachable → retried after 30 s and 2 min (3 attempts total). **4xx is not retried** — so don't return 4xx for "I can't process this yet"; only for genuinely bad auth.

Events: `delivery_status`, `tracking_update`, `consignment_update`, `return_list_accepted`, `payment_request`, `cancel_request`, `return_request`, `pickup_request`, `user_update`.
Only `delivery_status` has a documented payload: `notification_type, consignment_id, invoice, cod_amount, status (pending|delivered|partial_delivered|cancelled|unknown), delivery_charge, tracking_message, updated_at`. Store raw payloads for the others until you've seen real ones. Answer `{"status":"success"}`.

Note the webhook `status` set is coarser than the polling status set (no `in_review`/`hold`/approval-pending variants) — use polling or `tracking_update` for finer states.

## Gotchas
- Duplicated invoice numbers: `status_by_invoice` answers for the most recent one. Prefix invoice with a site/environment tag if multiple stores or a staging site share the account.
- WooCommerce order numbers with a `#` or spaces violate the invoice charset — sanitize (e.g. `WC-1042`).
- Pre-launch: `create_order` "books a real parcel" even from curl examples — use a throwaway invoice and cancel via return request / support, and never paste live keys into docs, tickets, or repos.
- No sandbox is documented for Steadfast. Test with a clearly named invoice (`TEST-…`) and a real but cooperative phone number, and confirm with support how to void test parcels.
