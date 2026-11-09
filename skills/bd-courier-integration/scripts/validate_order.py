#!/usr/bin/env python3
"""Validate a normalized order and build the courier-specific booking payload.

Why: each Bangladeshi courier has different units, enums, length limits and optional-field
rules (Pathao kg vs Carrybee/RedX grams, Steadfast silently truncating, Carrybee COD cap...).
Catching these before the API call avoids rejected bookings and silent data changes.

Usage:
  validate_order.py <pathao|carrybee|steadfast|redx> order.json [--pretty]
  validate_order.py --self-test

Input JSON (normalized, all couriers):
  name, phone, address, cod_amount (BDT, 0 = prepaid), weight_grams, quantity,
  reference (your order number), note, description, alt_phone, declared_value,
  store_id, city_id, zone_id, area_id, area_name   (the last five only where the courier needs them)

Output JSON: {"courier", "ok", "payload", "errors": [...], "warnings": [...]}; exit 1 if errors.
Pure stdlib. The same rules are mirrored in assets/php/courier-adapters.php.
"""
import json
import re
import sys

STEADFAST_STRIP = "{};<>$"


def normalize_phone(raw):
    """Return (phone, error). Accepts +8801XXXXXXXXX, 8801XXXXXXXXX, 01XXXXXXXXX, 1XXXXXXXXX."""
    digits = re.sub(r"\D", "", str(raw or ""))
    if digits.startswith("880"):
        digits = digits[3:]
    if len(digits) == 10 and digits.startswith("1"):
        digits = "0" + digits
    if not re.fullmatch(r"01[3-9]\d{8}", digits):
        return None, "phone %r is not a valid Bangladeshi mobile number (need 11 digits, 013-019 prefix)" % (raw,)
    return digits, None


def _need(o, key, errors, label=None):
    v = o.get(key)
    if v is None or (isinstance(v, str) and not v.strip()):
        errors.append("missing required field: %s" % (label or key))
        return None
    return v


def _len(value, lo, hi, label, errors, warnings=None, truncate=False):
    s = str(value)
    if len(s) < lo:
        errors.append("%s too short (%d < %d)" % (label, len(s), lo))
    if len(s) > hi:
        if truncate:
            warnings.append("%s longer than %d chars; truncated" % (label, hi))
            return s[:hi]
        errors.append("%s too long (%d > %d)" % (label, len(s), hi))
    return s


def _int_amount(o, errors, warnings):
    cod = o.get("cod_amount", 0) or 0
    try:
        f = float(cod)
    except (TypeError, ValueError):
        errors.append("cod_amount is not a number")
        return 0
    if f < 0:
        errors.append("cod_amount must be >= 0")
    if f != int(f):
        warnings.append("cod_amount %s rounded to whole taka" % cod)
    return int(round(f))


def _weight_g(o, errors):
    w = o.get("weight_grams")
    if w is None:
        errors.append("missing required field: weight_grams")
        return None
    try:
        return int(w)
    except (TypeError, ValueError):
        errors.append("weight_grams is not a number")
        return None


def build_pathao(o, errors, warnings):
    p = {}
    store = _need(o, "store_id", errors)
    if store is not None:
        try:
            p["store_id"] = int(store)
        except (TypeError, ValueError):
            errors.append("pathao store_id must be an integer")
    if o.get("reference"):
        p["merchant_order_id"] = str(o["reference"])
    name = _need(o, "name", errors)
    if name:
        p["recipient_name"] = _len(name, 3, 100, "recipient_name", errors)
    phone, err = normalize_phone(o.get("phone"))
    errors.append(err) if err else p.__setitem__("recipient_phone", phone)
    if o.get("alt_phone"):
        ap, err = normalize_phone(o["alt_phone"])
        errors.append(err) if err else p.__setitem__("recipient_secondary_phone", ap)
    addr = _need(o, "address", errors)
    if addr:
        p["recipient_address"] = _len(addr, 10, 220, "recipient_address", errors)
    p["delivery_type"] = int(o.get("delivery_type", 48))  # 48 normal, 12 on-demand
    p["item_type"] = int(o.get("item_type", 2))           # 1 document, 2 parcel
    p["item_quantity"] = int(o.get("quantity", 1) or 1)
    g = _weight_g(o, errors)
    if g is not None:
        kg = g / 1000.0
        if kg > 10:
            errors.append("pathao max weight is 10 kg (got %.2f)" % kg)
        elif kg < 0.5:
            warnings.append("pathao minimum weight is 0.5 kg; %d g raised to 0.5" % g)
            kg = 0.5
        p["item_weight"] = round(kg, 3)
    p["amount_to_collect"] = _int_amount(o, errors, warnings)
    if o.get("note"):
        p["special_instruction"] = str(o["note"])
    if o.get("description"):
        p["item_description"] = str(o["description"])
    # Optional location ids: include only when set. Never send null.
    for src, dst in (("city_id", "recipient_city"), ("zone_id", "recipient_zone"), ("area_id", "recipient_area")):
        if o.get(src) is not None:
            p[dst] = int(o[src])
    return p


