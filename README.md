# Seefeed

Load events from a Nextcloud calendar (and venues from a CardDAV address book) and render them on a website via AJAX. Core assets are CMS-agnostic; hosts wire them in (e.g. theme or submodule). A runnable **demo** lives under `demo/`.

Fetch is built and tested against **Nextcloud** (HTTP Basic + app password; calendar via CalDAV collection URL with Nextcloud’s `?export` ICS download; venues via CardDAV `addressbook-query`). It does **not** integrate Google Calendar or Microsoft Graph. Other CalDAV/CardDAV servers are a plausible follow-up, not a current guarantee.

## Quick start (demo)

Open the repository root in a browser (redirects to `demo/`). The demo reads versioned JSON from `demo/sample-data/`. Host integrations omit `eventsUrl` / `venuesUrl` to use the core defaults (`data/`), or set those URLs explicitly.

- List demo: `demo/index.php`
- Detail demo: `demo/event-detail.php?event=<id>` (e.g. `demo-evening-talk@seefeed.local`)

## Fetch data from Nextcloud

1. Copy the example config and fill in Nextcloud user, app password, calendar URL, address-book URL, and `fetch_token` (never commit `config.php` — it is gitignored):

```bash
cp fetch/config.example.php fetch/config.php
```

2. Run the fetch (CLI), or call it over HTTP with the token (e.g. cron on shared hosting):

```bash
php fetch/fetch.php
# or: curl -fsS "https://example.com/…/fetch/fetch.php?token=YOUR_FETCH_TOKEN"
```

Writes `data/events.json` and `data/venues.json` (atomic; on failure keeps previous files). Optional `data/events.schema.json` when `write_schema` is on — host embeds it in HTML; fetch does not. Web runs need a valid `fetch_token` (`.htaccess` blocks `config.php`).

`data/` is runtime-only (gitignored except `.gitkeep`). `demo/sample-data/` is versioned demo content and is never written by fetch.

## Layout

```text
seefeed/
  core/           # JS, CSS, templates; fonts/ for self-hosted demo theme typefaces
  fetch/          # Nextcloud → JSON; schema.php; see test-fixtures/README.md
  demo/           # Runnable demo: list + detail (shared include-seefeed.php)
    sample-data/  # Versioned demo JSON (+ images); never written by fetch
  data/           # Runtime JSON (gitignored), optional schema + images
```

## Embed on a host site

1. Ship or submodule this repository into the host (e.g. `vendor/seefeed`).
2. Include `core/js/seefeed.js` and optionally `core/css/seefeed.css` (structure). For the demo look, also load `core/css/seefeed.theme.css` (self-hosts Inter / Barlow Condensed from `core/fonts/`); host sites usually skin events themselves instead.
3. Place list mounts such as `<section data-events data-filter="upcoming" data-template="teaser"></section>`. For a detail page, use `<section data-event-detail data-template="detail"></section>`.
4. Include the matching templates (`core/templates/event-*.html`) or host copies.
5. Set `window.Seefeed` before the script (JSON URLs, locale, counts, `eventDetailUrl`, …) or rely on defaults pointing at `data/`.
6. Run `fetch/fetch.php` on a schedule so `data/` stays current.

See `demo/index.php` (list) and `demo/event-detail.php` (detail) for a minimal example.

## Event detail view

Recommended host pattern: a **list page** with `[data-events]` and a **separate detail page** with `[data-event-detail]`. The host owns the detail page URL, `<title>`, meta description, and JSON-LD. Embed the upcoming **list** (`data/events.schema.json` after fetch) on the list/home page. The demo uses `demo/sample-data/events.schema.json` on `demo/index.php`. Detail pages should not reuse that full list; an optional single-`Event` JSON-LD may follow later.

| Piece            | Convention                                                                  |
| ---------------- | --------------------------------------------------------------------------- |
| Event `id`       | From fetch: `UID`, or `UID@start` for RRULE occurrences                     |
| Detail URL       | Query `?event=<id>` (param name: `eventIdParam`, default `"event"`)         |
| List link        | Optional `<a class="event-permalink">`; filled when `eventDetailUrl` is set |
| Detail template  | `#event-detail` (`data-template="detail"`)                                  |
| Missing query    | Detail mount stays empty                                                    |
| Unknown `id`     | Detail mount shows "Event not found"                                        |

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

## License

MIT — see [`LICENSE`](LICENSE). Demo theme fonts under `core/fonts/` use their own OFL licenses (`core/fonts/*/OFL.txt`).
