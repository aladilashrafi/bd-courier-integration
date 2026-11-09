# Webhook design for the four couriers

## Contents
- Comparison table
- Universal receiver pattern
- Per-courier verification and handshake
- Testing
- Operational checklist

## Comparison

| | Pathao | Carrybee | Steadfast | RedX |
|---|---|---|---|---|
| Auth scheme | Shared secret in `X-PATHAO-Signature` | Shared secret in `X-CB-Webhook-Integration-Header` | `Authorization: Bearer <token>` + `X-Signature` = HMAC-SHA256(raw body, token) | Token in callback URL query string |
| Real signature? | No | No | **Yes** | No |
| Handshake | Respond with header `X-Pathao-Merchant-Webhook-Integration-Secret: <integration value>`; reply within 10 s | POST `{"event":"webhook.integration"}`; reply **202** and echo `X-CB-Webhook-Integration-Header: <secret>` | Callback must answer 2xx within 5 s | None documented |
| Event name field | `event` (`order.delivered`) | `event` (`order.delivered`) | `notification_type` (`delivery_status`) + `status` | `status` (`delivered`) |
| Consignment id field | `consignment_id` | `consignment_id` | `consignment_id` | `tracking_number` |
| Your reference field | `merchant_order_id` | `merchant_order_id` | `invoice` | `invoice_number` |
| Timestamp field | `timestamp` (ISO UTC), `updated_at` | `timestamptz` | `updated_at` | `timestamp` |
| Retries | Not documented | Not documented | 30 s, then 2 min (3 attempts); 4xx not retried | Not documented |
| Idempotency header | No | No | `Idempotency-Key` | No |
| Cash fields | `collected_amount`, `delivery_fee` | `collected_amount`, `cod_fee`, `delivery_fee` | `cod_amount`, `delivery_charge` | none |
| Settlement signal | `order.paid` (`invoice_id`) | `order.paid` (`invoice_id`) | `payment_request`; `GET /payments` | none |

## Universal receiver pattern

One endpoint per courier (`/webhook/pathao`, `/webhook/carrybee`, `/webhook/steadfast`, `/webhook/redx`) — distinct URLs make secrets, logs and debugging separate. Steps, in order:

1. Read the **raw body** (`file_get_contents('php://input')` / `req.rawBody`) before any parsing.
2. Authenticate (see below) with a constant-time comparison. On failure return 401 and log the attempt (no secrets in the log).
3. Handle the handshake/integration event if present and return the exact response the courier wants.
4. Persist the raw payload + headers (minus auth) to an events table; compute the dedupe key; if already seen, return 2xx and stop.
5. **Return 2xx immediately.** Do the work (status mapping, order updates, emails, SMS) in a queue/async job (Action Scheduler in WordPress, a job queue elsewhere). Couriers time out at 5–10 s, and Steadfast treats 5xx as "retry".
6. In the worker: look up the shipment by `consignment_id` (fall back to your reference id), map status → canonical, apply the no-regression rule (`status-map.md`), update the order, add a note.
7. Unknown event, unknown shipment, or malformed field → still 2xx (log + alert). A 4xx/5xx that you *chose* to send tells some couriers to stop sending.

Exception to rule 7: bad authentication → 401/403. Steadfast won't retry a 4xx, which is right for bad auth and wrong for "not ready yet".

## Per-courier details

**Pathao**: compare `X-PATHAO-Signature` to the stored secret (`hash_equals`). Always add response header `X-Pathao-Merchant-Webhook-Integration-Secret` (value from the panel, config `PATHAO_INTEGRATION_SECRET`). Return 202 (doc doesn't state the code; confirm with the panel's TEST button). Payload may be sent for any event you ticked — the panel's per-event TEST buttons let you fire samples.

**Carrybee**: first handle `event == "webhook.integration"` → 202 + echo header. For every request, compare the `X-CB-Webhook-Integration-Header` request header with your secret. Echo the header in responses too. `order.create-failed` has no consignment id — correlate by `merchant_order_id`/`position` for bulk bookings.

**Steadfast**: `expected = hash_hmac('sha256', $raw, $token)`; `hash_equals($expected, $_SERVER['HTTP_X_SIGNATURE'])`. Optionally also check the bearer token. Dedupe on `Idempotency-Key`. Reply `{"status":"success"}` with 200.

**RedX**: compare the `token` query parameter with the stored token (`hash_equals`), require HTTPS, and strip the query string from access logs. There is no signature, so treat the payload as lower-trust: before acting on a `delivered` event for a high-value COD order, optionally confirm with `GET /parcel/info/{id}`.

## Testing

- `scripts/webhook_tool.py simulate <courier> <event> --url <endpoint> --secret …` posts a realistic payload with the right auth headers; `verify` computes/validates Steadfast signatures offline.
- Use the courier panel's TEST button after your endpoint passes the local simulation. Public reachability: the receiver must be on a public HTTPS URL with a valid certificate (a tunnel works for local dev; WAF/Cloudflare "bot fight" rules and basic-auth staging gates are the usual reason a handshake fails — allow-list the route).
- Replay test: send the same event twice and confirm the second is a no-op; send an older event after a newer one and confirm state does not regress.

## Operational checklist

- [ ] Secrets in config/env, rotated if a PDF/screenshot containing them was shared
- [ ] Raw-body signature verification, constant-time compare
- [ ] 2xx within a few seconds; heavy work async
- [ ] Dedupe table with unique key
- [ ] Unknown events logged, not errors
- [ ] Alert if no webhook has been received for N hours while shipments are active (couriers can silently disable failing endpoints) — and a polling fallback job for non-terminal shipments (respect Steadfast's 60 s cache and rate limits)
- [ ] Webhook route excluded from page caching, security plugins' rate-limit/JSON-block rules, and login/auth redirects