def build_carrybee(o, errors, warnings):
    p = {}
    store = _need(o, "store_id", errors)
    if store is not None:
        p["store_id"] = str(store)  # string for Carrybee
    if o.get("reference"):
        p["merchant_order_id"] = _len(o["reference"], 0, 49, "merchant_order_id (<50)", errors)
    p["delivery_type"] = int(o.get("delivery_type", 1))  # 1 normal, 2 express
    p["product_type"] = int(o.get("product_type", 1))    # 1 parcel, 2 book, 3 document
    name = _need(o, "name", errors)
    if name:
        p["recipient_name"] = _len(name, 2, 99, "recipient_name", errors)
    phone, err = normalize_phone(o.get("phone"))
    errors.append(err) if err else p.__setitem__("recipient_phone", phone)
    if o.get("alt_phone"):
        warnings.append("Carrybee doc spells the secondary phone field 'recipient_secendary_phone' on create; "
                        "alt_phone omitted - verify the real field name in sandbox before sending it")
    addr = _need(o, "address", errors)
    if addr:
        p["recipient_address"] = _len(addr, 10, 200, "recipient_address", errors)
    g = _weight_g(o, errors)
    if g is not None:
        if not 1 <= g <= 25000:
            errors.append("carrybee item_weight must be 1-25000 g (got %d)" % g)
        p["item_weight"] = g
    q = int(o.get("quantity", 1) or 1)
    if not 1 <= q <= 200:
        errors.append("carrybee item_quantity must be 1-200")
    p["item_quantity"] = q
    cod = _int_amount(o, errors, warnings)
    if cod > 100000:
        errors.append("carrybee collectable_amount max is 100000 BDT (got %d): split the shipment or use another courier" % cod)
    p["collectable_amount"] = cod
    if o.get("note"):
        p["special_instruction"] = _len(o["note"], 0, 254, "special_instruction (<255)", errors, warnings, truncate=True)
    if o.get("description"):
        p["product_description"] = _len(o["description"], 0, 254, "product_description (<255)", errors, warnings, truncate=True)
    for k in ("city_id", "zone_id", "area_id"):
        if o.get(k) is not None:
            p[k] = int(o[k])
    return p


def build_steadfast(o, errors, warnings):
    p = {}
    ref = _need(o, "reference", errors, "reference (becomes invoice)")
    if ref:
        inv = str(ref)
        if not re.fullmatch(r"[A-Za-z0-9_-]+", inv):
            errors.append("invoice %r may contain only letters, digits, hyphen, underscore" % inv)
        p["invoice"] = _len(inv, 1, 100, "invoice", errors, warnings, truncate=True)

    def clean(label, val, limit):
        s = str(val)
        if any(c in s for c in STEADFAST_STRIP):
            warnings.append("%s contains characters Steadfast replaces with spaces (%s); cleaned" % (label, " ".join(STEADFAST_STRIP)))
            s = "".join(" " if c in STEADFAST_STRIP else c for c in s)
        return _len(s, 1, limit, label, errors, warnings, truncate=True)

    name = _need(o, "name", errors)
    if name:
        p["recipient_name"] = clean("recipient_name", name, 100)
    phone, err = normalize_phone(o.get("phone"))
    errors.append(err) if err else p.__setitem__("recipient_phone", phone)
    addr = _need(o, "address", errors)
    if addr:
        p["recipient_address"] = clean("recipient_address", addr, 490)
    cod = _int_amount(o, errors, warnings)
    if cod > 1000000:
        errors.append("steadfast cod_amount max is 1,000,000")
    p["cod_amount"] = cod
    if o.get("note"):
        p["note"] = clean("note", o["note"], 480)
    if o.get("alt_phone"):
        ap, err = normalize_phone(o["alt_phone"])
        errors.append(err) if err else p.__setitem__("alternative_phone", ap)
    if o.get("description"):
        p["item_description"] = _len(o["description"], 0, 255, "item_description", errors, warnings, truncate=True)
    if o.get("quantity"):
        p["total_lot"] = int(o["quantity"])
    if o.get("delivery_type") in (0, 1):
        p["delivery_type"] = int(o["delivery_type"])
    if o.get("weight_grams") is not None or o.get("area_id") is not None or o.get("city_id") is not None:
        warnings.append("Steadfast create_order has no weight/area fields; those inputs are ignored (address text only)")
    return p


