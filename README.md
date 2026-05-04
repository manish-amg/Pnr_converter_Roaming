# Roaming Nepal PNR Converter

Roaming-hosted web tool for converting pasted GDS itinerary text into a clean passenger-friendly flight itinerary card. It can be kept employee-only, or hosted on Roaming Nepal's website for trusted agency users.

## What It Does

- Accepts pasted raw itinerary / PNR text from Amadeus, Travelport Galileo / Smartpoint / Worldspan, or Sabre style output.
- Parses passenger names, booking reference, flight segments, dates, times, airport codes, airline codes, booking class, cabin, layovers, operated-by text, ticket numbers, and seats when clearly detectable.
- Redacts or ignores sensitive lines such as payment, FOID, DOCS, contact, phone, email, private remarks, and sensitive SSR/OSI content.
- Produces a branded itinerary card with print/PDF, PNG download, text copy, clean share view, and optional image clipboard support.
- Includes converter-style display controls for transit time, distance, 12-hour clock, operated-by notes, aircraft, airline logos, and Detailed, Compact, Table, and WhatsApp layouts.
- Lets agencies hide the itinerary header, footer/contact block, and disclaimer when they need a neutral passenger copy.
- Uses dedicated Amadeus, Travelport/Galileo/Smartpoint/Worldspan, and Sabre parsers first, then a flexible generic GDS air-segment fallback for unfamiliar but standard-looking segment lines.
- Merges live `config/settings.php` with `config/settings.example.php`, so new feature defaults still work after code-only cPanel updates.
- Runs as plain PHP 8.x files on normal cPanel hosting. No database, Composer, Node build, Docker, or external CDN is required.

## File Tree

```text
pnr-converter/
  index.php
  app/
    bootstrap.php
    Parser/
    Support/
    View/
  assets/
    css/
    js/
    images/
  config/
    settings.example.php
    settings.php
  data/
    airlines.php
    airports.php
  docs/
    SYSTEM-REBUILD-NOTES.md
  tests/
    fixtures/
    run-tests.php
  DEPLOY-GODADDY-CPANEL.md
  DEPLOY-FROM-GITHUB.md
  CHANGELOG.md
```

## Configuration

Edit `config/settings.php`.

Important placeholders:

- `agency_name`
- `logo_path`
- `contact_phone`
- `contact_email`
- `whatsapp`
- `footer_note`
- `default_disclaimer`
- `base_url`
- feature defaults inside `features`

Raw pasted PNR text is not saved. Optional technical logging is off by default and never records raw input.

Do not commit live `config/settings.php` to GitHub. Keep real production settings on cPanel and use `config/settings.example.php` as the editable template.

## Parser Notes

The parser is intentionally conservative. High and medium confidence results render the itinerary. Low confidence results show unparsed lines for manual review rather than creating a bad passenger output.

Add new rules in:

- `app/Parser/AmadeusParser.php`
- `app/Parser/TravelportParser.php`
- `app/Parser/SabreParser.php`
- `app/Parser/GenericAirSegmentParser.php`

Metadata is optional and local:

- `data/airports.php`
- `data/airlines.php`

If metadata is removed or incomplete, the app falls back to IATA airline and airport codes.

Airline logos are optional and local. Add files named by airline code in `assets/images/airlines/`, for example `QR.svg`, `RA.png`, or `EK.webp`. The app never fetches airline logos from a remote website during conversion.

The itinerary card displays the airline logo beside the airline code and flight number when a matching local logo exists. If no logo file exists, it shows a clean airline-code badge and still displays the airline name when metadata is available.

## Running Tests

From the project folder:

```bash
php tests/run-tests.php
```

The test runner is dependency-free and prints extracted fields for each anonymized fixture.

## Local Preview

If PHP is installed locally:

```bash
php -S localhost:8080
```

Open `http://localhost:8080`.

## GitHub Workflow

This project is prepared for a fresh private GitHub repository. See `DEPLOY-FROM-GITHUB.md`.

Recommended flow:

```text
Local code -> GitHub private repo -> cPanel Git pull or ZIP upload
```

Keep live `config/settings.php` out of Git. Custom uploaded airline logos can be committed when they are generic assets, or kept only on cPanel when they are agency-specific.

## Security Checklist

- Protect the subdomain or folder with cPanel Directory Privacy or your own login if the tool should be private.
- Keep HTTPS enabled.
- Do not add analytics or third-party tracking scripts.
- Keep logo and assets local.
- Leave `privacy_logging_enabled` as `false` unless you need minimal technical success/failure logs.

## Agency Sharing

For agencies that do not want Roaming branding on the passenger copy, turn off these options before converting:

- Show agency header
- Show footer/contact
- Show disclaimer

The converter page is still hosted by Roaming Nepal, but the generated itinerary card can be neutral.
