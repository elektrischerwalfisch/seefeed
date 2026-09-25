# ICS test fixtures

Local ICS samples for `fetch/ics-parser.php` — no CalDAV, no `config.php`, no network. Useful to check RRULE expansion, horizons, and public `id`s.

## Run (from repo root)

```bash
php fetch/test-fixtures/run.php fetch/test-fixtures/single-event.ics
php fetch/test-fixtures/run.php --now=2026-09-22 fetch/test-fixtures/monthly-byday-3we.ics
```

With Docker (container name may differ):

```bash
docker exec seefeed-web php fetch/test-fixtures/run.php fetch/test-fixtures/single-event.ics
```

## Options

| Flag | Meaning |
| ---- | ------- |
| `--raw` | No RRULE expansion (parsed VEVENTs only) |
| `--internal` | Keep `_ics` after expansion |
| `--now=ISO` | Fixed “now” for horizon (reproducible runs) |
| `--past=N` | Override past horizon months |
| `--future=N` | Override future horizon months |

Output is JSON on stdout (`{"events":[…]}`).