def build_redx(o, errors, warnings):
    p = {}
    name = _need(o, "name", errors)
    if name:
        p["customer_name"] = str(name)
    phone, err = normalize_phone(o.get("phone"))
    errors.append(err) if err else p.__setitem__("customer_phone", phone)
    area_name = _need(o, "area_name", errors, "area_name (RedX delivery_area)")
    if area_name:
        p["delivery_area"] = str(area_name)
    area_id = _need(o, "area_id", errors, "area_id (RedX delivery_area_id, from GET /areas)")
    if area_id is not None:
        try:
            p["delivery_area_id"] = int(area_id)
        except (TypeError, ValueError):
            errors.append("area_id must be an integer")
    addr = _need(o, "address", errors)
    if addr:
        p["customer_address"] = str(addr)
    if o.get("reference"):
        p["merchant_invoice_id"] = str(o["reference"])
    p["cash_collection_amount"] = _int_amount(o, errors, warnings)
    g = _weight_g(o, errors)
    if g is not None:
        p["parcel_weight"] = g  # grams
    dv = o.get("declared_value")
    if dv is None:
        dv = p.get("cash_collection_amount", 0)
        warnings.append("declared_value not provided; defaulted to COD amount (%s). Set the real parcel value - it drives compensation." % dv)
    p["value"] = int(dv)
    if o.get("note"):
        p["instruction"] = str(o["note"])
    if o.get("store_id") is not None:
        p["pickup_store_id"] = int(o["store_id"])
    else:
        warnings.append("no store_id: RedX will use the default pickup store")
    if o.get("description"):
        p["parcel_details_json"] = [{"name": str(o["description"])[:100], "category": o.get("category", "general"),
                                     "value": int(dv)}]
    if o.get("alt_phone"):
        warnings.append("RedX create-parcel has no secondary phone field; alt_phone ignored")
    return p


BUILDERS = {"pathao": build_pathao, "carrybee": build_carrybee, "steadfast": build_steadfast, "redx": build_redx}


def validate(courier, order):
    courier = courier.lower()
    if courier not in BUILDERS:
        raise ValueError("unknown courier %r" % courier)
    errors, warnings = [], []
    payload = BUILDERS[courier](order, errors, warnings)
    return {"courier": courier, "ok": not errors, "payload": payload, "errors": errors, "warnings": warnings}


def _self_test():
    good = {"name": "Jahid Hasan", "phone": "+880 1712-345678", "address": "House 17/1, Road 3/A, Dhanmondi, Dhaka-1209",
            "cod_amount": 1060, "weight_grams": 700, "quantity": 2, "reference": "WC-10231", "note": "Call first",
            "store_id": 5, "area_id": 12, "area_name": "Dhanmondi", "city_id": 1, "zone_id": 3}
    fails = []

    def expect(cond, msg):
        if not cond:
            fails.append(msg)

    r = validate("pathao", good)
    expect(r["ok"] and r["payload"]["item_weight"] == 0.7 and r["payload"]["recipient_phone"] == "01712345678", "pathao good")
    expect(r["payload"]["recipient_city"] == 1, "pathao optional ids kept when set")
    r = validate("pathao", dict(good, weight_grams=11000))
    expect(not r["ok"], "pathao >10kg must fail")
    r = validate("pathao", {k: v for k, v in good.items() if k not in ("city_id", "zone_id", "area_id")})
    expect("recipient_city" not in r["payload"], "pathao must omit absent location ids (never null)")
    r = validate("carrybee", good)
    expect(r["ok"] and r["payload"]["item_weight"] == 700 and r["payload"]["store_id"] == "5", "carrybee good")
    r = validate("carrybee", dict(good, cod_amount=150000))
    expect(not r["ok"], "carrybee COD cap")
    r = validate("steadfast", dict(good, address="House 5 <b>Road</b> $3; Banani, Dhaka"))
    expect(r["ok"] and "<" not in r["payload"]["recipient_address"] and r["warnings"], "steadfast strips chars")
    r = validate("steadfast", dict(good, reference="WC 10231"))
    expect(not r["ok"], "steadfast invoice charset")
    r = validate("redx", good)
    expect(r["ok"] and r["payload"]["parcel_weight"] == 700 and r["payload"]["delivery_area_id"] == 12, "redx good")
    r = validate("redx", {k: v for k, v in good.items() if k not in ("area_id", "area_name")})
    expect(not r["ok"], "redx requires area")
    for raw, ok in (("01712345678", True), ("8801712345678", True), ("1712345678", True), ("0212345678", False), ("01212345678", False)):
        expect((normalize_phone(raw)[0] is not None) == ok, "phone %s" % raw)
    if fails:
        print("FAIL")
        for f in fails:
            print(" -", f)
        return 1
    print("OK: all validation self-tests passed")
    return 0


def main(argv):
    if len(argv) == 2 and argv[1] == "--self-test":
        return _self_test()
    args = [a for a in argv[1:] if not a.startswith("--")]
    if len(args) != 2:
        print(__doc__)
        return 2
    with open(args[1], "r", encoding="utf-8") as fh:
        order = json.load(fh)
    result = validate(args[0], order)
    print(json.dumps(result, indent=2 if "--pretty" in argv else None, ensure_ascii=False))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
