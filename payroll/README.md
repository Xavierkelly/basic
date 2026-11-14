# Basic Payroll

A simple payroll and time-clock web app for service businesses, built from Kelly Rentals' payroll Google Sheet.
Many businesses can sign up; each one only ever sees its own data.

**Stack:** PHP 8.1+, MySQL 8 / MariaDB 10.6+, HTML, CSS, Bootstrap 5.3 (self-hosted), plain JavaScript with `fetch` + JSON. No frameworks, no build step.

## What it does

- **Sign up / sign in** for a business, with roles: owner, admin (runs payroll), manager (timesheets, reports), employee (clock in, own pay stubs).
- **Time clock** – one big Clock In / Clock Out button on the phone. The phone's GPS location is saved on every punch (a punch without location is still saved and flagged, unless the company requires location).
- **Hours entry is a setting**: company-wide "clock in/out" or "weekly total typed in", overridable per worker.
- **Overtime is optional**: off by default, company-wide with per-worker override (threshold and multiplier configurable).
- **Weekly pay periods** – start a week, hours are pulled from the clock, edit hours/rate/salary/commissions/deductions inline like a spreadsheet, taxes recalculate as you type, mark each check paid (locks it).
- **Tax engine** matching the sheet: IRS Pub. 15-T 2026 withholding with W-4 data, Social Security (wage cap), Medicare + Additional Medicare, Texas / Illinois / no-income-tax states, 1099 withholding rate. Lines for the same person on the same pay date are combined and split to the cent like the sheet.
- **Pay stubs** (printable) and **reports** filtered by date, branch, department, employee, status, with CSV export.
- **Branches and departments are plain tags.**
- **Import** the existing workbook (.xlsx): Tax Settings → employees and W-4 data, every weekly tab → pay periods. Paid history is imported exactly and locked.
- **Installable app (PWA)** – "Add to Home Screen" on iPhone and Android; opens full screen with its own icon.

- **Subscription billing with Square** – new businesses get a free trial, then subscribe on Square's hosted checkout (no card data on this server). Square webhooks keep the status current. If a subscription lapses the account becomes read-only, though employees can still clock in.

## Folder layout

```
payroll/
  public/        ← web root (point the server here)
    index.php    pages router (?p=page)
    api.php      JSON endpoints used by assets/app.js
    assets/      app.js, app.css, vendor/bootstrap
    manifest.webmanifest, sw.js, offline.html, icons/
  views/         page templates
  src/           Db, Auth, TaxEngine, Payroll, TimeClock, Importer, XlsxReader, Report, Tags
  sql/           schema.sql, tax_2026.sql
  bin/           install.php (create tables), import.php (CLI import)
  tests/         php tests/run.php
```

## Deploy on Cloudways

The full run sheet, with Cloudways settings, smoke test and troubleshooting, is in [DEPLOY.md](DEPLOY.md). In short:

1. Create a **PHP** application (PHP 8.1 or newer) and note its database name, user and password (Application → Access Details).
2. Deploy this repo with Cloudways **Deployment via Git** (or SFTP). Set the application's **webroot** to `public_html/payroll/public` (Application Settings → General → Webroot) so only `public/` is reachable.
3. Over SSH, in the `payroll/` folder:
   ```sh
   cp config.example.php config.php   # then edit config.php: base_url and db settings
   php bin/install.php                # creates tables and loads 2026 tax tables (safe to re-run)
   php bin/check.php                  # pre-flight check; should end with 0 failed
   ```
4. Turn on the free **SSL certificate** (Application → SSL Certificate). HTTPS is required for location and for installing the app on phones.
5. Open the site, click **Create an account**, then **Import spreadsheet** from the menu (Google Sheets: File → Download → Microsoft Excel).

After importing, check **Company settings → Count paid checks toward YTD from** (the import sets it to the sheet's Oct 16, 2026 date) and each employee's "wages paid before this app".

## Billing (Square)

1. In the Square Dashboard create a **subscription plan** (Items → Subscription plans) with a monthly variation. Copy the **plan variation ID**.
2. In the [Square Developer Dashboard](https://developer.squareup.com/apps) create an app and copy its **access token** and **location ID** (start with Sandbox).
3. Under **Webhooks**, add an endpoint `https://YOUR-DOMAIN/square-webhook.php` and subscribe to `subscription.created`, `subscription.updated`, `payment.created`, `payment.updated`, `invoice.payment_made` and `invoice.scheduled_charge_failed`. Copy its **signature key**.
4. Fill in the `square` section of `config.php` (`plan_price_cents` must equal the plan's price; `webhook_url` must exactly match the URL you registered).
5. Owners see **Billing** in the menu: **Subscribe with Square** opens Square's checkout, and **Cancel** ends it at the close of the paid period.

## Local development

```sh
cp config.example.php config.php   # point at a local MySQL/MariaDB database
php bin/install.php
php -S localhost:8080 -t public
php tests/run.php                  # tax engine tests
```

## Tests

`tests/TaxEngineTest.php` replays every W-2 check on the workbook's Oct 5 – Dec 6, 2026 tabs (names removed) through the tax engine:
all rows still driven by the sheet's formulas match to the cent, and paid snapshots match when replayed with the W-4 credit in force when they were paid.

## Yearly upkeep

Withholding tables change every January. Add a `sql/tax_2027.sql` with the new IRS Pub. 15-T Worksheet 1A brackets and constants, run it once, and pay dates in 2027 will use it automatically. If a year's tables are missing, W-2 paychecks show a tax error instead of silently withholding nothing.
