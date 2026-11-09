# Canonical status model and per-courier mapping

Couriers use incompatible status vocabularies. Store **two** things per shipment: the courier's raw status/event string (never discard it) and a canonical state from the list below. `scripts/status_map.py` is the executable version of this table — keep them in sync (it has a `--self-test`).

## Canonical states

| Canonical | Meaning |
|---|---|
| `created` | Booked with the courier, awaiting courier approval / not yet requested for pickup |
| `pickup_requested` | Pickup requested or assigned to a pickup rider |
| `picked_up` | Courier has the parcel |
| `pickup_failed` | Pickup failed or cancelled |
| `in_transit` | At sorting hub / warehouse / moving between hubs |
| `out_for_delivery` | Assigned to a delivery rider |
| `on_hold` | Held (address/payment/customer issue) |
| `delivery_failed` | Attempt failed; may be retried |
| `delivered` | Delivered and COD collected (or prepaid delivered) |
| `partial_delivered` | Part delivered; part returning |
| `returning` | Coming back to the merchant |
| `returned` | Back with the merchant |
| `cancelled` | Cancelled before delivery |
| `exchange` | Exchange completed/in progress |
| `settled` | COD paid out / invoice raised (financial event, not a delivery state) |
| `exception` | Lost, damaged, unknown — needs a human |
| `unknown` | Unmapped value — log and alert |

`settled` is orthogonal to the delivery state: record it as a payment flag (`cod_settled_at`, `invoice_id`) instead of overwriting `delivered`.

## Mapping

### Pathao (webhook `event`)
| event | canonical |
|---|---|
| `order.created`, `order.updated` | `created` (updated: keep state, refresh fee) |
| `order.pickup-requested`, `order.assigned-for-pickup` | `pickup_requested` |
| `order.picked` | `picked_up` |
| `order.pickup-failed`, `order.pickup-cancelled` | `pickup_failed` |
| `order.at-the-sorting-hub`, `order.in-transit`, `order.received-at-last-mile-hub` | `in_transit` |
| `order.assigned-for-delivery` | `out_for_delivery` |
| `order.on-hold` | `on_hold` |
| `order.delivery-failed` | `delivery_failed` |
| `order.delivered` | `delivered` |
| `order.partial-delivery` | `partial_delivered` |
| `order.returned` | `returned` |
| `order.paid` | `settled` |
| `order.paid-return` | `returned` (+ `collected_amount`) |
| `order.exchanged` | `exchange` |
| `store.*`, `return-id-created` etc. | not a shipment state: log |

Pathao polling (`order_status`/`order_status_slug`) strings are only documented as `Pending`; map unknown strings to `unknown` and alert.

### Carrybee (webhook `event`)
| event | canonical |
|---|---|
| `order.created`, `order.updated` | `created` |
| `order.create-failed` | not a shipment (booking failed; no consignment) |
| `order.pickup-requested`, `order.assigned-for-pickup` | `pickup_requested` |
| `order.picked` | `picked_up` |
| `order.pickup-failed`, `order.pickup-cancelled` | `pickup_failed` |
| `order.at-the-sorting-hub`, `order.on-the-way-to-central-warehouse`, `order.at-central-warehouse`, `order.in-transit`, `order.received-at-last-mile-hub` | `in_transit` |
| `order.assigned-for-delivery` | `out_for_delivery` |
| `order.delivery-on-hold` | `on_hold` |
| `order.delivery-failed` | `delivery_failed` |
| `order.delivered` | `delivered` |
| `order.partial-delivery` | `partial_delivered` |
| `order.returned-at-sorting`, `order.returned-in-transit` | `returning` |
| `order.returned`, `order.returned-to-merchant`, `order.paid-return` | `returned` |
| `order.exchange` | `exchange` |
| `order.paid` | `settled` |

### Steadfast (`status` / `delivery_status`)
| status | canonical |
|---|---|
| `in_review`, `pending` | `created` |
| `hold` | `on_hold` |
| `delivered_approval_pending`, `partial_delivered_approval_pending`, `cancelled_approval_pending`, `unknown_approval_pending` | keep previous state; flag `awaiting_confirmation` (do not settle) |
| `delivered` | `delivered` |
| `partial_delivered` | `partial_delivered` |
| `cancelled` | `cancelled` (parcel then returns; see return statuses) |
| `exceptional` | `exception` |
| `unknown` | `unknown` |
| `*_return_proccessing`, `*_return_rider_assigned` | `returning` |
| `*_return_received` | `returned` |

Steadfast has no in-transit/out-for-delivery granularity in `status`; use `tracking_update` webhooks or `/trackings_by_invoice/{invoice}` for that.

### RedX (webhook `status`)
| status | canonical |
|---|---|
| `ready-for-delivery` | `picked_up` (merchant parcel received by RedX) |
| `delivery-in-progress` | `out_for_delivery` |
| `delivered` | `delivered` |
| `agent-hold` | `on_hold` |
| `agent-returning` | `returning` |
| `returned` | `returned` |
| `agent-area-change` | `in_transit` + flag `area_changed` |
| `pickup-pending` (seen in parcel info) | `created` |

## State-machine rules

1. **Never move backwards on stale events.** Webhooks can arrive out of order or be retried. Keep a rank (`created`<`pickup_requested`<`picked_up`<`in_transit`<`out_for_delivery`<terminal) and ignore events that regress, except legitimate loops (`delivery_failed`/`on_hold` → `out_for_delivery`; any → `returning`). Also compare the courier timestamp when present.
2. **Terminal states**: `delivered`, `returned`, `cancelled`, `exception`. Only `settled` (a flag) and `exchange` may follow `delivered`.
3. **Idempotency**: key on `(courier, consignment_id, event/status, courier_timestamp)`; Steadfast additionally sends `Idempotency-Key`.
4. **Store the raw payload** in a log table/post-meta before mapping — you'll need it the first time a courier changes a field or sends an event this doc never showed.
5. **Unknown values** → `unknown`, keep raw, notify an admin; never throw (a 5xx makes couriers retry and eventually disable the webhook).

## Suggested store-side effects (WooCommerce-style)
| canonical | typical store action |
|---|---|
| `picked_up` / `in_transit` / `out_for_delivery` | order → "shipped"/"out for delivery" (custom status or note), customer email optional |
| `delivered` | order → completed; record `collected_amount` vs expected COD, flag mismatches |
| `partial_delivered` | add order note, hold refund/stock decisions for staff |
| `delivery_failed` / `on_hold` | order note + staff alert; optionally SMS customer |
| `returning` / `returned` | order → returned/refunded status per policy; restock only on `returned` (parcel physically back) |
| `settled` | store `invoice_id`, mark COD reconciled |

Always let the merchant choose the order-status mapping; these are defaults, not rules.
