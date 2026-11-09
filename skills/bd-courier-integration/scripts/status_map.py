#!/usr/bin/env python3
"""Map courier-specific statuses/events to the canonical states in references/status-map.md.

Usage:
  status_map.py <pathao|carrybee|steadfast|redx> <value>     # print canonical state as JSON
  status_map.py --self-test                                  # check every documented value maps

Return value of normalize(): (canonical, flags)
  canonical = one of CANONICAL, or:
    "keep"   -> do not change the current state (e.g. Pathao order.updated, Steadfast *_approval_pending)
    "ignore" -> not a shipment-state event (store.*, order.create-failed)
  flags     -> tuple of extra markers, e.g. ("awaiting_confirmation",), ("area_changed",)

Pure stdlib; import it from other tooling or copy the dicts into the target codebase.
"""
import json
import sys

CANONICAL = [
    "created", "pickup_requested", "picked_up", "pickup_failed", "in_transit",
    "out_for_delivery", "on_hold", "delivery_failed", "delivered",
    "partial_delivered", "returning", "returned", "cancelled", "exchange",
    "settled", "exception", "unknown",
]

# Rank for the "never regress on stale webhooks" rule. Loops back to out_for_delivery from
# on_hold / delivery_failed are allowed, and anything may move to returning/returned/cancelled/exception.
RANK = {
    "created": 0, "pickup_requested": 1, "pickup_failed": 1, "picked_up": 2,
    "in_transit": 3, "out_for_delivery": 4, "on_hold": 4, "delivery_failed": 4,
    "delivered": 9, "partial_delivered": 9, "returning": 8, "returned": 10,
    "cancelled": 10, "exception": 10, "exchange": 10, "unknown": -1, "settled": -1,
}
TERMINAL = {"delivered", "partial_delivered", "returned", "cancelled", "exception"}
ALWAYS_ALLOWED = {"returning", "returned", "cancelled", "exception", "delivered", "partial_delivered"}

PATHAO = {
    "order.created": ("created", ()),
    "order.updated": ("keep", ()),
    "order.pickup-requested": ("pickup_requested", ()),
    "order.assigned-for-pickup": ("pickup_requested", ()),
    "order.picked": ("picked_up", ()),
    "order.pickup-failed": ("pickup_failed", ()),
    "order.pickup-cancelled": ("pickup_failed", ()),
    "order.at-the-sorting-hub": ("in_transit", ()),
    "order.in-transit": ("in_transit", ()),
    "order.received-at-last-mile-hub": ("in_transit", ()),
    "order.assigned-for-delivery": ("out_for_delivery", ()),
    "order.on-hold": ("on_hold", ()),
    "order.delivery-failed": ("delivery_failed", ()),
    "order.delivered": ("delivered", ()),
    "order.partial-delivery": ("partial_delivered", ()),
    "order.returned": ("returned", ()),
    "order.paid": ("settled", ()),
    "order.paid-return": ("returned", ("cod_collected_on_return",)),
    "order.exchanged": ("exchange", ()),
    "store.created": ("ignore", ()),
    "store.updated": ("ignore", ()),
}

CARRYBEE = {
    "order.created": ("created", ()),
    "order.create-failed": ("ignore", ("booking_failed",)),
    "order.updated": ("keep", ()),
    "order.pickup-requested": ("pickup_requested", ()),
    "order.assigned-for-pickup": ("pickup_requested", ()),
    "order.picked": ("picked_up", ()),
    "order.pickup-failed": ("pickup_failed", ()),
    "order.pickup-cancelled": ("pickup_failed", ()),
    "order.at-the-sorting-hub": ("in_transit", ()),
    "order.on-the-way-to-central-warehouse": ("in_transit", ()),
    "order.at-central-warehouse": ("in_transit", ()),
    "order.in-transit": ("in_transit", ()),
    "order.received-at-last-mile-hub": ("in_transit", ()),
    "order.assigned-for-delivery": ("out_for_delivery", ()),
    "order.delivery-on-hold": ("on_hold", ()),
    "order.delivery-failed": ("delivery_failed", ()),
    "order.delivered": ("delivered", ()),
    "order.partial-delivery": ("partial_delivered", ()),
    "order.returned-at-sorting": ("returning", ()),
    "order.returned-in-transit": ("returning", ()),
    "order.returned": ("returned", ()),
    "order.returned-to-merchant": ("returned", ()),
    "order.paid-return": ("returned", ("cod_collected_on_return",)),
    "order.exchange": ("exchange", ()),
    "order.paid": ("settled", ()),
    "webhook.integration": ("ignore", ("handshake",)),
}

