# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Oracle-safe table identifiers** (≤30 characters including default `oc_` prefix): `mc_reimbursement_rate_config` → `mc_reim_rate_cfg`, `mc_booking_reassignment_suggestions` → `mc_booking_reassign_sug`. Migration `1008` renames existing tables in place on upgrades; PostgreSQL sequences are aligned when applicable.

## 0.4.3 - 2026-05-18

### Added

- **Release tooling:** `release/` docs and `build-appstore-archive.sh` for App Store and GitHub Releases on [`nextcloud-mobilitycheck`](https://github.com/aSoftwareByDesignRepository/nextcloud-mobilitycheck).
- **Repository layout:** canonical standalone repo; monorepo tracks `apps/mobilitycheck` as a git submodule on `main`.

### Changed

- Initial changelog entry aligned with `appinfo/info.xml` **0.4.3** at submodule split from `nextcloud-development`.
