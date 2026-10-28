# Faster — Vehicle Occupancy Tracker (Phases 1–2)

Pulls occupancy and page-view data from Power BI on every refresh, stores each
pull as a dated snapshot, and shows the history in a sortable table.

Phase 1 is **infrastructure only**: pull, store, display.
Phase 2 adds **automatic rank/price change detection** and a `/changes` page that
shows what happened to a car either side of a change, against its category.

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
| `php artisan changes:detect` | Compare the latest two snapshots and log changes (runs automatically in every sync) |
| `php artisan changes:detect --all --fresh` | Replay detection over all history. `--force`, `--dry-run` |
| `php artisan changes:backfill-ranks` | Import dated rank history from RankPublished API. `--dry-run`, `--fresh`, `--since=` |
| `php artisan ga4:inspect-clicks` | Explore what GA4 click events exist and whether they name a car |

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

## Phase 2 — change detection

`ChangeDetector` runs at the end of every `powerbi:sync`. It compares the
snapshot just written against the previous one and writes a `change_events` row
for every rank or price move. It reads only `occupancy_snapshots`, so it is safe
to replay and can never corrupt a pull.

**Grain differs by type, on purpose.** Rank is per car per category. Price is
per car with a NULL category — price repeats across a car's category rows, so
recording it per category would turn one Escalade price cut into four identical
events.

**Rejection is based on proven staleness, not volume.** If the sync recorded the
`RANKS IN THIS SNAPSHOT MAY BE STALE` warning (the live-API fallback fired), its
rank changes are discarded rather than written as real decisions. Volume alone is
deliberately *not* a criterion: a 2026-08-28 run with a healthy API moved 492 of
1075 ranks (46%) across an 8-day gap, and those were genuine. A high share only
prints a note. `--force` overrides.

Cars appearing or disappearing between snapshots are not logged — that is fleet
movement, not a change — but the count is surfaced as a warning.

### Where rank history comes from

Diffing our own snapshots can only date a change to *somewhere between two
syncs* — an 8-day gap dates it to within 8 days — and it reaches back no further
than the first snapshot. `RankPublished API` records every ranking publication
with its own `published_at`, `prev_rank` and `delta_vs_prev`: **5,597 usable
moves back to 2026-07-11**, dated to the minute.

`changes:backfill-ranks` imports them with `source = 'rank_api'`. Two wrinkles
it handles: the ranking surfaces were renamed partway through (`SUV Car` and
`Rent SUV` both appear, mapped via `SURFACE_ALIASES`), and the table also carries
internal surfaces that are not website categories at all (`flash_sale`,
`brand:7`) which are skipped and counted.

This matters because the before/after comparison needs changes whose after-window
has already closed. Without the backfill the page can only show *in window* until
a week of history accumulates.

Price is not backfilled — `RankPublished API` carries no price, so price changes
come only from snapshot diffing.

## Conversions, and the `daily_clicks` table that isn't

A 2026-08-29 scan of all 109 dataset tables found **no per-car click breakdown**.
`ga4_Events` carries `click`, `Call`, `select_item` and `popup_click`, but at one
row per event per day with no car identifier — site-wide only. There is no
WhatsApp event at all (`Customers[Whatsapp]` is a phone-number field).

The only click-shaped signal that resolves to a single car is `keyEvents` in
`ga4_PagesAndScreens` — the table daily views already come from. It is stored as
`daily_views.key_events`, at the same grain, costing one extra column in the
existing DAX rather than a second table and a second query.

It is **sparse**: over 2026-07-29..08-26, 213 of 358 cars recorded zero and only
7.3% of car-days were non-zero. The page therefore suppresses a growth percentage
when the before-window holds fewer than
`ChangeImpactAnalyser::MIN_CONVERSIONS_FOR_GROWTH` conversions — 0 → 1 is not a
100% improvement. The category average is what makes the metric usable, because
aggregating a category's cars clears the noise floor a single car cannot.

Rows synced before the column existed are NULL, not 0, so "not measured" never
reads as "no conversions".

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

`/changes` — every detected rank and price change, with views, conversions and
occupancy summed over the N days before and after it, beside **the average
movement of the other cars in the same category over the identical window**.
That control column is the point: a car rising while its whole category rises is
not evidence the change worked. Green means the car beat its category by
`ChangeImpactAnalyser::WIN_THRESHOLD`, red means it trailed by `LOSS_THRESHOLD`;
both are named constants, adjustable in one place.

Keeping views, conversions and occupancy as three separate columns is deliberate:
rising views with flat conversions points at price or photos, while rising
conversions with flat occupancy points at what happens after the enquiry.

**A window is "complete" when the data covers it, not when the calendar passes
it.** GA4 lands about two days behind, so a row whose after-window has elapsed on
the clock but not in the data shows *in window* rather than a fabricated collapse
to zero.

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
