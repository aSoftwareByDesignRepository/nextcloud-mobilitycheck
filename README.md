# MobilityCheck — Nextcloud app

Corporate carsharing, fleet management, Fahrtenbuch (§ 6 EStG) and Fahrtkostenabrechnung (§ 9 EStG) for organisations in Germany.

For the full specification, see `pm/app-ideas/mobilitycheck/README.md` in the parent repository.

## Highlights

- **Booking engine** with server-side conflict prevention (gap-lock transaction), optional approval workflow, auto-reschedule; check-out/check-in with **pickup** and **return location notes** (return note **required** for pool/group shared vehicles — see spec §4.6).
- **Driver compliance** — licence verification log, yearly Fahrunterweisung, expiry reminders.
- **Damage and repair** — immutable damage reports, photos via Nextcloud Files, workshop assignments, repair invoices.
- **Costs** — Euro cents only, VAT 0 / 7 / 19 %, default categories at install.
- **Maintenance** — calendar + odometer-driven schedules, optional booking blocks.
- **Fahrtenbuch** — § 6 EStG-aligned trip logbook, immutable after confirmation, amendment chain.
- **Fahrtkostenabrechnung** — private vehicles, versioned per-km rates per jurisdiction, statutory-vs-taxable split.
- **Vehicle assignment** — pool / group / dedicated, tax treatment-aware (`business_only`, `one_percent_rule`, `logbook_method`).
- **Smart vehicle search** — feature requirements with best-effort fallback.
- **Exports** — CSV (UTF-8 BOM, `;`), XLSX, print HTML; large jobs run as background jobs into the user's Files.
- **Access governance** — directory restriction, delegated app administrators, per-role permission matrix.
- **WCAG 2.1 AA** target, responsive from 320 px, German (Sie-Form) and English.

## Requirements

- Nextcloud 32–33
- PHP 8.2 – 8.4
- MariaDB/MySQL or PostgreSQL

## Development

```bash
docker compose exec -u www-data nextcloud php occ app:enable mobilitycheck
docker compose exec -u www-data nextcloud php occ migrations:execute mobilitycheck
```

## Licence

AGPL-3.0-or-later. See `LICENSE`.
