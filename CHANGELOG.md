# Changelog

All notable changes to this project are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-15

### Added

- Prompt from the post through an editable template with named variables,
  a clean-up pass, Unicode-safe length limits and a per-request override.
- `ImageProvider` adapter interface with a typed exception family, a
  configurable `generic-http` JSON adapter (one-shot or submit-then-poll)
  and an offline `fixture` adapter.
- Checks on the returned bytes: magic-number type, decode, pixel count,
  minimum size, aspect window, byte limit while downloading; optional
  centre crop and JPEG or WebP conversion.
- Pending suggestions in a protected directory with an authenticated
  preview route; Accept, Reject and Generate another; post lock warning on
  Accept; media modal picks recorded as Accept.
- Per-day and per-post caps reserved atomically before every call, kill
  switch (setting, constant, filter), provider pause on account errors,
  rate-limit and transient retry rules, crash sweep.
- One automatic attempt per post; save, publish and `ffp_request`
  triggers; `off`, `log` and `on` auto modes; backfill with `--spread`.
- Placeholder policy and placeholder detection by ID.
- Visible labels by caption or appended paragraph, without doubles.
- Retention and daily clean-up.
- Own queue table verified with `SHOW COLUMNS`; optional gen-queue,
  spend-meter and prompt-library integration.
- REST API, WP-CLI command, settings screen, block and classic editor
  panel, media library filter, Site Health test, personal data exporter
  and eraser, uninstall clean-up.
