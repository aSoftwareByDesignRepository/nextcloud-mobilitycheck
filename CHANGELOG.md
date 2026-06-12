# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.4.6 - 2026-06-12

### Fixed

- **Data loss after Nextcloud upgrade:** `UninstallDropTables` preserves tables and settings on disable; full cleanup runs only on app removal.

## 0.4.4 - 2026-05-25

### Added

- **Timezone and currency catalogs:** single source of truth in `TimezoneCatalog` (full IANA via PHP) and `CurrencyCatalog` (supported ISO 4217 codes including RUB, UAH, KZT, TRY) with `GET /api/catalog/timezones` and `/api/catalog/currencies` for fleet admins.
- **Accessible settings pickers:** searchable combobox UI for default currency and timezone (WCAG 2.1 AA patterns, responsive layout).
- **Unit tests** for catalog services, settings validation, and API error mapping for invalid catalog values.

### Changed

- **Operational defaults:** currency and timezone persistence uses strict server validation (`INVALID_TIMEZONE`, `CURRENCY_NOT_SUPPORTED`) instead of silent fallbacks; getters defensively normalize legacy config.
- **Money handling:** minor-unit decimals follow the active currency catalog in PHP (`MobilityCheckMoney`, cost threshold notifications) and JavaScript (`MobilityCheckMoney` / `data-mc-currency-decimals`).
- **Locale hints:** app default timezone drives `clientHints` when valid; station create/update validates IANA timezones on write.

## [Unreleased]

### Fixed

- **Oracle-safe table identifiers** (≤30 characters including default `oc_` prefix): `mc_reimbursement_rate_config` → `mc_reim_rate_cfg`, `mc_booking_reassignment_suggestions` → `mc_booking_reassign_sug`. Migration `1008` renames existing tables in place on upgrades; PostgreSQL sequences are aligned when applicable.

## 0.4.3 - 2026-05-18

### Added

- **Release tooling:** `release/` docs and `build-appstore-archive.sh` for App Store and GitHub Releases on [`nextcloud-mobilitycheck`](https://github.com/aSoftwareByDesignRepository/nextcloud-mobilitycheck).
- **Repository layout:** canonical standalone repo; monorepo tracks `apps/mobilitycheck` as a git submodule on `main`.

### Changed

- Initial changelog entry aligned with `appinfo/info.xml` **0.4.3** at submodule split from `nextcloud-development`.
