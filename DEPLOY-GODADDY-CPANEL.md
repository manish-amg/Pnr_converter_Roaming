# Deploying on GoDaddy cPanel

These steps assume the preferred address is `pnrconverter.roamingnepal.com`.

## 1. Create the Subdomain

1. Log in to GoDaddy.
2. Open your hosting product.
3. Open cPanel.
4. Find **Domains** or **Subdomains**.
5. Create `pnrconverter` under `roamingnepal.com`.
6. cPanel will show a document root folder. It may look like `public_html/pnrconverter` or `public_html/pnrconverter.roamingnepal.com`.

If you prefer a path instead, upload the project to `public_html/pnr-converter`.

## 2. Upload the Files

1. Open **File Manager** in cPanel.
2. Go to the subdomain document root folder.
3. Upload the ZIP file for this project.
4. Click the ZIP and choose **Extract**.
5. Make sure `index.php` is directly inside the subdomain folder.

Correct:

```text
public_html/pnrconverter/index.php
public_html/pnrconverter/app/
public_html/pnrconverter/assets/
public_html/pnrconverter/config/
```

If everything extracted inside an extra folder, move the contents up one level.

## 3. Edit the Settings

Open `config/settings.php` in cPanel File Manager and edit:

- `agency_name`
- `contact_phone`
- `contact_email`
- `whatsapp`
- `footer_note`
- `base_url`

Leave `privacy_logging_enabled` as `false` for normal use.

## 3A. Database Setup (Phase 2 — auth & credits)

The app now requires login. Set this up once:

1. In cPanel, open **MySQL® Databases** and create a database and a database user (grant that user **All Privileges** on the database).
2. Open **phpMyAdmin**, select the new database, go to the **Import** tab, and upload `schema.sql` from this repo. This creates `agencies`, `users`, `credit_ledger`, `usage_daily`, and `documents`, plus a seed row in `agencies` for the house agency.
3. Add the DB credentials to `config/settings.php`:

```php
'db' => [
    'host' => 'localhost',
    'name' => 'yourcpanelusername_pnrconverter',
    'user' => 'yourcpanelusername_pnruser',
    'pass' => 'the-password-you-set',
    'charset' => 'utf8mb4',
],
```

4. Create the first superadmin login. If cPanel gives you SSH/Terminal access, run:

```bash
php bin/create-admin.php you@example.com "Your Name" "a-strong-password"
```

   If you don't have shell access, ask your host to enable **Terminal** in cPanel, or run the same script once via SSH from another machine pointed at the same database.
5. Visit the site — you'll be redirected to `login.php`. Sign in with the superadmin account you just created.

New agencies can self-register from `register.php` (creates an agency + an `owner` account with 0 credits). The superadmin flags trusted staff accounts as `internal` from `admin.php` to bypass the 50/day free-conversion cap.

## 3B. Visa Doc Migration

The Visa Doc feature (`visa-doc.php`, `verify.php`) needs a few extra columns on the `documents` table. Run once via phpMyAdmin's **SQL** tab after `schema.sql`:

```sql
ALTER TABLE documents
  ADD COLUMN passenger_name VARCHAR(160) NULL AFTER type,
  ADD COLUMN route_summary  VARCHAR(120) NULL AFTER passenger_name,
  ADD COLUMN travel_date    DATE NULL AFTER route_summary,
  ADD COLUMN reference_no   VARCHAR(20) NULL AFTER travel_date,
  ADD UNIQUE KEY uniq_reference_no (reference_no);
```

(Also saved at `migrations/002_visa_doc_fields.sql`.) Without this migration, generating a visa document still works and still deducts a credit, but `verify.php` won't have a reference/route/passenger to show — the verify footer is silently omitted until the migration runs.

## 4. Replace the Logo

1. Upload the new logo to `assets/images/`.
2. Edit `config/settings.php`.
3. Change `logo_path`, for example:

```php
'logo_path' => 'assets/images/roaming-nepal-logo.png',
```

Use a local file only. Do not use a remote logo URL.

## 4A. Add Airline Logos

