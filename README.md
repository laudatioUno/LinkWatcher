# LinkWatcher

Nightly URL health watchdog for translation text keys.

LinkWatcher fetches the localisation files of a (public) GitHub repository, picks every text key that contains a URL, checks all of those URLs and sends **one summary mail per night** when something is broken. A small dashboard shows the health of every URL, every key and every language.

- **Sources:** iOS `.strings` (`"key" = "value";`) and Android `strings.xml` (`<string name="key">value</string>`, incl. `string-array` / `plurals`), any number of files and languages
- **Key sync:** new keys are picked up automatically, vanished keys are archived (and restored if they come back)
- **Checks:** parallel (`curl_multi`) with a per-host limit, HEAD with automatic GET fallback, redirects followed, timeouts, delayed retry for failures, identical URLs checked only once
- **Result:** `OK` · `Warnung` (redirect to another URL, slow, 401/403/429 – often bot protection) · `Fehler` (4xx/5xx, DNS, connection, timeout, TLS)
- **Alerting:** one mail per run with all broken URLs, the affected keys/languages and a direct link to the line on GitHub; new vs. still-broken vs. recovered
- **Dashboard:** overview with filters, key × language matrix, key list incl. archive, per-URL history, run log, manual run ("Jetzt prüfen"), ignore list, test mail
- **Dead cron detection:** the dashboard raises an alarm if the nightly run has not happened for 26 h; optional heartbeat ping (e.g. healthchecks.io)
- **No dependencies:** plain PHP 8.1+ with SQLite – no Composer, no framework

## Requirements

PHP 8.1 or newer with the extensions `pdo_sqlite`, `curl`, `dom`, `mbstring` (all standard), a web server and cron.

## Installation

```bash
git clone https://github.com/laudatioUno/LinkWatcher.git
cd LinkWatcher

cp config/config.example.php config/config.php
chmod 600 config/config.php          # contains the SMTP password
php bin/hash-password.php            # → paste the hash into config.php (admin.password_hash)
```

1. Edit `config/config.php`: sources, mail settings, `app.base_url`.
2. Point the **document root** of the (sub)domain to the `public/` folder. Everything else (`config/`, `data/`, `src/`, `bin/`) must not be reachable from the web. The folders contain `.htaccess` files with `Require all denied` as a second line of defence for Apache.
3. The web server user needs write access to `data/` (SQLite database and lock file).
4. Test the mail settings: `php bin/test-mail.php` (or in the dashboard under *Einstellungen*).
5. Run once by hand: `php bin/run.php`
6. Set up the cron job (as the same user as the web server, so the database stays writable):

```cron
17 3 * * * php /path/to/LinkWatcher/bin/run.php --quiet
```

`bin/run.php` options: `--quiet` (no output), `--no-mail`. Exit codes: `0` OK, `1` problems found, `2` run failed, `3` already running.

## Configuration of the sources

```php
'sources' => [
    [
        'label'  => 'iOS',
        'format' => 'ios_strings',          // ios_strings | android_xml | auto
        'repo'   => 'owner/repository',
        'branch' => 'main',
        'files'  => [
            'de' => 'path/to/de.lproj/Localizable.strings',
            'fr' => 'path/to/fr.lproj/Localizable.strings',
            // …
        ],
    ],
    [
        'label'  => 'Android',
        'format' => 'android_xml',
        'repo'   => 'owner/repository',
        'branch' => 'main',
        'files'  => [
            'de' => 'path/to/values-de/strings.xml',
            // …
        ],
    ],
],
```

Files are downloaded from `raw.githubusercontent.com`; links in the mail and dashboard point to `github.com/…/blob/…#L<line>`. Instead of a path, a direct URL can be used: `'de' => ['url' => 'https://…']`.

Only keys whose value contains an `http(s)://` URL are tracked. `key_filter` (regex) narrows this further, e.g. `'/_link/'`.

Safety net: if a file suddenly contains no URL keys at all, or cannot be downloaded or parsed, nothing is archived and the problem is reported instead.

## Security

- The SMTP password lives in `config/config.php` outside the web root (`chmod 600`, ignored by git). Alternatively set it via the environment variable `LINKWATCHER_SMTP_PASSWORD`.
- Dashboard login with a password hash, session cookies (`HttpOnly`, `SameSite`, `Secure` on HTTPS), CSRF tokens on every form, login throttling (5 attempts / 15 min), strict Content-Security-Policy.
- Check history and runs older than `history_days` (default 90) are deleted automatically.

## Manual run from the dashboard

"Jetzt prüfen" starts a run immediately and redirects to the live log. With PHP-FPM or LiteSpeed the response is returned at once and the check continues in the background; with other setups the browser waits until the run is done (usually well under a minute). Tick "Mail" to also send the summary mail for a manual run. A lock file prevents overlapping runs (cron and manual).

## Project structure

```
bin/        CLI: run.php (cron), hash-password.php, test-mail.php
config/     config.example.php → config.php
data/       SQLite database, lock file (not in git)
public/     web root: index.php + assets
src/        application code (sync, checker, runner, mail, web UI)
```

## Versioning & releases

The version lives in the `VERSION` file (shown in the dashboard footer and the alert mail).

To publish a release, bump `VERSION` in your pull request (e.g. `1.0.0` → `1.1.0`). When the PR is merged to `main`, the GitHub Action `.github/workflows/release.yml` lints the PHP files, creates the tag `v<version>` and a GitHub release with generated release notes and a `.zip` / `.tar.gz` of the app attached. A version with a suffix (e.g. `1.1.0-beta.1`) is published as pre-release. If the tag already exists, nothing happens; the workflow can also be started by hand under *Actions → Release → Run workflow*.

To update an installation, replace the files with the new release – `config/config.php` and `data/` are not part of the archive and stay untouched. Database changes are migrated automatically on the next run.
