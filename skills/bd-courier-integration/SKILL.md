---
name: bd-courier-integration
description: Build, review and debug integrations with Bangladeshi courier APIs — Pathao Courier, Carrybee, Steadfast (Packzy) and RedX — covering shipment booking, COD amounts, bulk orders, area/zone/city lookup, tracking and status sync, returns/cancellation/exchange, payouts and COD reconciliation, and webhook receivers (signature/secret verification, handshakes, idempotency, retries). Use this skill whenever the user mentions any of these couriers, a Bangladesh logistics/delivery API, courier webhook callbacks, consignment/tracking IDs, "book a parcel", COD parcel booking from WooCommerce/WordPress/Laravel/Node, multi-courier shipping, or a logistics SaaS like BanglaTrack — even if they don't name the courier's API or ask for "integration" explicitly.
license: MIT
metadata:
  author: Al Adil Ashrafi (Goynar Sur)
  homepage: https://github.com/aladilashrafi/bd-courier-integration
  version: "1.0.0"
---

# Bangladeshi courier integration (Pathao · Carrybee · Steadfast · RedX)

The four couriers solve the same problem with incompatible auth, units, enums, status words and webhook schemes. The job is to integrate them **safely and uniformly**: one normalized order in, one canonical status model out, courier quirks isolated in adapters.

All facts here come from the couriers' own docs (Pathao Merchant API, Carrybee v2.0, Steadfast/Packzy v1, RedX OpenAPI v1.0.0-beta). Where a doc is silent, ambiguous, or has a typo, the reference files say so — surface those to the user instead of guessing, because a wrong guess either double-books a parcel or silently drops a COD amount.

## Workflow

1. **Scope it.** Which couriers? Which operations (book, track, cancel/return, bulk, webhook, payout reconciliation)? Which stack (WordPress/WooCommerce plugin, Laravel, Node, SaaS backend)? Sandbox or live? Ask only what you can't infer; if the user pasted docs or code, start from those.
2. **Read only what you need:**
   - the courier file(s) in `references/` for the couriers in scope
   - `references/webhooks.md` for any webhook work
   - `references/status-map.md` for any status/tracking sync
   - `references/wordpress.md` for WordPress/WooCommerce targets
3. **Design around the adapter pattern** (below). Don't scatter courier field names through business code.
4. **Implement.** For WordPress, start from `assets/php/courier-adapters.php` and `assets/php/webhook-receiver.php` (templates — adapt names, settings and store logic). For other stacks, port the same structure; the PHP is the readable spec.
5. **Verify before handing over:** run `scripts/validate_order.py` on sample orders, `scripts/status_map.py --self-test`, and simulate every webhook with `scripts/webhook_tool.py`. Test in the courier sandbox if one exists.
6. **Report** what is verified vs assumed (see "Unknowns to surface").

## Credentials: handle with care

- Secrets (Pathao client secret/password/webhook secret, Carrybee client secret, Steadfast `Api-Key`/`Secret-Key`/webhook token, RedX JWT) live in environment/config or encrypted options — never in source, JS, REST responses, logs, tickets or this skill.
- Courier doc PDFs and portal screenshots often embed **live keys and webhook secrets**. Don't copy them into code, tests or examples; use placeholders. If the user shared such a document, mention once that the secrets in it should be treated as exposed and rotated if the file went anywhere untrusted.
- Steadfast has no documented sandbox and its curl example "books a real parcel"; never run live bookings as a test without the user's explicit go-ahead and a throwaway invoice.

## Cross-courier cheat sheet

