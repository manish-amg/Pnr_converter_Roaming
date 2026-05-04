# System Rebuild Notes

## Product Direction

The converter is a Roaming-hosted utility for travel agents and agencies. It should behave like a fast PNR conversion workbench:

- Paste raw GDS text.
- Extract passenger-safe air itinerary data.
- Let the user choose branded or neutral output.
- Render a clean passenger copy in Detailed, Compact, Table, or WhatsApp layout.
- Export by PNG, print/PDF, copied text, or clipboard image where supported.

## Competitive Research Summary

Public PNR converter tools emphasize:

- Support for Amadeus, Sabre, Galileo, Smartpoint, Worldspan, and flexible GDS-style air segments.
- Multiple layouts such as table, two-line, three-line, graphic, PDF, email, and WhatsApp-friendly output.
- Display toggles for airline name, airline logo, cabin, booking class, aircraft, operated-by, distance, transit time, seats, ticket numbers, and airline locators.
- Branding controls for agency logo/contact details and neutral passenger output.
- Local or account-managed airline logos.

## Architecture

The parser must not depend on a single guessed source family. Source detection is only a label.

Parsing order:

1. Universal air segment extraction.
2. Family-specific parsers for Amadeus, Travelport/Galileo/Smartpoint/Worldspan, and Sabre.
3. Unknown/manual review only when no safe air segments are found.

This prevents a weak source-family guess from blocking valid segments.

## Parser Principles

- Parse standard GDS air segment patterns across families.
- Treat airline code and flight number as IATA-like tokens, not a fixed airline list.
- Use local airline metadata only for nicer labels; never require metadata to parse.
- Use local airline logos when present; otherwise show a badge.
- Never parse or display payment, passport/APIS, FOID, contact, private remarks, or sensitive SSR/OSI text by default.
- Low confidence must not silently render bad data.
- If valid segment fields are extracted but the family is uncertain, render with a staff warning.

## UI Principles

- The first screen is the tool, not a landing page.
- Keep controls grouped by user intent:
  - Branding
  - Flight Details
  - Passenger Safe Data
  - Distance
  - Layout: Detailed, Compact, Table, WhatsApp
- Changing display controls after conversion should refresh the passenger card.
- Clean Share View must hide input and admin controls.
- Header, footer/contact, and disclaimer must be independently toggleable.

## Deployment Principles

- GitHub stores code.
- cPanel stores live `config/settings.php`.
- Code updates must not overwrite live branding/contact settings.
- `config/settings.php` is merged over `config/settings.example.php` so new flags get safe defaults after deployment.
