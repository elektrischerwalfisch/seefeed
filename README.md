# Seefeed

Load events from a Nextcloud calendar (and venues from a CardDAV address book) and render them on a website via AJAX. Core assets are CMS-agnostic; thin adapters wire them into a host site. A plain demo adapter is included; WordPress (and other hosts) can follow the same pattern.

Fetch uses **CalDAV / CardDAV** (today: Nextcloud + app password). It does **not** integrate Google Calendar or Microsoft Graph; other CalDAV/CardDAV hosts (e.g. iCloud) and public calendar URLs are possible follow-ups, not current defaults.

## Quick start (plain adapter)

Open the repository root in a browser (redirects to `adapters/plain/`). The demo reads versioned JSON from `demo-data/`. Host integrations omit `eventsUrl` / `venuesUrl` to use the core defaults (`data/`), or set those URLs explicitly.

- List demo: `adapters/plain/index.php`
- Detail demo: `adapters/plain/event-detail.php?event=<id>` (e.g. `demo-evening-talk@seefeed.local`)

## Fetch data from Nextcloud

```bash
cp fetch/config.example.php fetch/config.php
# Fill in config.php: user, app password, CalDAV URL, CardDAV URL, fetch_token
php fetch/fetch.php
```

Shared-hosting cron (HTTP):

```bash
curl -fsS "https://www.example.com/path/to/seefeed/fetch/fetch.php?token=YOUR_FETCH_TOKEN"
```

`fetch/config.php` is gitignored. Web calls without a valid `fetch_token` return 403. `config.php` is blocked via `.htaccess`. On success the script writes `data/events.json` and `data/venues.json` atomically (on failure, previous files are kept). With `write_schema => true` (default in `config.example.php`) it also writes `data/events.schema.json` (Schema.org JSON-LD). That file is **not** injected into HTML by fetch — the host embeds it. The plain demo embeds a versioned fixture `demo-data/events.schema.json` on `adapters/plain/index.php` only (not on the detail page; a single-event JSON-LD there is optional later).

Recurring events (`RRULE` with `FREQ=WEEKLY` or `MONTHLY`, plus `EXDATE` / `RECURRENCE-ID`) are expanded into concrete occurrences within a configurable horizon (defaults: 3 months past, 6 months future; keys `rrule_horizon_past_months` / `rrule_horizon_future_months` in config). The JSON stays a flat event list. Each event gets a public **`id`**: ICS `UID` for singles, or `UID@start` for expanded RRULE occurrences (same `start` string as in JSON).
`data/` is runtime-only (gitignored except `.gitkeep`). `demo-data/` is versioned demo content and is never written by fetch.

Local Docker: make `data/` writable for the web server user (often `www-data`):

```bash
chmod 777 data
# after the first successful fetch:
chmod 666 data/events.json data/venues.json
```

### Local ICS fixtures (no CalDAV)

`fetch/ics-parser-fixture-test.php` parses versioned files under `fetch/fixtures/` via `ics-parser.php` (no `config.php`, no network). Useful to check RRULE expansion, horizons, and public `id`s:

```bash
php fetch/ics-parser-fixture-test.php fetch/fixtures/single-event.ics
php fetch/ics-parser-fixture-test.php --now=2026-09-22 fetch/fixtures/monthly-byday-3we.ics
```

Options: `--raw` (no expansion), `--internal` (keep `_ics`), `--now=ISO`, `--past=N`, `--future=N`. With Docker: `docker exec seefeed-web php fetch/ics-parser-fixture-test.php …` (container name may differ).

## Layout

```text
seefeed/
  core/           # JS, CSS, templates; fonts/ for self-hosted demo theme typefaces
  fetch/          # Nextcloud → JSON; schema.php; fixtures + ics-parser-fixture-test.php
  adapters/
    plain/        # Demo: list + detail (shared include-seefeed.php)
    wordpress/    # placeholder for a future adapter
  demo-data/      # Versioned demo JSON (+ images)
  data/           # Runtime JSON (gitignored), optional schema + images
```

## Embed on a host site

