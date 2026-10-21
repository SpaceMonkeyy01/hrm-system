# Deploying on Render (free plan)

What runs where:

| Part | Service | Cost |
|---|---|---|
| The app | Render free web service, from the `Dockerfile` and `render.yaml` in this repo | Free |
| The database | A free Postgres that doesn't expire: [Neon](https://neon.tech) or [Supabase](https://supabase.com) | Free |
| Daily reminder email | [cron-job.org](https://cron-job.org) opens a secret link every morning | Free |
| Email sending | [Resend](https://resend.com) (web API; Render free blocks the SMTP ports) with your company domain | Free: 3,000 a month, 100 a day |

Things to know about the free plan:

- **It sleeps after 15 minutes without visitors.** The first visit after that takes about a minute to load. That is normal.
- **The app's own disk is wiped on every restart**, which is why the database lives in Neon/Supabase.
- **Uploads (scans, photos, signed leave forms) go to a private Cloudflare R2 bucket**, because the app's own disk would lose them on restart. See "Uploads" at the end.
- **Real HR data:** the Postgres provider stores it. Pick the region closest to you (Singapore for Pakistan) and keep the connection string private.

About 30 minutes the first time.

## 1. Put the code on GitHub

1. Create a **private** repository on GitHub, for example `hrm-system`. Don't add a README.
2. On your computer, in the `hrm-system` folder:

   ```bash
   git remote add origin https://github.com/YOUR-ACCOUNT/hrm-system.git
   git push -u origin main
   ```

   Git opens a browser window to sign in to GitHub the first time.

The repository never contains real HR data: `.gitignore` keeps out `.env`, the SQLite database and uploads.

## 2. Create the database

**Neon** (or Supabase, the steps are the same):

1. Sign up, create a project. Region: **AWS Asia Pacific (Singapore)**. Postgres version 16 or 17.
2. Copy the **connection string** (Dashboard > Connect). It looks like
   `postgresql://user:password@ep-something.ap-southeast-1.aws.neon.tech/neondb?sslmode=require`.
   Use the direct (not "pooled") string.

## 3. Create the app on Render

1. Make an app key on your computer, in the `hrm-system` folder:

   ```bash
   php artisan key:generate --show
   ```

   Copy the whole line it prints, including `base64:`.
2. Render dashboard > **New > Blueprint** > connect your GitHub account > pick the `hrm-system` repo. Render reads `render.yaml`.
3. Render asks for the secret values:

   | Setting | What to put |
   |---|---|
   | `APP_KEY` | the line from step 1 |
   | `APP_URL` | `https://hrm-system.onrender.com` (the name Render shows; change it later if you add your own domain) |
   | `DB_URL` | the Neon connection string |
   | `RESEND_API_KEY`, `MAIL_FROM_ADDRESS` | see step 6. Leave them for later if you like; the app works, it just can't send email. |

4. **Apply**. The first build takes 5 to 10 minutes. On every start the app runs its database migrations, so the Neon database gets all the tables by itself.
5. Open the Render URL. You should see the login page. (Your local admin login won't work yet: the database is still empty.)

## 4. Copy your local data into the live database

This copies everything from the SQLite database on your computer (employees, attendance, links, settings, your admin login) straight into Neon. It doesn't go through GitHub or Render.

1. In `hrm-system/.env` on your computer, add one line with the same Neon connection string:

   ```
   COPY_TARGET_URL="postgresql://user:password@ep-something.ap-southeast-1.aws.neon.tech/neondb?sslmode=require"
   ```

2. Your computer's PHP needs the Postgres driver: in `php.ini`, turn on `extension=pdo_pgsql` (remove the `;` in front).
3. Run:

   ```bash
   php artisan hr:copy-data
   ```

   It prints each table with how many records it copied. If the live database already has employees (someone used the live site), it stops; add `--wipe` only if you want to replace everything there with your local copy.
4. Remove the `COPY_TARGET_URL` line from `.env` again.
5. Log in on the live site with your local admin email and password.

Do the copy once, before HR starts using the live site. After that the live site is the real one: don't copy again, or HR's changes there are lost.

## 5. Daily reminder email

Render free has no always-on scheduler, so an outside service opens the app's reminder link each morning:

1. Render > your service > **Environment**: copy the value of `CRON_TOKEN`.
2. [cron-job.org](https://cron-job.org) > create a free account > **Create cronjob**:
   - URL: `https://hrm-system.onrender.com/cron/reminders/THE-CRON-TOKEN`
   - Schedule: every day at 08:00, time zone Asia/Karachi
   - Request timeout: the longest allowed (the first request wakes the app, which takes about a minute)
3. Save and press **Test run**. The response says what happened, e.g. "Sent 3 reminders to 2 people." or "Nothing needs attention on 2026-10-13. No email sent." Opening the link a second time on the same day does nothing ("Already sent today.").

Keep the link private: anyone with it can trigger the email (only to HR and admin, and only once a day).

## 5b. Attendance machine sync

The app reads ZKTeco machines over the internet: new punches, the user list and the clock. It never changes,
clears or restarts anything on a machine. Machines are kept on **Attendance > Devices** (address, port, Comm Key,
switched on, automatic sync, timeout, re-check days, clock warning), one row per machine. Enrol each person under
the same number on every machine: one machine number links a person on all of them.

1. On the machine: set a **Comm Key** that isn't a default like 123456 (*Comm. > Comm Key*).
2. On the office router: port forward the static IP's port 4370 to the machine. **Allow only Render's outbound IPs**
   (Render dashboard > your service > *Connect* > *Outbound*), so nobody else on the internet can reach the machine.
3. Attendance > Devices > **Add device**: the static IP, port 4370 and the Comm Key, then **Test connection**.
   (Older installs that had `ZK_HOST` / `ZK_PORT` / `ZK_COMM_KEY` set got that machine added here automatically on
   upgrade; those settings aren't used after that and can be removed from Render.)
4. **Sync now** on the device. The first sync brings in everything stored on the machine
   (about 6 seconds for 20,000 punches); after that each sync only compares the last 3 days.
5. cron-job.org > **Create cronjob**:
   - URL: `https://hrm-system.onrender.com/cron/machine/THE-CRON-TOKEN` (same token as the reminders; reads every
     device that is switched on with automatic sync on)
   - Schedule: every 10 minutes (every 5 is fine too)
   - Turn on "notify on failure": a failed sync answers with an error, e.g. "Sync failed: Couldn't reach the machine".

The Attendance page shows when the machine was last synced, and warns when the machine's clock is more than a minute
off (set it under the machine's *Date/Time* menu). Uploading a Full day log still works and never doubles punches
the sync already brought in.

## 5c. Clock in on the app

Staff with a login see **Clock in / Clock out** on their My page. A clock-in from an office network counts as office
time, from anywhere else as remote. HR keeps the office networks on **Attendance > App clock-ins** (no redeploy
needed): it lists addresses people clocked in from that aren't on the list yet, with an "Add as office network"
button, and can re-mark earlier clock-ins from a newly added address. Optionally, `HRM_OFFICE_IPS` in Render >
Environment adds fixed ones (comma-separated; `203.0.113.0/24` ranges work too). `HRM_CLOCK_ENABLED=false` hides the
button for everyone.

## 6. Staff logins and email

Logins for staff (Settings > Users > Create staff logins) send each person a link to choose their password; welcome
emails, password resets and the daily reminder go out the same way. Render's free plan blocks the ports normal email
servers use (SMTP: 25, 465, 587), so Gmail settings won't work there. The app sends through **Resend's web API**:

1. Sign up at resend.com, then **Domains > Add domain**: your company domain (or a subdomain like `mail.yourcompany.com`).
2. Add the DNS records Resend shows (at whoever runs the domain's DNS) and wait until the domain shows **Verified**.
3. **API Keys > Create API key** (permission "Sending access" is enough).
4. Render > Environment: `MAIL_MAILER` = `resend`, `RESEND_API_KEY` = the key, `MAIL_FROM_ADDRESS` = an address on the
   verified domain, e.g. `hr@yourcompany.com` (it doesn't need to be a real mailbox). Save; Render redeploys.
5. Settings > Users > **Send a test email**: it goes to your own login's address, or shows Resend's error (an unverified
   domain or a wrong key say so).

On a paid Render plan, ordinary SMTP works too (`MAIL_MAILER=smtp` with `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
`MAIL_PASSWORD`).

After changing environment values, Render restarts the app by itself.

## Updating the live site

Push to GitHub (`git push`). Render builds and deploys the new version, then runs any new migrations at start. Takes about 5 minutes; the old version keeps serving until the new one is ready.

## Uploads

Set up on a **Cloudflare R2** bucket (10 GB free), `hrm-files`. `render.yaml` switches uploads on and names the bucket and
endpoint; only the two keys live in Render > Environment. To set it up again (new account or bucket):

1. Cloudflare > R2 > create a bucket, e.g. `hrm-files` (private; never turn on public access).
2. R2 > Manage API tokens > create a token with Object Read & Write on that bucket. Note the access key, the secret and the S3 endpoint `https://ACCOUNT-ID.r2.cloudflarestorage.com`.
3. Render > Environment:

   ```
   HRM_FILES_ENABLED=true
   HRM_FILES_DISK=s3
   AWS_ACCESS_KEY_ID=...
   AWS_SECRET_ACCESS_KEY=...
   AWS_DEFAULT_REGION=auto
   AWS_BUCKET=hrm-files
   AWS_ENDPOINT=https://ACCOUNT-ID.r2.cloudflarestorage.com
   AWS_USE_PATH_STYLE_ENDPOINT=true
   ```

Files are only ever served through the app to HR and admin logins, never from a public link.

## If something goes wrong

| What you see | Where to look |
|---|---|
| Build fails | Render > the deploy > Logs. Usually a typo in `render.yaml` or a missing file |
| "Server error" page | Render > Logs (the app logs to there). Most often `APP_KEY` or `DB_URL` is wrong or missing |
| Login page, but the admin login fails | Step 4 hasn't run yet, or ran against a different database |
| Pages load very slowly the first time | The free instance was asleep. Normal |
| No reminder email | cron-job.org > the job's history (the response text says what happened), then Settings > Users > Send a test email |
| Links in emails point to the wrong address | `APP_URL` |
