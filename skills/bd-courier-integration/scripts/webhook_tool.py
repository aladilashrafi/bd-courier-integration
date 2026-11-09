#!/usr/bin/env python3
"""Offline helpers for testing courier webhook receivers.

Subcommands
  sign steadfast  --token T --body-file F            print the X-Signature for a body
  verify steadfast --token T --body-file F --signature SIG   check a received signature (constant-time)
  simulate <courier> <event> [--url U] [--secret S] [--integration-secret I]
           [--consignment ID] [--reference REF] [--send] [--show-secrets]
           Build a realistic webhook request. Without --send it only prints it (dry run, secrets masked).
  --self-test

Couriers/events for `simulate`:
  pathao     order.created | order.picked | order.delivered | order.returned | order.paid ... (any "order.*")
  carrybee   webhook.integration | order.created | order.delivered | order.delivery-failed ... (any "order.*")
  steadfast  pending | delivered | partial_delivered | cancelled | unknown   (sent as delivery_status)
  redx       ready-for-delivery | delivery-in-progress | delivered | agent-hold | agent-returning | returned

Secrets can also come from env: PATHAO_WEBHOOK_SECRET, CARRYBEE_WEBHOOK_SECRET,
STEADFAST_WEBHOOK_TOKEN, REDX_WEBHOOK_TOKEN. Pure stdlib.
"""
import argparse
import datetime as dt
import hashlib
import hmac
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

ENV = {"pathao": "PATHAO_WEBHOOK_SECRET", "carrybee": "CARRYBEE_WEBHOOK_SECRET",
       "steadfast": "STEADFAST_WEBHOOK_TOKEN", "redx": "REDX_WEBHOOK_TOKEN"}


def hmac_hex(token, body_bytes):
    return hmac.new(token.encode(), body_bytes, hashlib.sha256).hexdigest()


def verify_steadfast(token, body_bytes, signature):
    return hmac.compare_digest(hmac_hex(token, body_bytes), (signature or "").strip().lower())


def build_request(courier, event, secret, integration_secret, consignment, reference, url):
    now = dt.datetime.now(dt.timezone.utc)
    iso = now.isoformat(timespec="seconds")
    plain = now.strftime("%Y-%m-%d %H:%M:%S")
    headers = {"Content-Type": "application/json"}
    if courier == "pathao":
        body = {"consignment_id": consignment or "DL121224VS8TTJ", "merchant_order_id": reference or "TS-123",
                "updated_at": plain, "timestamp": iso, "store_id": 130820, "event": event}
        if event in ("order.created", "order.updated", "order.pickup-requested"):
            body["delivery_fee"] = 83.46
        if event in ("order.delivered", "order.partial-delivery", "order.paid-return", "order.exchanged"):
            body["collected_amount"] = 60
        if event == "order.paid":
            body["invoice_id"] = "121224IBW19790"
        headers["X-PATHAO-Signature"] = secret or ""
    elif courier == "carrybee":
        if event == "webhook.integration":
            body = {"event": "webhook.integration"}
        else:
            body = {"event": event, "store_id": "a1b2c3d4", "consignment_id": consignment or "FX1212124433",
                    "merchant_order_id": reference or "order-1234", "timestamptz": iso}
            if event in ("order.created", "order.updated"):
                body.update({"collectable_amount": 1500, "cod_fee": 15, "delivery_fee": 83.46})
            if event in ("order.delivered", "order.partial-delivery", "order.paid-return", "order.exchange"):
                body.update({"collected_amount": 60, "attempt": 1})
            if event == "order.assigned-for-delivery":
                body.update({"attempt": 1, "agent_name": "Rahim Uddin", "agent_phone": "01700000000"})
            if event == "order.paid":
                body["invoice_id"] = "IX212124433"
        headers["X-CB-Webhook-Integration-Header"] = secret or ""
    elif courier == "steadfast":
        body = {"notification_type": "delivery_status", "consignment_id": int(consignment or 12345),
                "invoice": reference or "INV-67890", "cod_amount": 1500.0, "status": event,
                "delivery_charge": 100.0, "tracking_message": "Simulated status: %s" % event, "updated_at": plain}
        headers.update({"Authorization": "Bearer %s" % (secret or ""), "User-Agent": "Steadfast-Webhook/1.0",
                        "Idempotency-Key": hashlib.sha1(("%s%s" % (event, iso)).encode()).hexdigest()})
    elif courier == "redx":
        body = {"tracking_number": consignment or "21A427TU4BN3R", "timestamp": iso, "status": event,
                "message_en": "Simulated status: %s" % event, "message_bn": "", "invoice_number": reference or "ACBD1234TEST"}
        if secret:
            sep = "&" if urllib.parse.urlparse(url or "").query else "?"
            url = "%s%stoken=%s" % (url, sep, urllib.parse.quote(secret))
    else:
        raise SystemExit("unknown courier %r" % courier)
    raw = json.dumps(body, separators=(",", ":")).encode()
    if courier == "steadfast":
        headers["X-Signature"] = hmac_hex(secret or "", raw)
    return url, headers, raw


