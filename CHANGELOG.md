# Changelog

## Unreleased

- feat: Add optional day, month, and year slots for event date templates

## 0.1.0 - 2026-09-23
- chore: Release Seefeed 0.1.0
- docs: Document RRULE expansion and horizon config in the README
- feat: Apply EXDATE skips and RECURRENCE-ID overrides when expanding RRULE series
- feat: Expand WEEKLY and MONTHLY RRULE series within a configurable horizon
- refactor: Extract ICS parser with recurrence metadata and fixture test CLI
- fix: Ignore VALARM blocks when parsing VEVENT description and other fields
- fix: Parse ICS/vCard lines without splitting on colons inside quoted parameters
- refactor: Split Seefeed CSS into structure and optional demo theme stylesheets
