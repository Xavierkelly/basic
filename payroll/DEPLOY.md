# Deploying to Cloudways

A step-by-step run sheet for putting Basic Payroll on a Cloudways server. Steps marked **(you)** happen in your Cloudways, GitHub or Square account; everything else is a command to paste over SSH.

Passwords, database credentials and Square keys go **only** into `payroll/config.php` on the server. Never paste them into chat, email, GitHub or this repo (`config.php` is git-ignored).

---

## 1. Create the server and app (you)

1. Cloudways → **Add Server** (or use an existing server) → application type **Custom App** (plain PHP). Any size works to start; 1 GB is plenty.
2. **Server → Settings & Packages → Packages**: PHP **8.2 or 8.3**, MariaDB **10.6+** (or MySQL 8).
3. **Application → Application Settings → General**: turn **Varnish off** for this app. Varnish can cache signed-in pages and JSON responses.
4. **Application → Application Settings → PHP FPM Settings**: add these lines and save (the Kelly Rentals workbook is about 7 MB):
   ```
   php_admin_value[upload_max_filesize] = 32M
   php_admin_value[post_max_size] = 32M
   ```
5. **Application → Access Details**, note (don't share): the app URL, the **MySQL** database name, username and password, and the **Application Credentials** (SFTP/SSH user). Add your SSH public key or set a password for that user.

## 2. Connect the repo with Deployment via Git (you)

1. **Application → Deployment via Git → Generate SSH keys**, then **View SSH key** and copy it.
2. GitHub → `Xavierkelly/basic` → **Settings → Deploy keys → Add deploy key**, paste it, leave **write access off**.
3. Cloudways needs an **empty** `public_html` to clone into. Over SFTP/SSH delete the placeholder files in `public_html` (only on a fresh app).
4. Back in Deployment via Git: repo address `git@github.com:Xavierkelly/basic.git`, branch **`master`** (or the PR branch if deploying before merge), deployment path **`public_html`** (leave blank = `public_html`), then **Start Deployment**.
5. **Application Settings → General → Webroot**: set to **`public_html/payroll/public`** and save. Only that folder is reachable from the web. The old WordPress theme files at the repo root are deployed alongside but cannot be opened from a browser.

## 3. Configure and install (SSH)

SSH in with the Application Credentials, then:

```sh
cd public_html/payroll   # Application Credentials log in to the app folder
                         # with Master Credentials: cd applications/<app-folder>/public_html/payroll
cp config.example.php config.php
chmod 640 config.php
nano config.php
```

In `config.php` set:

| Setting | Value |
|---|---|
| `base_url` | Your app URL with `https://` and no trailing slash, e.g. `https://phpstack-123-456.cloudwaysapps.com` |
| `db.host` | `localhost` |
| `db.name`, `db.user`, `db.pass` | From Access Details → MySQL Access |
| `square.*` | Leave blank for now (step 6) |

Then:

```sh
php bin/install.php    # creates the tables and loads 2026 tax tables (safe to re-run)
php bin/check.php      # pre-flight: PHP, extensions, config, DB, tables, Square, upload limit
```

`bin/check.php` should end with `0 failed`. Two warnings are expected at this point: Square not set up yet, and the upload limit (the CLI ignores the PHP FPM setting from step 1.4).

## 4. HTTPS (you)

Open the app URL with `https://`. If it loads with a padlock you're done. Otherwise, or when you add your own domain: **Application → Domain Management** (add the domain, point its DNS A record at the server IP) → **SSL Certificate → Let's Encrypt**, and turn on **HTTPS redirection**. Update `base_url` in `config.php` to match.

Phones need HTTPS for clock-in location and for "Add to Home Screen".

## 5. First run and smoke test (you)

1. Open the site → **Create an account** (this makes you the owner).
2. **Import spreadsheet**: in Google Sheets, File → Download → Microsoft Excel (.xlsx), then upload it. You should see "Imported 26 weekly tabs." Alternatively import over SSH, which skips upload limits:
   ```sh
   php bin/import.php 1 ~/Kelly-Rentals-Payroll.xlsx   # 1 = your company id (first sign-up); delete the file afterwards
   ```
3. Check **Company settings → Count paid checks toward YTD from** and each employee's "wages paid before this app".
4. Open a recent week and compare a few checks against the sheet.
5. On your phone: sign in, **Clock In**, allow location, **Clock Out**, then Share → **Add to Home Screen** (iPhone) or menu → **Install app** (Android).
6. Print one pay stub and run one report with CSV export.

## 6. Square Sandbox billing (you, when ready)

Follow **Billing (Square)** in `README.md`. Use **Sandbox** credentials first. Register the webhook URL as `https://YOUR-DOMAIN/square-webhook.php`, then put the access token, location ID, plan variation ID, plan price in cents and webhook signature key into the `square` section of `config.php` on the server. Run `php bin/check.php` again; the Square lines should all be `OK`. Test with Square's sandbox test card, then switch `environment` to `production` with production keys when you go live.

## Updating later

Push to the deployed branch, then **Deployment via Git → Pull** (or add the Cloudways webhook to GitHub for auto-deploy). After a pull, run `php bin/install.php` (it only adds what's missing) and `php bin/check.php`. `config.php` is not in git, so pulls don't touch it.

Each January add `sql/tax_<year>.sql` and run it (see README, Yearly upkeep); `bin/check.php` warns when the current year's tables are missing.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "Missing config.php" | Step 3; the file goes in `public_html/payroll/`, not `public/` |
| Blank page / 500 error | **Application → Monitoring → Logs** (PHP error log); run `php bin/check.php` |
| Can't connect to database | Re-copy the MySQL credentials from Access Details; host `localhost` |
| Sign-in doesn't stick, or you see someone else's page | Varnish is on; turn it off (step 1.3) and purge the cache |
| Import says file too large | PHP FPM settings (step 1.4), or import over SSH |
| Location never recorded on phones | Site isn't on HTTPS (step 4) |
| Deployment via Git fails "not empty" | Empty `public_html` first (step 2.3) |
