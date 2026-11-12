# Drop

Minimal, anonymous file sharing. Drop a file, get a short link, and it is gone after 24 hours.

- No accounts. Drag files anywhere on the page, or pick them.
- Short links: `https://your.domain/hQu7jK9`, seven random letters and digits.
- Files expire 24 hours after upload. A scheduled task deletes them from storage.
- Up to 2 GB per file. Files go straight from the browser to a **private** Cloudflare R2 bucket; your server never handles the bytes.
- A password-protected backoffice at `/backoffice` lists current files. It is linked nowhere.
- Light and dark mode follow the visitor's device.

Built with PHP 8.4, [CrabPHP](https://github.com/crabphp/framework), [crabphp/s3](https://github.com/crabphp/s3) and SQLite. No Docker, no frontend build step, one CSS file and one JS file.

## Requirements

- PHP 8.4 with `pdo_sqlite`, `mbstring`, `simplexml`, `pcntl` and `posix`
- Composer
- A Cloudflare account with R2 enabled
- For running the tests: the `dom` and `xmlwriter` extensions

## Quick start (local)

```sh
git clone https://github.com/crabphp/drop.git
cd drop
composer install
php scripts/setup.php    # creates .env with a random APP_KEY
php cua migrate          # creates the SQLite database in storage/
php cua dev              # serves http://127.0.0.1:8080, restarts on file changes
```

The pages load right away. Uploads answer "Uploads are not configured yet" until you finish the R2 setup below. After editing `.env`, stop and start `php cua dev` again so it picks up the new values.

## Set up Cloudflare R2

### 1. Create a private bucket

In the Cloudflare dashboard, open **R2 → Create bucket**, name it (for example `drop`), and keep it **private**: do not enable the `r2.dev` URL or attach a public custom domain. Drop hands out short-lived signed URLs instead.

### 2. Create an API token

Open **R2 → Manage API tokens → Create API token**:

- Permission: **Object Read & Write**
- Scope: only the bucket you just created

Copy the **Access Key ID** and **Secret Access Key**. Your **Account ID** is shown on the R2 overview page.

### 3. Fill in `.env`

```dotenv
APP_URL=http://127.0.0.1:8080
ADMIN_TOKEN=sample

R2_ACCOUNT_ID=your-account-id
R2_BUCKET=drop
R2_ACCESS_KEY_ID=your-access-key-id
R2_SECRET_ACCESS_KEY=your-secret-access-key
```

`.env` is ignored by Git. Never commit it.

### 4. Allow browser uploads (CORS)

Browsers upload directly to R2, so the bucket must accept requests from your site. Open the bucket's **Settings → CORS policy → Edit** and paste [`deploy/r2-cors.json`](deploy/r2-cors.json), with your own origins:

```json
[
  {
    "AllowedOrigins": ["https://drop.example.com", "http://127.0.0.1:8080"],
    "AllowedMethods": ["PUT"],
    "AllowedHeaders": ["content-type", "content-length", "if-none-match"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 300
  }
]
```

Each origin must match `APP_URL` exactly: scheme, host and port. Remove the local one in production if you don't need it.

### 5. Check the connection

```sh
php scripts/check-r2.php
```

It uploads a tiny test object, reads it back, checks overwrite protection and CORS, then deletes it. It never prints credentials or signed URLs.

### 6. Optional: a lifecycle safety net

Add a lifecycle rule on the bucket that deletes objects under `files/` after **2 days**. Drop deletes files itself after 24 hours; this only catches anything left behind if the scheduler was down for a long time.

## Configuration

All settings live in `.env`. See [`.env.example`](.env.example).

| Variable | Default | |
| --- | --- | --- |
| `APP_URL` | `http://127.0.0.1:8080` | Public origin of the site. Used for links and for checking that API requests come from your own pages. |
| `APP_KEY` | random | Secret for hashing. `scripts/setup.php` generates it. |
| `ADMIN_TOKEN` | `sample` | Backoffice password. **Change it before going public.** |
| `R2_ACCOUNT_ID` | | Cloudflare account ID |
| `R2_BUCKET` | `drop` | Bucket name |
| `R2_ACCESS_KEY_ID` | | API token's access key |
| `R2_SECRET_ACCESS_KEY` | | API token's secret |
| `MAX_FILE_BYTES` | `2000000000` | Largest file, in bytes (2 GB). R2 accepts at most 5 GB in one upload. |
| `MAX_STORED_BYTES` | `10737418240` | Total storage Drop may use at once (10 GiB), counting files not yet cleaned up |
| `SERVER_ADDRESS` | `tcp://127.0.0.1:8080` | Where the app listens |

Generate a strong admin token with:

```sh
php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'
```

## Deploy (single server, no Docker)

Drop is its own web server: no PHP-FPM or nginx needed for PHP. Put a reverse proxy in front for HTTPS.

1. **Get the code** onto the server, for example in `/srv/drop`, owned by a dedicated `drop` user:

   ```sh
   sudo useradd --system --home /srv/drop --shell /usr/sbin/nologin drop
   sudo git clone https://github.com/crabphp/drop.git /srv/drop
   cd /srv/drop
   sudo -u drop composer install --no-dev --prefer-dist
   sudo -u drop php scripts/setup.php
   ```

2. **Configure** `/srv/drop/.env`: `APP_URL=https://drop.example.com`, a strong `ADMIN_TOKEN`, `APP_DEBUG=false`, and your R2 values. Add your production origin to the bucket's CORS policy.

3. **Create the database:**

   ```sh
   sudo -u drop php cua migrate
   ```

4. **Run the service.** Copy the systemd unit, adjust paths and user if yours differ, and start it:

   ```sh
   sudo cp deploy/drop.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now drop
   ```

   One service runs both the HTTP server on `127.0.0.1:8080` and the scheduler that cleans up expired files every five minutes. It restarts on failure and starts at boot.

5. **Add HTTPS.** With [Caddy](https://caddyserver.com), add a site block (see [`deploy/Caddyfile`](deploy/Caddyfile)) to `/etc/caddy/Caddyfile`, then `sudo systemctl reload caddy`:

   ```
   drop.example.com {
       encode zstd gzip
       reverse_proxy 127.0.0.1:8080
   }
   ```

   Point your domain's DNS at the server. Caddy gets a certificate automatically.

6. **Verify:** open `https://drop.example.com/ready` (should say `"ready": true`), then upload and download a real file in a browser.

### Updating

```sh
cd /srv/drop
sudo -u drop git pull
sudo -u drop composer install --no-dev --prefer-dist
sudo -u drop php cua migrate
sudo systemctl restart drop
```

Restart the service after any change to `.env`. Logs: `journalctl -u drop -f`.

### Backups

Everything worth keeping is in `storage/database.sqlite`. Files themselves expire within a day. Back up the database with a WAL-aware tool such as `sqlite3 storage/database.sqlite ".backup backup.sqlite"`, not by copying the file while the app runs.

## Backoffice

Open `/backoffice` and enter `ADMIN_TOKEN`. It lists current files with size, time left and a copy-link button, 50 per page. There is no delete button: files only leave by expiring.

Sessions last 12 hours in an HttpOnly cookie. Changing `ADMIN_TOKEN` and restarting signs everyone out. Login attempts are rate-limited.

## Cleanup

The scheduler runs `drop:cleanup` every five minutes. You can also run it by hand:

```sh
php cua drop:cleanup
php cua schedule:list
```

It deletes expired files from R2 first, then from SQLite, so a failed deletion is retried on the next run. It also clears abandoned uploads, old sessions and old rate-limit counters.

Links stop working at exactly 24 hours even if cleanup is late: every page and download checks the expiry time itself.

## How it works

1. The browser asks `POST /api/uploads` for a slot. The server reserves the size and returns a signed R2 upload URL valid for one hour.
2. The browser uploads the file straight to R2. The signature fixes the exact size, and the upload cannot overwrite an existing object.
3. The browser calls `POST /api/uploads/{id}/complete`. The server checks the object in R2 really has the expected size, then starts the 24-hour clock and returns the link.
4. Visiting the link shows the file name, size and time left. **Download** redirects to a signed R2 URL that lasts at most 60 seconds and never past the file's expiry.

Storage keys are random and unrelated to the link or the file name.

## Security notes

- Anyone with a link can download the file until it expires. Links are hard to guess but are not a substitute for encryption.
- Files are not encrypted end-to-end or scanned for malware. Downloads are always sent as attachments, never rendered in the browser.
- The API only accepts requests whose `Origin` matches `APP_URL`.
- Behind a proxy other than one on `127.0.0.1`, update `TrustedProxies` in `app.php` so client IPs are read correctly. Never trust every proxy.
- Rotate your R2 token if it was ever exposed.

## Development

```sh
composer test            # PHP tests: in-memory SQLite, fake storage, no network
php cua describe app     # checks configuration
npm ci                   # Playwright, for browser checks
npm run check:js
```

Browser checks run against a separate fixture server with fake storage; see [`tests/browser/README.md`](tests/browser/README.md).

Project layout:

```
app/                 actions, upload logic, R2 adapter, backoffice sessions
routes/web.php       all HTTP routes
resources/views/     plain HTML templates
public/assets/       drop.css and drop.js
database/migrations/ SQLite schema
deploy/              CORS policy, systemd units, Caddyfile
scripts/             setup and R2 check
tests/               PHPUnit and Playwright checks
```

## License

[MIT](LICENSE)
