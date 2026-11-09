# bd-courier-integration

A Claude skill for building, reviewing and debugging integrations with Bangladeshi courier APIs: **Pathao Courier, Carrybee, Steadfast (Packzy) and RedX**.

Covers parcel booking, COD amounts, bulk orders, area/zone lookup, tracking and status sync, returns/cancellation, COD reconciliation, and webhook receivers (signature checks, handshakes, idempotency). Ships with WordPress/WooCommerce PHP templates and Python scripts to validate orders, map statuses and simulate webhooks.

## Install (Claude Code)

```text
/plugin marketplace add aladilashrafi/bd-courier-integration
/plugin install bd-courier-integration@bd-courier-integration
```

The skill triggers automatically when you mention these couriers or Bangladesh delivery APIs.

## Claude.ai / Claude Desktop

Zip the `skills/bd-courier-integration` folder and upload it under **Settings → Capabilities → Skills**.

## Layout

```
.claude-plugin/    plugin.json + marketplace.json
skills/bd-courier-integration/
  SKILL.md         entry point
  references/      per-courier API notes, webhooks, status map, WordPress
  assets/php/      adapter + webhook receiver templates
  scripts/         validate_order.py, status_map.py, webhook_tool.py
```

## Safety notes

- No live credentials are included; keep secrets in environment/config.
- Steadfast has no documented sandbox — don't run live test bookings without approval.
- Courier APIs change; check current courier docs when behaviour disagrees with the references.

## License

MIT © Al Adil Ashrafi. See [LICENSE](LICENSE).
