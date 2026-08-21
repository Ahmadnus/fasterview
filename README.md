# Faster — Vehicle Occupancy Tracker (Phase 1)

Pulls occupancy and page-view data from Power BI on every refresh, stores each
pull as a dated snapshot, and shows the history in a sortable table.

Phase 1 is **infrastructure only**: pull, store, display. No ranking logic and no
rank-change tracking yet — those sit on top of this later.

## Location

`D:\laravel\faster-occupancy-tracker` (the C: drive had no free space).

## Setup

```bash
php artisan migrate                              # create the tables
php artisan db:seed --class=UserSeeder           # create the two logins
php artisan serve
```

MySQL runs under XAMPP; start it from the XAMPP control panel before syncing.

## Commands

| Command | Purpose |
|---|---|
| `php artisan powerbi:list-tables` | Explore dataset tables. `--columns`, `--measures`, `--table=X`, `--json` |
| `php artisan powerbi:sync` | Refresh Power BI, wait, pull, store a snapshot |
| `php artisan powerbi:sync --skip-refresh` | Pull current data without triggering a refresh (~20s) |
| `php artisan powerbi:sync --dry-run` | Pull and summarise, write nothing |

## Where the data comes from

Two sources. **Rank and the car catalogue come from the live website API**;
Power BI supplies what the site does not carry.

### Live site — `https://www.fastercars.ae/api/cars`

| What | Field |
|---|---|
| **Rank** | `categories[].pivot.sort` — the ordering the site actually renders |
| Car list, title, slug | `id`, `title`, `slug` |
| Category | `categories[].title` |
| Price | `sale_price`, falling back to `price` |

Power BI's own `RankPublished API` and `Website API ID` tables hold the same
data but only move when the dataset refreshes, so they go stale. A comparison on
2026-08-22 found **825 of 1090 car/category ranks disagreeing with the live
site**, plus 68 ranks Power BI had left empty. They remain wired up as a
fallback (see below) but are no longer the source of truth.

### Power BI — everything the site does not expose

| What | Table | Key columns |
|---|---|---|
| Occupancy | `Car Occupancy` | `CarSerial`, `Occupancy %` (a fraction — ×100 on the way in) |
| Views | `ga4_PagesAndScreens` | `slug`, `screenPageViews`, `_DATA_DATE` (daily rows) |
| Plate | `car serial - model` | `Car serial`, `Plate` |
| Ownership | `Car link with accounts` | `CarSerial`, `Account Type` |
| **Bridge** | `Website link with OM` | `Site ID` ↔ `Matched CarSerial` ↔ `Slug` |

Occupancy is keyed by OM CarSerial; views, rank and category are keyed by
website ID/slug. The bridge is what ties the two worlds together.

## Design decisions

**Snapshots are append-only.** Every sync writes new `occupancy_snapshots` rows
with a fresh `snapshot_datetime`. Nothing is ever overwritten — the history is
the point.

**One snapshot row per car per category.** A car carries a different rank in
each category it appears in (the Audi R8 sits in Supercars, Luxury, Convertible
and Sports), so a single row per car would throw away the rank you actually
decide on. `car_serial` holds the website Site ID.

**Views are stored raw at day grain, never pre-aggregated.** `daily_views` holds
one row per car per day straight from GA4. Any "views over N days" figure is
summed on demand over the range chosen in the UI. This means changing the window
never requires a re-pull and never invalidates existing history. Rows are
upserted on `(car_serial, view_date)` because GA4 restates recent days.
`POWERBI_VIEWS_LOOKBACK_DAYS` only bounds how far back each sync re-pulls — it
is **not** an aggregation window.

**Rank comes from the live site, with a loud fallback.** If the website API
cannot be reached, the sync falls back to Power BI so the daily snapshot still
happens — and writes `LIVE WEBSITE API UNAVAILABLE … RANKS MAY BE STALE` into
`refresh_log.warnings`, which the page shows. Set `WEBSITE_API_FALLBACK=false`
to make the sync fail instead of storing stale ranks.

**Ownership is derived from the books.** A car with an `Asset` account in
`Car link with accounts` is one Faster owns (you only depreciate what you own);
one with only Income/Expences accounts is `lent`. Cars with no matched OM serial
stay NULL — "unknown" is never displayed as "lent".

**Price** uses `sale_price`, falling back to `price` — the sampled `price`
column reads 0 while `sale_price` holds the live rate.

## The page

`/occupancy` — one row per car per category, with **views sitting directly
beside occupancy**. Category and date-range filters, click-to-sort on every
column, and a car name search. Occupancy above 70% is green, below 30% red;
views are deliberately uncoloured because an absolute count is not comparable
across categories. `/occupancy/{serial}` charts occupancy and daily views
against each other — views rising while occupancy stays flat points at a
conversion problem rather than a demand problem.

"Pull Now" runs the fast path (no Power BI refresh). "Full Power BI refresh"
triggers a refresh and waits, which can take several minutes.

## Scheduling

`powerbi:sync` runs **daily at 12:30 Dubai time**, just after Power BI's own
refresh lands (~12:01 Dubai, per the `Last Update` table). The entry lives in
`routes/console.php` under a clearly marked block — the timing and the timezone
are two adjacent lines you can change.

The timezone is pinned with `->timezone('Asia/Dubai')` rather than left to
inherit the app timezone, which is UTC. Without that pin the time would drift by
four hours. Verify with `php artisan schedule:list`; it should read
`30 8 * * *` (08:30 UTC = 12:30 Dubai).

**This does not run until you register it with Windows.** Add one Task Scheduler
entry that fires every minute:

```
Program:   C:\xampp\php\php.exe
Arguments: artisan schedule:run
Start in:  D:\laravel\faster-occupancy-tracker
```

Laravel decides internally whether anything is actually due, so the every-minute
entry is correct and cheap. Tick **"Run task as soon as possible after a
scheduled start is missed"** so a sync still happens if the machine is asleep at
12:30. Output is appended to `storage/logs/powerbi-sync.log`, and every run is
recorded in the `refresh_log` table either way.

## Security

The Power BI client secret in `.env` was shared in plain-text chat and **must be
rotated** in the Azure App Registration. See the reminder at the top of
`.env.example`.
