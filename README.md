# JSD Enterprises

A Canadian investing site for following TSX stocks and ETFs, keeping a watchlist, and learning how brokerage and market data tools work. It runs on PHP and Apache, with MariaDB for member accounts and discussions. The dashboard, guides, tools, and guest watchlist run without a database.

## Run locally

Use PHP 8.2 or later with sessions enabled. Member features also require PDO MySQL (`php-mysql` on Debian and Ubuntu).

```sh
git clone https://github.com/jsdyer/jsd-enterprises.git
cd jsd-enterprises
php -S 127.0.0.1:8080 -t public
```

Open [localhost:8080](http://127.0.0.1:8080/). The dashboard starts with clearly labelled synthetic prices. Guest watchlists use the browser session. Member watchlists persist in MariaDB. You can also enter a symbol outside the chart catalogue, such as `WSP`. It appears on your list without a quote until you add it to the catalogue and provide prices.

## Enable accounts and discussions

Create the database and a limited application user in a MariaDB administrator session. Choose your own password before running these statements.

```sql
CREATE DATABASE jsd_enterprises CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'jsd_app'@'localhost' IDENTIFIED BY 'replace-with-a-unique-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON jsd_enterprises.* TO 'jsd_app'@'localhost';
```

Import the schema with an administrator account, then copy the configuration example.

```sh
sudo mariadb jsd_enterprises < database/schema.sql
cp config/local.example.php config/local.php
```

Set the database credentials in `config/local.php`, which Git ignores. You can also supply `JSD_DB_DSN`, `JSD_DB_USER`, and `JSD_DB_PASSWORD` to PHP. Enable `registration_enabled` to allow self-registration. Each member's watchlist is private to that account.

Set `community_enabled` to `true` to open the discussion board. Visitors can read it and signed-in members can start discussions, reply, and delete their own posts. The application stores watchlist symbols separately from discussion content.

## Enable the administration console

The console uses the existing member login and a separate administrator role. Apply the admin migrations to a database that already has `database/schema.sql`.

```sh
cat database/admin-core.sql database/admin-market.sql database/admin-guides.sql database/admin-community.sql |
    sudo mariadb jsd_enterprises
```

Create or sign in to the member account you want to promote. From the server checkout, grant it the role using the same database configuration as the site.

```sh
php bin/admin.php grant your-username
php bin/admin.php list
```

Set `admin_enabled` to `true` in `config/local.php`. After signing in, open **Administration** in the sidebar. Use `php bin/admin.php revoke your-username` to remove the role. Disabling the console closes the admin routes while keeping published guides, moderation decisions, and unexpired market notices in effect.

- **Market feed** checks catalogue coverage and labels missing, invalid, future-dated and stale files. `market_stale_days` is a calendar-day threshold. Download the diagnostic CSV to inspect metadata and close dates. Publish a visitor notice with an optional UTC expiry during data maintenance.
- **Guide desk** saves private drafts, previews them with the public guide layout, and publishes or restores revisions. Set a review-by date to flag content that needs a source check. Repository guides remain the starting point. Published edits and newly published guides are stored in MariaDB, so include the database in backups.
- **Moderation** can hide and restore discussion topics and replies with an internal reason. The console records operator changes in **Audit log**. Overview shows aggregate counts from the application database.

## Deploy with Apache

Use [deploy/apache.conf](deploy/apache.conf) as the virtual host template. Substitute your hostname, checkout path, and TLS certificate paths. Point Apache's `DocumentRoot` at the project's `public/` directory and configure Apache to execute PHP via your distribution's PHP-FPM integration or PHP module. Enable the virtual host once the certificate is available. Use HTTPS and set `secure_cookies` to `true` in production, especially if TLS terminates at a reverse proxy.

Keep configuration, database backups, and market data files outside `public/`. Give the PHP process read access to its local configuration and data files. When using a reverse proxy, configure Apache to trust client IP headers only from that proxy so that account rate limits use the correct client address.

## Edit the site

| What to change | Where |
| --- | --- |
| Tracked symbols, names, and sectors | [content/markets.php](content/markets.php) |
| Brokerage how-tos and personal finance articles | [content/guides.php](content/guides.php) |
| Credentials and feature switches | `config/local.php` |
| Styles and chart interactions | [public/assets/](public/assets/) |
| Market data adapter | [app/market.php](app/market.php) |

Guides are plain PHP arrays. Copy an entry and give it a unique slug, title, category, description, and sections. Each section can include paragraphs, steps, and a code block. Add source links in the guide's `sources` list.

## Market data and JSON API

The default feed is synthetic and available without an API key. The dashboard and the JSON endpoints use the same symbols and daily close history. The Market data API page links to sample responses.

- `GET /?page=api` returns a market snapshot.
- `GET /?page=api&symbol=RY&range=3M` returns a symbol's daily close history. Ranges are `1M`, `3M`, `6M`, and `1Y`.

To display actual prices, set `market_data_dir` in `config/local.php` or provide `JSD_MARKET_DATA_DIR` to PHP. Use an absolute directory path outside `public/` and populate it from a source whose licence covers your site. The app reads one file per catalogued symbol. Filenames are the PHP `rawurlencode()` form of the symbol plus `.json`, such as `RY.json` and `%5EGSPTSE.json`. Each file needs a provider name, matching currency, and at least two distinct dated closes. Supply a full year of closes to populate the longest range. A feed file uses this structure.

```json
{
  "source": "Licensed provider name",
  "currency": "CAD",
  "points": [
    {"date": "2026-10-28", "close": 123.45},
    {"date": "2026-10-29", "close": 124.50}
  ]
}
```

Write each updated file to a temporary name in the same directory, then rename it over the old file. Configuring a data directory switches the app to licensed data. Missing and invalid files appear as unavailable. Enable `publish_licensed_api` only if your licence also permits redistribution through a JSON API.

For feed and display rights, see [TMX pricing and contract documents](https://www.tmxinfoservices.com/market-data/pricing-and-contract-documents) and [Yahoo Canada's Terms of Service](https://legal.yahoo.com/ca/en/yahoo/terms/otos/index.html).