| | Pathao | Carrybee | Steadfast | RedX |
|---|---|---|---|---|
| Base | `api-hermes.pathao.com` / `courier-api-sandbox.pathao.com` | `developers.carrybee.com` / `sandbox.carrybee.com` | `portal.packzy.com/api/v1` | `openapi.redx.com.bd/v1.0.0-beta` / `sandbox.redx.com.bd/v1.0.0-beta` |
| Auth | OAuth2 password grant → Bearer (5-day token, refresh token) | headers `Client-ID`, `Client-Secret`, `Client-Context` | headers `Api-Key`, `Secret-Key` | header `API-ACCESS-TOKEN: Bearer <jwt>` |
| Sandbox | yes | yes | **none documented** | yes |
| Weight unit | **kg**, 0.5–10 | **grams**, 1–25000 | not sent | **grams** |
| COD cap | none stated | **100,000 BDT** | 1,000,000 | none stated |
| Location input | city/zone/area ids optional (derived from address) | ids optional; `address-details`/`area-suggestion` helpers | address text only | **`delivery_area` + `delivery_area_id` required** (`GET /areas`) |
| Your reference | `merchant_order_id` (optional) | `merchant_order_id` (<50, optional) | `invoice` (required, **unique**, `[A-Za-z0-9_-]`) | `merchant_invoice_id` (optional) |
| Duplicate protection | none documented | none documented | **yes** (unique invoice) | none documented |
| Fee at booking | `delivery_fee` | `delivery_fee`, `cod_fee` | none (balance deducts later) | none (use charge calculator) |
| Bulk | `/orders/bulk`, 202 async | `/orders-bulk`, 202 async (webhook per result) | `/create_order/bulk-order/extended`, ≤500, sync per-item results | none documented |
| Cancel | none documented | `POST …/cancel` | none; use `create_return_request` | `PATCH /parcels` status→cancelled |
| Webhook auth | secret in `X-PATHAO-Signature` + handshake header | secret in `X-CB-Webhook-Integration-Header`, handshake 202 | HMAC-SHA256 `X-Signature` (+ Bearer) | token in URL query only |
| Settlement info | `order.paid` + `invoice_id` | `order.paid` + `invoice_id` | `/get_balance`, `/payments` | none documented |
| Phone format | 11 chars | valid BD phone | 11 digits starting `01` | not specified |

Normalize phones to `01XXXXXXXXX` for all four (`scripts/validate_order.py: normalize_phone`).

## Adapter pattern (what to build)

```
normalized order ─► validate/clean ─► Courier_Adapter::create_shipment() ─► normalized result
courier payload  ─► webhook receiver ─► raw event log ─► status mapper ─► canonical state ─► store logic
```

- **Normalized order**: `name, phone, address, cod_amount, weight_grams, quantity, reference, note, description, alt_phone, declared_value, store_id, city_id, zone_id, area_id, area_name`.
- **Normalized result**: `ok, consignment_id, tracking_code, fee, status_raw, http, error, raw`.
- **Canonical states**: see `references/status-map.md`. Persist both the raw courier status and the canonical state.
- Adapters expose `create_shipment`, `get_status`, `cancel` (return `ok=false` + explanation where a courier has no documented endpoint — don't fake one).

## Rules that prevent expensive bugs

1. **Booking retries.** Only Steadfast is safe to retry (duplicate invoices are refused). For Pathao/Carrybee/RedX a timeout is ambiguous — the parcel may exist. Mark the order "booking attempted", look it up (Carrybee `…/details` accepts your merchant id; otherwise panel/list/staff) before retrying. Never auto-retry in a loop.
2. **Never retry 401 blindly.** Steadfast locks you out for 60 min after 10 failed-auth requests in 5 min (even with correct keys afterwards). Pathao: refresh the token once, then fall back to password grant, then stop.
3. **Money**: COD is whole BDT (int). Prepaid → `0`. Check courier caps (Carrybee 100,000) *before* booking and offer to split/choose another courier. Webhook fees are decimals (e.g. 83.46) and sometimes strings — cast, store as decimal. Compare `collected_amount` to expected COD on `delivered` and flag mismatches.
4. **Units**: Pathao kg vs Carrybee/RedX grams. Convert in the adapter, flag clamped weights to staff.
5. **Data mutation**: Steadfast replaces `{ } ; < > $` with spaces and truncates (name 100, address 490, note 480, invoice 100) without error. Clean on your side so printed labels match what the rider sees.
6. **Async booking** (Pathao bulk, Carrybee bulk): the HTTP response only means "accepted". Results arrive by webhook; use unique references and a position/reference map.
7. **Statuses are open-ended.** Unknown values → `unknown` + raw log + admin alert, never an exception (a 5xx makes couriers retry and eventually disable your webhook).
8. **Approval-pending ≠ done.** Steadfast `*_approval_pending` is the rider's claim; do not settle COD or complete orders on it. For stock reconciliation use `status_with_return_status_by_cid` (note the courier's spelling `proccessing`).
9. **Webhooks**: verify against the raw body with constant-time comparison; return 2xx fast; do work async; dedupe; no status regression on stale/out-of-order events; keep the route out of caches/WAF/REST-blocking plugins. Details: `references/webhooks.md`.
10. **Location resolution** is the most failure-prone step (esp. RedX which requires area ids). Cache the courier location trees, resolve by postcode/name with a staff override, and fail with a clear admin message rather than booking to a guessed area.
11. **Customer risk checks** (Steadfast `/fraud_check/score/{phone}`): ratios can be `null` = unknown, not "clean"; it's a signal, not a verdict; the old `/fraud_check/{phone}` counts endpoint sunset on 27 Sept 2026 — use `/score/`.

