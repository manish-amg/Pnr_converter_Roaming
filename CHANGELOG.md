# Changelog

## 3.0.0 - 2026-05-04

- Revamped the UI into a v3 agency-ready workbench with stronger spacing, mobile layout, hover states, sticky desktop actions, modern toggles, parser summary cards, and clearer privacy cues.
- Added Roaming, Neutral, and WhatsApp preset cards for faster agency/client sharing workflows.
- Added broader common airline metadata while preserving universal parsing for any valid airline code.
- Added an unknown-airline parser fixture so the generic fallback is tested against codes that are not in local metadata.
- Added cache-busted local CSS/JS/logo links and a visible build label so live cPanel deployments are easier to confirm.

## 1.2.0 - 2026-05-04

- Changed parsing strategy so universal air segment extraction runs before source-specific parsing.
- Added configuration default merging so older live `config/settings.php` files do not disable new feature flags.
- Improved flexible GDS fallback parsing for merged airline/flight formats such as `3U3902 L 20JUN 6 KTMTFU DK1`.
- Fixed low-confidence Amadeus/Travelport detections blocking the flexible GDS fallback.
- Added Sichuan Airlines, Chengdu Tianfu, and a local `3U` airline badge.
- Reworked share/display controls into grouped sections.
- Replaced internal two-line/three-line labels with agency-facing Detailed, Compact, Table, and WhatsApp layouts.
- Added Roaming Branded, Neutral, and WhatsApp presets.
- Updated cPanel deployment so built-in airline logos deploy without deleting custom cPanel-only airline logos.
- Removed browser-native fieldset rendering from the control panel.
- Added auto-refresh of the itinerary card when share/display settings are changed after conversion.

## 1.0.0 - 2026-05-01

- Initial plain PHP 8.x release.
- Added Amadeus, Travelport Galileo / Smartpoint / Worldspan, and Sabre parser modules.
- Added confidence scoring and low-confidence manual review behavior.
- Added sensitive-line filtering for payment, FOID, DOCS, contact, private remark, and sensitive SSR/OSI content.
- Added branded itinerary card, clean share view, PNG export, print stylesheet, text copy, and optional image clipboard action.
- Added local airline and airport metadata fallbacks.
- Added dependency-free fixtures and parser test runner.