def cmd_simulate(a):
    secret = a.secret or os.environ.get(ENV[a.courier], "")
    url, headers, raw = build_request(a.courier, a.event, secret, a.integration_secret, a.consignment, a.reference, a.url or "")
    shown = dict(headers)
    if not a.show_secrets:
        for k in list(shown):
            if k.lower() in ("x-pathao-signature", "x-cb-webhook-integration-header", "x-signature", "authorization"):
                shown[k] = "********"
        if a.courier == "redx" and secret:
            url = url.replace(urllib.parse.quote(secret), "********")
    print("POST", url or "<--url not given>")
    for k, v in shown.items():
        print("%s: %s" % (k, v))
    print()
    print(raw.decode())
    if not a.send:
        print("\n(dry run - add --send to deliver)")
        return 0
    if not a.url:
        print("--send needs --url", file=sys.stderr)
        return 2
    req = urllib.request.Request(url, data=raw, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            code, rheaders, rbody = resp.status, resp.headers, resp.read(2000)
    except urllib.error.HTTPError as e:
        code, rheaders, rbody = e.code, e.headers, e.read(2000)
    except Exception as e:  # network/TLS errors are the usual handshake problem
        print("request failed: %s" % e, file=sys.stderr)
        return 1
    print("\n<- HTTP %s" % code)
    for h in ("X-Pathao-Merchant-Webhook-Integration-Secret", "X-CB-Webhook-Integration-Header"):
        if rheaders.get(h) is not None:
            print("%s present in response" % h)
    print(rbody.decode(errors="replace"))
    return 0 if 200 <= code < 300 else 1


def _read_body(path):
    with open(path, "rb") as fh:
        return fh.read()


def _self_test():
    token = "test-token"
    _u, hdrs, raw = build_request("steadfast", "delivered", token, None, None, None, "")
    ok = verify_steadfast(token, raw, hdrs["X-Signature"]) and not verify_steadfast("wrong", raw, hdrs["X-Signature"])
    _u, h2, _r = build_request("carrybee", "webhook.integration", "s3cret", None, None, None, "")
    ok = ok and h2["X-CB-Webhook-Integration-Header"] == "s3cret"
    u3, _h3, _r3 = build_request("redx", "delivered", "tok", None, None, None, "https://x.test/cb")
    ok = ok and u3.endswith("?token=tok")
    print("OK" if ok else "FAIL")
    return 0 if ok else 1


def main():
    if "--self-test" in sys.argv:
        return _self_test()
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    for name in ("sign", "verify"):
        sp = sub.add_parser(name)
        sp.add_argument("courier", choices=["steadfast"])
        sp.add_argument("--token", default=os.environ.get(ENV["steadfast"], ""))
        sp.add_argument("--body-file", required=True)
        if name == "verify":
            sp.add_argument("--signature", required=True)
    sp = sub.add_parser("simulate")
    sp.add_argument("courier", choices=list(ENV))
    sp.add_argument("event")
    sp.add_argument("--url")
    sp.add_argument("--secret")
    sp.add_argument("--integration-secret")
    sp.add_argument("--consignment")
    sp.add_argument("--reference")
    sp.add_argument("--send", action="store_true")
    sp.add_argument("--show-secrets", action="store_true")
    a = ap.parse_args()
    if a.cmd == "sign":
        print(hmac_hex(a.token, _read_body(a.body_file)))
        return 0
    if a.cmd == "verify":
        good = verify_steadfast(a.token, _read_body(a.body_file), a.signature)
        print("VALID" if good else "INVALID")
        return 0 if good else 1
    return cmd_simulate(a)


if __name__ == "__main__":
    sys.exit(main())