STEADFAST = {
    "in_review": ("created", ()),
    "pending": ("created", ()),
    "hold": ("on_hold", ()),
    "delivered_approval_pending": ("keep", ("awaiting_confirmation",)),
    "partial_delivered_approval_pending": ("keep", ("awaiting_confirmation",)),
    "cancelled_approval_pending": ("keep", ("awaiting_confirmation",)),
    "unknown_approval_pending": ("keep", ("awaiting_confirmation",)),
    "delivered": ("delivered", ()),
    "partial_delivered": ("partial_delivered", ()),
    "cancelled": ("cancelled", ()),
    "exceptional": ("exception", ()),
    "unknown": ("unknown", ()),
    # Return statuses (note the courier's own spelling: "proccessing")
    "partial_delivered_return_proccessing": ("returning", ("partial",)),
    "partial_delivered_return_rider_assigned": ("returning", ("partial",)),
    "partial_delivered_return_received": ("returned", ("partial",)),
    "cancelled_return_proccessing": ("returning", ()),
    "cancelled_return_rider_assigned": ("returning", ()),
    "cancelled_return_received": ("returned", ()),
}

REDX = {
    "ready-for-delivery": ("picked_up", ()),
    "delivery-in-progress": ("out_for_delivery", ()),
    "delivered": ("delivered", ()),
    "agent-hold": ("on_hold", ()),
    "agent-returning": ("returning", ()),
    "returned": ("returned", ()),
    "agent-area-change": ("in_transit", ("area_changed",)),
    "pickup-pending": ("created", ()),  # seen in GET /parcel/info, not in the webhook list
}

MAPS = {"pathao": PATHAO, "carrybee": CARRYBEE, "steadfast": STEADFAST, "redx": REDX}


def normalize(courier, value):
    """Return (canonical, flags). Unmapped values -> ("unknown", ("unmapped",))."""
    table = MAPS.get(courier.lower())
    if table is None:
        raise ValueError("unknown courier %r (expected one of %s)" % (courier, ", ".join(MAPS)))
    key = (value or "").strip().lower()
    if key in table:
        return table[key]
    return ("unknown", ("unmapped",))


def should_apply(current, new):
    """Apply the no-regression rule. `current`/`new` are canonical states."""
    if new in ("keep", "ignore", "settled", "unknown"):
        return False
    if current in (None, "", "unknown"):
        return True
    if new in ALWAYS_ALLOWED:
        # A terminal state never flips to another terminal one except via an explicit return flow.
        if current in TERMINAL and new != current:
            return current in ("delivered", "partial_delivered") and new in ("returning", "returned")
        return True
    if current in TERMINAL:
        return False
    if new in ("on_hold", "delivery_failed", "out_for_delivery") and current in (
            "on_hold", "delivery_failed", "out_for_delivery", "in_transit"):
        return True
    return RANK.get(new, -1) >= RANK.get(current, -1)


def _self_test():
    problems = []
    for courier, table in MAPS.items():
        for raw, (canon, _flags) in table.items():
            if canon not in CANONICAL and canon not in ("keep", "ignore"):
                problems.append("%s:%s -> bad canonical %r" % (courier, raw, canon))
    if normalize("steadfast", "cancelled_return_proccessing")[0] != "returning":
        problems.append("steadfast proccessing spelling not mapped")
    if normalize("pathao", "order.something-new")[0] != "unknown":
        problems.append("unmapped value should be unknown")
    # regression rule
    checks = [
        ("in_transit", "picked_up", False),
        ("out_for_delivery", "delivery_failed", True),
        ("delivery_failed", "out_for_delivery", True),
        ("delivered", "in_transit", False),
        ("delivered", "returning", True),
        ("returned", "delivered", False),
        ("created", "settled", False),
    ]
    for cur, new, expect in checks:
        got = should_apply(cur, new)
        if got != expect:
            problems.append("should_apply(%s, %s) = %s, expected %s" % (cur, new, got, expect))
    total = sum(len(t) for t in MAPS.values())
    if problems:
        print("FAIL")
        for p in problems:
            print(" -", p)
        return 1
    print("OK: %d mapped values across %d couriers, regression rules pass" % (total, len(MAPS)))
    return 0


def main(argv):
    if len(argv) == 2 and argv[1] == "--self-test":
        return _self_test()
    if len(argv) != 3:
        print(__doc__)
        return 2
    canon, flags = normalize(argv[1], argv[2])
    print(json.dumps({"courier": argv[1], "raw": argv[2], "canonical": canon, "flags": list(flags)}))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