## Unknowns to surface (don't paper over)

State these to the user when they affect the task:
- Pathao documents **no cancel/return/exchange** endpoint; Steadfast no cancel (return request instead); RedX/Steadfast no settlement webhook for RedX.
- Carrybee's create-order doc spells the secondary phone field `recipient_secendary_phone` — verify before sending it.
- RedX `parcel_weight` unit text is ambiguous (samples and calculator say grams); RedX webhooks have **no signature** and no documented retry/timeout/response code.
- Pathao's webhook handshake status code isn't stated (use 202 and confirm with the panel TEST button); integration-secret value comes from the panel.
- Steadfast events other than `delivery_status` have no documented payload shape; Pathao/RedX/Carrybee retry behaviour isn't documented.
- Doc samples contain typos/invalid JSON (trailing commas, unquoted placeholders, missing commas) — always build bodies with a JSON encoder, never by string-pasting curl samples.
- RedX is v1.0.0-beta; Carrybee v2.0; re-check the live docs when behaviour disagrees with these references.

## Bundled resources

| Path | Use |
|---|---|
| `references/pathao.md`, `carrybee.md`, `steadfast.md`, `redx.md` | Auth, endpoints, field rules, webhook specifics, gotchas per courier |
| `references/webhooks.md` | Comparison table, universal receiver pattern, handshakes, testing, ops checklist |
| `references/status-map.md` | Canonical states, per-courier mapping, no-regression rules, store-side effects |
| `references/wordpress.md` | WP/WooCommerce architecture: settings, HTTP rules, token caching, REST route, Action Scheduler, HPOS, data model |
| `assets/php/courier-adapters.php` | Interface + 4 adapters + factory (template) |
| `assets/php/webhook-receiver.php` | REST route, per-courier auth/handshake, raw event table, async worker, PHP status mapper (template) |
| `scripts/validate_order.py` | Normalize/validate an order and build each courier's payload (`--self-test`) |
| `scripts/status_map.py` | Status → canonical mapper + regression rule (`--self-test`) |
| `scripts/webhook_tool.py` | Simulate courier webhooks / compute & verify Steadfast signatures (`--self-test`) |

Quick commands:
```bash
python3 scripts/validate_order.py pathao order.json --pretty      # also: carrybee | steadfast | redx
python3 scripts/status_map.py steadfast cancelled_return_proccessing
python3 scripts/webhook_tool.py simulate steadfast delivered --url https://site.test/wp-json/ns/v1/webhook/steadfast --secret "$STEADFAST_WEBHOOK_TOKEN" --send
```

## Deliverable checklist (when asked to "integrate" a courier)

- [ ] Settings for credentials + sandbox/live switch (secrets masked, never echoed)
- [ ] Adapter with create / status / cancel (or an explicit "unsupported")
- [ ] Order → payload validation (phone, weight, COD cap, lengths, charset, area ids)
- [ ] Booking-failure handling per the retry rule; admin-visible notes; no raw courier errors to customers
- [ ] Webhook route: auth, handshake, raw log, dedupe, async processing, status mapping, no regression
- [ ] Tracking/polling fallback for non-terminal shipments (respect Steadfast's 60 s cache and rate limits)
- [ ] COD reconciliation hook (`collected_amount`, `invoice_id`, payouts)
- [ ] Tests/simulations run, plus the list of unverified assumptions
