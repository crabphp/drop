# Drop

Minimal anonymous 24-hour file sharing (open source, MIT; the author runs it at drop.daudau.cc). PHP 8.4 CrabPHP, SQLite metadata, private Cloudflare R2 objects. No Docker, user accounts, or manual file deletion UI.

- Public sharing URLs are root-level, exactly seven random ASCII letters/digits. No symbols, filename, or /file/ prefix.
- Admin is /backoffice, password/token protected by ADMIN_TOKEN (.env default sample); never link it from public pages or include it in a sitemap.
- Homepage drop target fills the entire screen, capped at 100dvh, with no header, footer or dashed border. Center the essential upload controls. Do not show a size/retention explanatory note; keep the 2 GB and 24-hour rules enforced by the application.
- Keep UI text essential: controls, file details, limits, expiry, status and errors only. No slogans or marketing filler.
- Theme must follow the user's device system setting automatically using prefers-color-scheme.
- Default per-file limit is 2 GB (2000000000 bytes), configurable in .env. No hourly/per-client upload count limit; do not rate-limit upload creation or completion.
- Use our own minimal public/assets/drop.css and system fonts. No design-system dependency, vendored component CSS, bundled fonts, or CSS build step.
- Keep credentials, .env, logs, sessions, and SQLite files out of Git. Never log tokens or signed URLs. Deploy examples use drop.example.com placeholders.
- Services outlive requests; never store request-specific state in them. Inject services in constructors.
- Use bound SQL. Never hold a database transaction during an R2 call.
- Downloads and completion must check expiry server-side; cleanup is not the access control mechanism.
- Run composer test and php cua describe app after changing code/configuration.
- Run agents or long-running processes only in a new visible hc terminal, never a hidden background shell. Agent work must use a blank full-screen Bia session handed to the user, without supervision unless requested.