Airline logos are read from local files only, so no passenger itinerary data is leaked to an outside logo service.

1. Upload airline logo files into `assets/images/airlines/`.
2. Name each file by the airline IATA code, for example:

```text
QR.svg
EK.png
RA.webp
```

3. Turn on **Show airline logo** in the converter.

If a logo is missing, the app safely shows the airline code badge instead.

## 4B. Live Footer Details

The GitHub repo does not overwrite `config/settings.php` because that file contains live branding and contact details. Make sure the live cPanel `config/settings.php` contains this footer block:

```php
'footer' => [
    'head_office' => [
        'title' => 'ROAMING NEPAL TRAVEL & TOURS PVT. LTD.',
        'lines' => [
            'A: Gairidhara-02, Nil Saraswoti Marg, Kathmandu, Nepal',
            'P: +(977) 015905391, 015905392',
            'M: +(977) 9851075316, 9841093086, 9851193482',
            'W: www.roamingnepal.com',
        ],
    ],
    'branches_title' => 'YOU CAN ALSO FIND US AT:',
    'branches' => [
        [
            'title' => 'POKHARA',
            'lines' => [
                '#Pokhara-06, Lakeside (Khahare), Kaski, Nepal',
                '+977-61-591401, 591402',
            ],
        ],
        [
            'title' => 'AUSTRALIA',
            'lines' => [
                '#15 Crossing Road, Mernda, VIC, 3754, Australia',
                '+(61) 0452055393',
            ],
        ],
    ],
],
```

Also confirm these feature defaults are enabled when Roaming branding should appear:

```php
'show_agency_header' => true,
'show_agency_footer' => true,
'show_disclaimer' => true,
'show_airline_logo' => true,
```

## 4C. Agency-Neutral Sharing

Other agencies can use the converter without showing Roaming branding on the exported itinerary card.

Before pressing **Convert**, turn off:

- Show agency header
- Show footer/contact
- Show disclaimer

The tool remains hosted on Roaming Nepal's website, but the itinerary card becomes neutral for client sharing.

## 5. Set File Permissions

Most cPanel hosting works with:

- Folders: `755`
- Files: `644`

If optional technical logging is enabled, the app may create `storage/logs/technical.log`. Keep that folder outside public access if your hosting setup allows it, or leave logging disabled.

## 6. Enable HTTPS

1. In cPanel, open **SSL/TLS Status** or **SSL Manager**.
2. Run AutoSSL for the subdomain if needed.
3. Confirm the tool opens at `https://pnrconverter.roamingnepal.com`.

## 7. Protect the Tool

Use this step if the converter should be private for Roaming staff or approved agency users.

Simple cPanel protection:

1. Open **Directory Privacy** in cPanel.
2. Select the subdomain folder.
3. Enable password protection.
4. Create usernames and passwords for the people who should use it.
5. Test in a private browser window.

## 8. Test After Deployment

1. Open the protected HTTPS URL.
2. Confirm the header shows the newest build label, for example `Build 3.0.0`.
3. Paste a sample fixture from `tests/fixtures/`.
4. Press **Convert**.
5. Confirm the itinerary card is generated for Amadeus, Travelport/Galileo/Smartpoint, Sabre, and generic GDS segment samples.
6. Try the **Roaming**, **Neutral**, and **WhatsApp** presets.
7. Try **Clean Share View**.
8. Try **Download PNG**.
9. Try browser **Print** and choose **Save as PDF**.
10. Press **Reset** and confirm pasted content is cleared.

## 9. Updating Later

1. Keep a backup copy of `config/settings.php` and any custom logo.
2. Upload the new ZIP.
3. Extract and replace app files.
4. Restore your `config/settings.php` if needed.
5. Test again with the fixtures.

## Optional SSH Path

If SSH is enabled:

```bash
cd ~/public_html/pnrconverter
unzip pnr-converter.zip
php tests/run-tests.php
```

No Composer or Node commands are required.

## Appendix: Node Alternative

cPanel Application Manager can run Node apps on some hosting plans, but this project intentionally avoids that path. The primary deployment is plain PHP uploaded through cPanel File Manager.
