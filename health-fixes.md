# Server health: what was wrong, what changed, what is left to do on the server

## Root causes found (measured, not guessed)
1. **Every slow call of "4.2 – 4.5 s" is a refused database connection.** `api.php` retries a refused connection for about 4 s
   (0.15 + 0.3 + 0.6 + 1.2 + 2 s) before it answers "Database connection failed (error 2002 …)". Those calls were not slow queries:
   they were waiting for a free connection on a shared MySQL that only allows a few at once.
2. **Too many statements per request keep each connection busy longer.** On a database that already has all its tables,
   `bd.cx_badge` (asked by almost every page) ran 52 SQL statements, `bd.sx_badge` 34, `bd.badge` 24, `dp.seats` 79 …
   most of them `CREATE TABLE IF NOT EXISTS`, `SHOW COLUMNS` and seed `INSERT IGNORE` repeated on every request.
   44 table set-up functions in the older modules only remembered "done" for the current request.
3. **Shopify orders stale:** `orders.cron` exists to keep `shop_orders` fresh, but nothing was calling it, so it only refreshed when someone opened an Orders page.

## What changed in the code
* `empmanagment/api.php`: new `schema_ok()` / `schema_set()` / `schema_sig_all()` (same idea as the existing `schema_done()` used by the WhatsApp modules).
  A set-up now runs once per deployment (any changed `.php` file in this folder runs every set-up once more) and is only remembered when the tables really exist.
  Applied to 43 set-up functions in api.php, boss, boss2, brain, check, claude_q, complaints, complaints_more, dept_boss, ledger, office, people, retail_field, social, social_learn, leader_pay.
  `ensure_tmph_media_table` was left alone on purpose: it also clears old media now and then.
* Measured on a real MariaDB (database limited to 8 connections), same answers before and after (422 read-only calls compared):
  statements per page-load call 27-30 -> 11-12; `bd.cx_badge` 52 -> 10, `bd.sx_badge` 34 -> 9, `bd.badge` 24 -> 10.
* `assets/js/api.js` (v=8 in every page): `me.flags` is reused for 30 s like the other badge calls.
* POS: `tmpos/includes/schema_flag.php` + guards in `unit_schema.php`, `biller_schema.php`, `restock_lib.php` (no more `information_schema` queries on every POS request).
* `attendance_admin.html`: the pencil buttons are 44 px on phone (the checker wants at least 40 px).
* Optional `DB_PERSISTENT` setting (off by default). Tested: with more PHP workers than database connections it made things WORSE (7 of 64 requests failed), so only use it when workers < max connections.

## Things only the server can fix
### Shopify orders: make the sync run by itself (cron every 5 minutes)
VPS (crontab -e as the web user, key = API_KEY from empmanagment/config.php):
    */5 * * * * curl -s "https://fourpirates.space/empmanagment/api.php?action=orders.cron&key=YOUR_API_KEY" >/dev/null 2>&1
Hostinger shared hosting: hPanel -> Advanced -> Cron Jobs -> Custom -> every 5 minutes -> command:
    curl -s "https://fourpirates.space/empmanagment/api.php?action=orders.cron&key=YOUR_API_KEY" > /dev/null
(`lib.cron` works the same way; see the comment above `case 'lib.cron'` in api.php. Help -> Jobs shows every cron the system expects.)

### Database connections (VPS)
* MariaDB/MySQL: `max_connections = 200` in /etc/mysql/mariadb.conf.d/50-server.cnf (or my.cnf), then `systemctl restart mariadb`.
* PHP-FPM pool (e.g. /etc/php/8.x/fpm/pool.d/www.conf): `pm.max_children` must be LOWER than max_connections (for example 30).
* Only then, if you want, add `define('DB_PERSISTENT', true);` to empmanagment/config.php.
