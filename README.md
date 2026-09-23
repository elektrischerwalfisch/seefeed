# Seefeed

Load events from a Nextcloud calendar (and venues from a CardDAV address book) and render them on a website via AJAX. Core assets are CMS-agnostic; thin adapters wire them into a host site. A plain demo adapter is included; WordPress (and other hosts) can follow the same pattern.

## Quick start (plain adapter)

Open the repository root in a browser (redirects to `adapters/plain/`). The demo reads versioned JSON from `demo-data/`. Host integrations omit `eventsUrl` / `venuesUrl` to use the core defaults (`data/`), or set those URLs explicitly.

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

`fetch/config.php` is gitignored. Web calls without a valid `fetch_token` return 403. `config.php` is blocked via `.htaccess`. On success the script writes `data/events.json` and `data/venues.json` atomically (on failure, previous files are kept). Optional `write_schema => true` also writes `data/events.schema.json` (Schema.org JSON-LD).

Recurring events (`RRULE` with `FREQ=WEEKLY` or `MONTHLY`, plus `EXDATE` / `RECURRENCE-ID`) are expanded into concrete occurrences within a configurable horizon (defaults: 3 months past, 6 months future; keys `rrule_horizon_past_months` / `rrule_horizon_future_months` in config). The JSON stays a flat event list.
`data/` is runtime-only (gitignored except `.gitkeep`). `demo-data/` is versioned demo content and is never written by fetch.

Local Docker: make `data/` writable for the web server user (often `www-data`):

```bash
chmod 777 data
# after the first successful fetch:
chmod 666 data/events.json data/venues.json
```

## Layout

```text
seefeed/
  core/           # JS, default CSS, HTML templates
  fetch/          # Nextcloud → JSON; optional schema.php
  adapters/
    plain/        # Demo host page
    wordpress/    # placeholder for a future adapter
  demo-data/      # Versioned demo JSON (+ images)
  data/           # Runtime JSON (gitignored), optional schema + images
```

## Embed on a host site

1. Ship or submodule this repository into the host (e.g. `vendor/seefeed`).
2. Include `core/js/seefeed.js` and optionally `core/css/seefeed.css` (structure). For the demo look, also load `core/css/seefeed.theme.css`; host sites usually skin events themselves instead.
3. Place mounts such as `<section data-events data-filter="upcoming" data-template="teaser"></section>`.
4. Include the matching templates (`core/templates/event-*.html`) or host copies.
5. Set `window.Seefeed` before the script (JSON URLs, locale, counts, …) or rely on defaults pointing at `data/`.
6. Run `fetch/fetch.php` on a schedule so `data/` stays current.

See `adapters/plain/index.php` for a minimal working example.

## Templates

Core templates use class hooks filled by `core/js/seefeed.js`. Missing slots are skipped.

Date/time inside each `<time class="event-start|event-end">`:

| Selector           | Role                                                              |
| ------------------ | ----------------------------------------------------------------- |
| `.event-date`      | Full localized date (default in core templates)                   |
| `.event-time`      | Clock time (+ optional `timeSuffix`)                              |
| `.event-day`       | Optional day part only                                            |
| `.event-month`     | Optional month part only                                          |
| `.event-year`      | Optional year part only                                           |
| `.event-daymonth`  | Optional wrapper for day+month (markup/CSS only; not filled by JS) |

Parts use the same `dateLocale` / `dateDisplay` options as `.event-date`. Hosts that only need a single date string keep `.event-date` and omit the part slots.

Example (host template; replace or accompany `.event-date` as needed):

```html
<time class="event-start" datetime="">
  <span class="event-daymonth">
    <span class="event-day"></span>
    <span class="event-month"></span>
  </span>
  <span class="event-year"></span>
  <span class="event-time"></span>
</time>
```

## License / status

Work in progress. Adjust hosting paths and credentials per environment; never commit `fetch/config.php` or `.env`.