1. Ship or submodule this repository into the host (e.g. `vendor/seefeed`).
2. Include `core/js/seefeed.js` and optionally `core/css/seefeed.css` (structure). For the demo look, also load `core/css/seefeed.theme.css` (self-hosts Inter / Barlow Condensed from `core/fonts/`; no Google Fonts CDN); host sites usually skin events themselves instead.
3. Place list mounts such as `<section data-events data-filter="upcoming" data-template="teaser"></section>`. For a detail page, use `<section data-event-detail data-template="detail"></section>`.
4. Include the matching templates (`core/templates/event-*.html`) or host copies.
5. Set `window.Seefeed` before the script (JSON URLs, locale, counts, `eventDetailUrl`, …) or rely on defaults pointing at `data/`.
6. Run `fetch/fetch.php` on a schedule so `data/` stays current.

See `adapters/plain/index.php` (list) and `adapters/plain/event-detail.php` (detail) for a minimal example.

## Event detail view

Recommended host pattern: a **list page** with `[data-events]` and a **separate detail page** with `[data-event-detail]`. The host owns the detail page URL, `<title>`, meta description, and JSON-LD. Embed the upcoming **list** (`data/events.schema.json` after fetch) on the list/home page. The plain demo uses `demo-data/events.schema.json` on `adapters/plain/index.php`. Detail pages should not reuse that full list; an optional single-`Event` JSON-LD can follow later.

| Piece            | Convention                                                                  |
| ---------------- | --------------------------------------------------------------------------- |
| Event `id`       | From fetch: `UID`, or `UID@start` for RRULE occurrences                     |
| Detail URL       | Query `?event=<id>` (param name: `eventIdParam`, default `"event"`)         |
| List link        | Optional `<a class="event-permalink">`; filled when `eventDetailUrl` is set |
| Detail template  | `#event-detail` (`data-template="detail"`)                                  |
| Missing query    | Detail mount stays empty                                                    |
| Unknown `id`     | Detail mount shows „Event nicht gefunden“                                   |

Encode the id in the query (`encodeURIComponent`). Demo: list sets `eventDetailUrl: "./event-detail.php"`; open e.g. `event-detail.php?event=demo-evening-talk%40seefeed.local`.

## Templates

Core templates use class hooks filled by `core/js/seefeed.js`. Missing slots are skipped. Layout and slot markup live in `core/css/seefeed.css` and `core/templates/`; this section only notes behaviour that is not obvious from those files.

### Event card layout

Structure CSS (`.event`) uses a two-column grid: date column | content column. Direct children of `.event` (location, address, URL, …) each get a grid row. Wrap stacked body content in `.event-main` (see `event-teaser.html`) so children share one row — structure CSS sets `grid-area` on `.event-main > *`.

| Selector                                                                                                   | Role                                                                      |
| ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| `.event-dates`                                                                                             | Date column (`grid-area: dates`)                                          |
| `.event-title`                                                                                             | Title row                                                                 |
| `.event-permalink`                                                                                         | Optional link to detail page (`eventDetailUrl` + `?event=<id>`)           |
| `.event-categories`                                                                                        | Optional categories row                                                   |
| `.event-location` / `.event-more` / `.event-address` / `.event-url` / `.event-description` / `.event-image` | Content rows when used as **direct children** of `.event`                 |
| `.event-main`                                                                                              | Optional wrapper for stacked body content (markup only; not filled by JS) |
| `.event-more`                                                                                              | Optional `<details>` for “read more” (address, URL, description, image)   |

### Date and time slots

Filled inside each `<time class="event-start|event-end">`:

| Selector          | Role                                                               |
| ----------------- | ------------------------------------------------------------------ |
| `.event-date`     | Full localized date (e.g. teaser template)                         |
| `.event-time`     | Clock time (+ optional `timeSuffix`)                               |
| `.event-day`      | Optional day part only                                             |
| `.event-month`    | Optional month part only                                           |
| `.event-year`     | Optional year part only                                            |
| `.event-weekday`  | Optional weekday (`Mo` / `Montag`); needs `dateDisplay.weekday`    |
| `.event-daymonth` | Optional wrapper for day+month (markup/CSS only; not filled by JS) |

Day/month/year parts use the same `dateLocale` and day/month/year/`timeZone` from `dateDisplay` as `.event-date`. Set `dateDisplay.weekday` to `"narrow"` | `"short"` | `"long"` to fill `.event-weekday`; it is **not** added to `.event-date`. Hosts that only need a single date string keep `.event-date` and omit the part slots.

## License / status

Work in progress. Adjust hosting paths and credentials per environment; never commit `fetch/config.php` or `.env`.

