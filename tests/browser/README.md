# Browser checks

Install dependencies with `npm ci --no-audit --no-fund`; install a browser with `npx playwright install chromium` if it is not already available.

Start the fixture in a **new visible terminal** from the app root:

```sh
APP_URL=http://127.0.0.1:8096 \
DB_DATABASE="$PWD/storage/browser-test.sqlite" \
ADMIN_TOKEN=sample \
php tests/browser/serve.php
```

The fixture refuses to use any other database path. It uses real Crab routes, templates, SQLite and authentication, but replaces object storage with a fake; no R2 credentials or external API calls are needed. The Playwright script intercepts the fake R2 PUT requests. This is not a production entrypoint.

Run in another visible terminal:

```sh
npm run test:browser
```

The script checks picker uploads, whole-screen drag/drop, immutable upload headers, copy-link, download redirects, login/logout, minimal UI without a header/footer, the 100dvh height cap with scrolling upload results, and light/dark layouts at 320, 390 and 1440 pixels. It also checks changing the system theme without reloading. Screenshots go in ignored `tests/browser/artifacts/`.

Stop the fixture after a run. Before starting a fresh run, remove only its disposable `storage/browser-test.sqlite`, `storage/browser-test.sqlite-wal` and `storage/browser-test.sqlite-shm` files while the fixture is stopped. Do not delete or replace the real `storage/database.sqlite`.

The fixture uses file-backed SQLite because Crab resets pre-server connections when starting; a pre-migrated `:memory:` database would disappear. PHP unit tests run in-process without that lifecycle and safely use `:memory:`.
