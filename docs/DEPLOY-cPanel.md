# Deploying on the cPanel VPS

Free components only: cPanel's Apache + PHP-FPM, cPanel MySQL, AutoSSL. About 30 minutes the first time.

Paths below assume the cPanel user is `cpuser` and the app lives in `/home/cpuser/hrm`. Replace both.

## 1. PHP version

WHM > EasyApache 4: make sure **PHP 8.3** (or 8.4) is installed with these extensions: `pdo_mysql`, `mbstring`, `xml`, `dom`, `zip`, `fileinfo`, `tokenizer`, `ctype`, `curl`, `openssl`.

The command-line PHP for that version is `/opt/cpanel/ea-php83/root/usr/bin/php`. In the steps below `php` means that path:

```bash
alias php=/opt/cpanel/ea-php83/root/usr/bin/php
```

## 2. Subdomain and database

1. cPanel > Domains > Create a New Domain: `hrm.yourdomain.com`. Untick "Share document root" and set the document root to `hrm/public`.
2. Point the subdomain's DNS (A record) at the VPS IP, in whichever panel hosts the domain's DNS zone. AutoSSL can't issue a certificate until this resolves.
3. cPanel > MultiPHP Manager: set the subdomain to PHP 8.3.
4. cPanel > Manage My Databases: create database `cpuser_hrm`, user `cpuser_hrm` with a strong password, and add the user to the database with ALL PRIVILEGES.

## 3. Code

Upload the project folder to `/home/cpuser/hrm` (File Manager: upload the zip, then Extract), or `git clone` it there from cPanel > Terminal.

The zip already contains the built frontend in `public/build`, so the server doesn't need Node. If you change anything in `resources/js` or `resources/css`, run `npm ci && npm run build` on your computer and upload `public/build` again.

## 4. PHP dependencies

```bash
cd ~/hrm
curl -sS https://getcomposer.org/installer | php        # creates composer.phar (skip if composer is installed)
php composer.phar install --no-dev --optimize-autoloader
```

## 5. Configuration

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` (File Manager > Edit):

```ini
APP_NAME="HRM System"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://hrm.yourdomain.com
APP_TIMEZONE=Asia/Karachi

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cpuser_hrm
DB_USERNAME=cpuser_hrm
DB_PASSWORD=the-password-from-step-2

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true

INERTIA_SSR_ENABLED=false
INERTIA_DEVTOOLS_ENABLED=false

# Mail, for the daily reminder email (step 11). Use a cPanel email account (cPanel > Email Accounts > Connect Devices).
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=mail.yourdomain.com
MAIL_PORT=465
MAIL_USERNAME=hrm@yourdomain.com
MAIL_PASSWORD=the-mailbox-password
MAIL_FROM_ADDRESS=hrm@yourdomain.com
MAIL_FROM_NAME="HRM System"
```

`APP_DEBUG=false` matters: with it on, an error page would show request data, including HR data.

`APP_URL` must be the real https address: the reminder email builds its links from it. `APP_TIMEZONE` decides what "today" is for reminders and when the email goes out.

## 6. Database tables and first login

```bash
php artisan migrate --force
php artisan db:seed --class=ChecklistItemSeeder --force
php artisan hr:create-admin you@company.com --name="Your Name"     # asks for a password
```

## 7. Import the sheet (once)

1. Download the Google Sheet as `.xlsx` (File > Download > Microsoft Excel).
2. Upload it to `~/hrm/storage/app/private/import/HR_Onboarding.xlsx` (this folder is not web-visible).
3. Run:

   ```bash
   php artisan hr:import storage/app/private/import/HR_Onboarding.xlsx
   ```

4. Download the report `storage/app/private/import/import-report-*.csv`, fix wrong matches in `aliases.csv` (see `docs/DATA-MIGRATION.md`), and run again with `--fresh` until the report looks right.
5. Delete the `.xlsx` and old reports from the server when you're done. They contain names and document statuses.

## 8. Speed and permissions

```bash
php artisan optimize          # caches config, routes and views
chmod -R 775 storage bootstrap/cache
```

## 9. HTTPS

cPanel > SSL/TLS Status > select the subdomain > Run AutoSSL. Then open `https://hrm.yourdomain.com` and log in.

## 10. Backups

cPanel > Backup > download a database backup now. For a nightly copy, cPanel > Cron Jobs:

```bash
0 2 * * * /usr/bin/mysqldump --defaults-extra-file=/home/cpuser/.my.cnf cpuser_hrm | gzip > /home/cpuser/backups/hrm-$(date +\%F).sql.gz
```

with `/home/cpuser/.my.cnf` (chmod 600):

```ini
[client]
user=cpuser_hrm
password=the-password
```

## 11. Daily reminder email (cron, required)

Every morning the app emails one list of reminders (probation ending or overdue, 30/60/90-day check-ins due or missed, documents still missing, offboarding clearance due) to every active login with the HR or admin role. Viewers are never emailed. Nothing is sent on a day with no reminders. The same list is on the dashboard under "Needs attention".

It needs Laravel's scheduler, run every minute by cron. cPanel > Cron Jobs > Add New Cron Job, "Once Per Minute":

```bash
* * * * * cd /home/cpuser/hrm && /usr/local/bin/ea-php83 artisan schedule:run >> /dev/null 2>&1
```

(`ea-php83` is EasyApache's PHP 8.3; use the same PHP binary as in step 1.) The email goes out at 08:00 in `APP_TIMEZONE`; change the time, the day counts or who gets it in `config/hrm.php` under `reminders`, then run `php artisan optimize` again.

Check it before relying on it:

```bash
php artisan hr:send-reminders --dry-run   # prints today's reminders and who would get them; sends nothing
php artisan schedule:list                 # shows hr:send-reminders at 0 8 * * *
php artisan hr:send-reminders             # sends now, if there is anything to send
```

If the email doesn't arrive, check the `MAIL_*` settings in `.env` (step 5) and `storage/logs/laravel.log`.

## 12. File uploads (scanned documents)

HR can upload PDF, JPG and PNG scans (up to 10 MB each) on an employee's Documents tab. They are stored in `storage/app/private/employee-files/`, outside `public_html`, and only HR and admin logins can open them through the app.

PHP's own limits must allow the size. cPanel > MultiPHP INI Editor > choose the domain and set:

```ini
upload_max_filesize = 11M
post_max_size = 12M
```

Include `storage/app/private/employee-files/` in your backups (step 10 covers only the database). Change the allowed types or size in `config/hrm.php` under `uploads`.

## Updating later

```bash
cd ~/hrm
# upload or git pull the new code, including public/build
php composer.phar install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
```

## If something goes wrong

| Symptom | Check |
|---|---|
| 500 error | `storage/logs/laravel.log`; permissions on `storage` and `bootstrap/cache` |
| Page without styles | `public/build` was not uploaded |
| "Vite manifest not found" | same: upload `public/build` |
| Login loops back | `APP_URL` must match the address in the browser, including https |
| `could not find driver` | `pdo_mysql` missing in EasyApache for PHP 8.3 |
| No reminder email | the `schedule:run` cron (step 11) and the `MAIL_*` settings; `php artisan hr:send-reminders --dry-run` shows what would be sent |
