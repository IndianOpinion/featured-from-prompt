# Architecture

How featured-from-prompt 0.1.0 is built, and every decision taken while
building it from [PRD.md](PRD.md). Case numbers refer to PRD section 4.

## Modules

All classes live in `src/` under `IndianOpinion\FeaturedFromPrompt`.

| Module | Role |
|---|---|
| `Plugin` | Service container (storage, clock, provider registry, logger) and hook wiring. Activation, multisite install on first use, schema check. |
| `Settings`, `Secrets`, `Constants` | Defaults, bounds and sanitising; fresh reads past any object cache; secrets from constants or non-autoloaded options. |
| `Storage`, `WpdbStorage` | The queue table, the counters table, locks and uncached option reads. Everything that must be atomic or fresh. |
| `Queue` | The one entry point that queues requests (cases 5 to 11), the cron batch (80), gen-queue hand-off (81), backfill (83, 84), the post's last-decision record. |
| `Worker` | Runs one request: guards in PRD order, reservation, spend check, provider call, download, checks, store (4.4 to 4.7). Crash sweep (87). Provider test. The only caller of `ImageProvider::generate()`. |
| `Eligibility`, `Placeholders` | Cases 1 to 4 and 66 to 71. |
| `PromptBuilder`, `PromptPreview` | Cases 12 to 24. |
| `SizeChooser` | Case 36. |
| `Downloader`, `ImageChecker`, `CheckedImage`, `CheckFailed` | Cases 38 and 46 to 55. |
| `Suggestions`, `Paths`, `Guard` | Storing (56 to 59), review (60 to 65), the protected directory, the re-entry guard (85). |
| `Caps`, `Pauses`, `Integrations` | Cap reservation, provider pauses, gen-queue, spend-meter and prompt-library bridges. |
| `Triggers`, `Labels`, `Cleanup` | Save, publish and action triggers; visible labels; retention. |
| `Rest`, `Api`, `functions.php`, `global-functions.php` | REST routes, the PHP API and its function wrappers. |
| `Admin\SettingsPage`, `Admin\PostPanel`, `Admin\MediaLibrary` | Settings screen, post panel (both editors), media library filter and column. |
| `Cli\Command` | `wp featured-from-prompt`. |
| `Privacy`, `SiteHealth`, `Uninstall` | Personal data tools, Site Health test, clean removal. |
| `Provider\*` | The adapter interface, value objects, the exception family, `GenericHttpProvider` and `FixtureProvider`. |

`assets/panel.js` draws the same panel in the block editor (a document
settings panel) and in the classic editor (a meta box), from the status
route, with plain DOM calls and no build step.

## Data flow

1. A trigger calls `Queue::request()`. It checks the schema, eligibility,
   auto mode, the one-attempt marker and the duplicate guard, renders the
   prompt, and inserts a `queued` row. Nothing is spent. Manual requests
   call `spawn_cron()`. In `log` mode it runs the guards dry and records
   `would_request` instead of queuing.
2. The minute cron event (or a gen-queue job) runs `Queue::run_batch()`
   under a site lock, which sweeps lost workers and calls `Worker::run()`
   for up to the batch size within the time budget.
3. `Worker::run()` checks, in order: kill switch, provider, pause,
   eligibility, queue age, prompt, then takes the per-post lock, reserves
   the per-post and per-day slots, asks spend-meter, claims the row
   (`queued` to `running`, atomically), and calls the provider.
4. The result is downloaded if it is a URL, checked from its bytes,
   optionally cropped and converted, and stored as a pending attachment
   whose file is in the protected directory. The generation record is
   written in the same step; if it cannot be, the attachment is deleted.
5. The panel polls the status route and shows the suggestion through the
   authenticated preview route. Accept moves the file into uploads, builds
   the sizes, sets the featured image, supersedes the other pending
   suggestions and fires `ffp_accepted`.
6. Daily clean-up expires old pending suggestions, deletes old rejected,
   superseded and expired ones, purges old counters and request rows.

## Data

- Option `ffp_settings` (autoloaded): all settings.
- Options, not autoloaded: `ffp_secret_<provider>_<field>`,
  `ffp_pause_<provider>`, `ffp_recent` (ring of 200 summaries, prompts cut
  to 200 characters), `ffp_last_batch`, `ffp_template_warned`,
  `ffp_schema_version`, and `_ffp_lock_<name>` lock rows.
- Table `{prefix}ffp_requests`: the queue and request records (columns in
  `WpdbStorage::COLUMNS`). `trigger` is a reserved word in MySQL, so the
  column is `trigger_type`. A `runner` column says whether the own cron or
  gen-queue runs the row.
- Table `{prefix}ffp_counters`: `(scope, day, count)`, scope `day` or
  `post:<id>`.
- Post meta: `_ffp_opt_out`, `_ffp_last`, `_ffp_auto_marker`,
  `_ffp_suggestion`, `_ffp_day_count` (a display copy of the counter),
  `_ffp_placeholder_set`.
- Attachment meta: `_ffp_generated`, `_ffp_state`, `_ffp_state_time`,
  `_ffp_record`, `_ffp_history` (last 10).
- Files: pending suggestions in the protected directory; accepted ones in
  uploads.

## Decisions

### Rulings on the PRD's open questions

1. **Run mode: background only; the panel polls.** A synchronous mode would
   tie a paid call to a browser request bounded by the PHP time limit and
   bypass the batch lock. Manual requests call `spawn_cron()` so they start
   at once on a healthy site; Site Health warns when a backlog sits for 15
   minutes, and the README gives the cron line for `DISABLE_WP_CRON`.
2. **Queue storage: a custom table, verified with `SHOW COLUMNS`.** After
   every install `WpdbStorage::missing_columns()` reads the live columns of
   both tables and compares them with the expected list. While anything is
   missing, `request()` returns `schema_missing`, the worker refuses to
   run, the panel says so and an admin notice names the columns. `dbDelta`
   is never trusted on its own. The integration suite drops a column and a
   table and checks both are reported.
3. **No multipart in 0.1.0; submit-then-poll yes.** `generic-http` takes an
   optional job URL path. When set, it submits once, then polls the job URL
   (HTTPS, safe HTTP) every few seconds until a done or failed status, or
   until the request timeout. Polls are not billed; the submit is the
   attempt. The auth header goes to the job URL only when it is on the
   endpoint's own host. A failed job whose body carries the refusal marker
   is a refusal; a job that never finishes is a timeout.
4. **Pending suggestions live in a protected directory outside uploads
   until accepted.** The directory is `wp-content/ffp-private/` (per site on
   multisite), or `FFP_PRIVATE_DIR`, or the `ffp_private_dir` filter. It
   gets `.htaccess`, `web.config` and `index.php` deny files; the README
   gives the nginx rule. A suggestion is still an attachment post (so the
   whole review API works on attachment IDs), but its attached file is an
   absolute path in that directory, no attachment metadata is generated,
   and `wp_get_attachment_url` is filtered to the authenticated preview
   route. On Accept the file moves into uploads, `update_attached_file` and
   `wp_generate_attachment_metadata` run, and offload plugins see the file
   then. This replaces `media_handle_sideload` at store time (case 56): an
   offload plugin must never receive an unreviewed image. Deleting a
   suggestion deletes its private file through `delete_attachment`.
5. **No per-user caps; spend-meter's hooks when present.** The worker
   applies `spend_meter_pre_spend` with the intent in case 33 (the model is
   the provider id when none is set, because spend-meter requires one) and,
   for every allowed decision, fires exactly one of `spend_meter_record`
   with one call array (the call was sent; status `ok`, `error_billed` or
   `error_unbilled`) or `spend_meter_release` with the reservation id (it
   was not sent). Provider cost is not forwarded, because spend-meter takes
   nanos and providers report their own units. The site and post caps and
   the kill switch always apply first. The settings screen's provider test
   goes through the same gate.
6. **No embedded provenance in 0.1.0.** Generated images are marked in
   attachment meta at store time and exposed through `is_generated()` and
   the REST field. Writing IPTC digital source type or a content credential
   into the file is a roadmap item.
7. **A soft filter for prompt-library.** The plugin applies
   `promptlib_render` with `null`, the slug `featured-from-prompt` (filter
   `ffp_prompt_library_slug`), the template variables as values, version
   `live` and the consumer `featured-from-prompt`. An answer with a `user`
   string becomes the prompt (the `system` part, if any, goes first) and
   then gets the same clean-up, cut and `ffp_prompt` filter. `null` (plugin
   absent), `promptlib_not_found` or any other `WP_Error` (logged) keeps the
   own template, after our `ffp_prompt_template` filter.
8. **Accept warns when another editor holds the post lock and proceeds on
   confirmation.** `accept()` reads `_edit_lock` with the same 150 second
   window as `wp_check_post_lock()` (for the accepting user rather than the
   current one). Held by someone else: it returns `post_locked` with the
   holder's ID (REST 409, with their display name); the panel asks, then
   resends with `confirm_lock`. WP-CLI uses `--yes`. The history entry
   notes that the accept went over a lock.

### Companion contracts

Checked against the released `spend-meter`, `gen-queue` and
`prompt-library` 0.1.0 READMEs and PRDs:

- spend-meter: `spend_meter_pre_spend` (filter, `null` and an intent array),
  `spend_meter_record` (action, one call array), `spend_meter_release`
  (action, the reservation id). `SPEND_METER_API` is 1. `null` means absent.
- gen-queue: `gen_queue_enqueue_job` (filter, `null`, type, payload, args)
  returns a job id, `null` or a `WP_Error`. The handler is registered on
  `gen_queue_register_types` with `gen_queue_register_type()` and receives
  the `Job` (payload `{request_id, post_id}`) and its context. One attempt
  per job; the worker keeps its own retries. Detection is
  `GEN_QUEUE_VERSION`, overridable with `ffp_use_gen_queue`.
- prompt-library: `promptlib_render` as in ruling 7.

### Other decisions

- **The per-post lock is taken before the reservation.** A worker that
  cannot get the lock never touches a counter, so there is nothing to
  refund. The observable result (`locked`, queued, not counted) is the
  PRD's.
- **Counters live in their own table**, not in options and post meta,
  because one atomic `UPDATE ... WHERE count < cap` needs a row per scope
  and day. The per-post count is mirrored into post meta for display only.
  The per-post slot is reserved first, then the day slot; a failed day slot
  gives the post slot back. Counters older than 7 days are purged daily.
- **Final states.** `done` (suggestion stored), `failed` (the provider was
  reached, or a check failed after it), `skipped` (ended before reaching a
  provider: post gone, has image, caps, spend denied, prompt empty, no
  provider, queue age, configuration error), `cancelled`. The one-attempt
  marker is written for every automatic request that ends in a state other
  than `skipped`, as case 9 says.
- **`ConfigException` ends the request as `skipped` with `config_error`**
  and refunds, so a fixed configuration lets an automatic trigger try
  again.
- **`bad_response` is retried like a transient error** (case 42 lists it),
  except a result with neither bytes nor URL, which is final (case 44).
  `download_failed` is retried the same way and counts each time.
- **Rate limits do not use up the transient attempts.** Each 429 still
  counts against the caps, which bound them.
- **Retries leave gen-queue.** A request that gen-queue ran and that must
  wait (rate limit, transient) goes back to the own queue (`runner` becomes
  `own`), so one runner owns each retry.
- **Backfill ignores `auto_mode`.** It is an operator's explicit command.
  It still uses automatic requests, so it respects the one-attempt marker
  (unless `--force`), the caps and the kill switch.
- **The excerpt is cut to the summary word limit too**, so the outbound
  text never exceeds the setting.
- **The `ffp_prompt` filter's result is cleaned and cut again** to the
  provider's maximum, which is a hard limit.
- **The kill switch parks, it does not fail.** A queued row stays `queued`
  with reason `disabled` and is retried a minute later.
- **`day_cap` 0 parks requests** with `cap_day` and checks again hourly.
- **Provider tests count against the day cap and keep nothing.** The
  fixture test may run while the switch is off, because it calls nothing.
- **The placeholder policy never sets a generated image,** even if one is
  configured as the fallback; it logs and sets nothing.
- **Accepting clears the "placeholder set by the plugin" note** on the post.
- **Retention `-1` means keep forever** (the PRD's "or keep").
- **Media modal picks.** Pending suggestions are hidden from the picker by
  default. If an editor picks one anyway, the pick is treated as Accept: the
  file is published into uploads and the states change. A write of a pending
  suggestion to `_thumbnail_id` is refused before it happens (on
  `update_post_metadata` and `add_post_metadata`) unless the current user
  can edit that post and upload files and the post is the suggestion's
  parent. Code, cron and other posts cannot make a pending image featured.
- **Downloads follow redirects themselves.** WordPress's safe mode lets
  link-local addresses (cloud metadata) through and checks redirects the
  same way, so the plugin sets `redirection` to 0, follows up to 3 hops
  itself, and checks every hop for HTTPS and a public address, literal or
  resolved. The poll URL and the endpoint get the same check.
- **One deadline for submit-then-poll.** Polling stops at the request
  timeout counted from the submit, so the whole call stays inside the post
  lock and the lost-worker window.
- **Fail closed on the private directory.** Inside uploads, a `..` in the
  path, or deny files that cannot be written: nothing is stored
  (`store_failed`). Only names the plugin makes, directly in the directory,
  count as private, so nothing else is streamed by the preview route or
  deleted with an attachment. Accept re-sniffs the bytes against the
  extension and the attachment MIME type before the move into uploads.
- **REST nonces.** WordPress core checks the cookie nonce before any
  permission callback runs, so a bad REST nonce surfaces as core's own
  `rest_cookie_invalid_nonce`. Admin forms check their own nonces and
  return `bad_nonce`.
- **Extra REST routes:** `opt-out`, `clear-marker` and `preview`, which the
  panel needs. Preview streams the file through `rest_pre_serve_request`
  with `Cache-Control: private, no-store`.
- **The daylight-saving rule.** The site day comes from `wp_timezone()`;
  the next day starts at local midnight, so the day after a change can be
  23 or 25 hours long.
- **Multisite network activation** installs each site on its first `init`
  (schema version check), as the PRD's "on first use".
- **Uninstall** removes every option (secrets and pauses of every
  registered provider, plus any `ffp_secret_` and `ffp_pause_` rows), the
  tables, the private directory, plugin post meta, scheduled events, and
  every generated attachment that was never accepted and is not featured
  anywhere. Accepted images keep their attachment meta.
- **Fixture images** are bundled PNGs made with GD for this project; the
  non-image variant is an HTML error page larger than 1 KB, so the type
  check, not the size check, rejects it.

## Testing

- `tests/stubs/` is a small in-memory WordPress layer (posts, meta, options
  with a simulated persistent object cache, hooks, capabilities, HTTP,
  cron, a GD image editor). `tests/Support/MemoryStorage` implements
  `Storage` per site.
- `tests/Unit/` has one test per PRD case (method names start with
  `test_case_NN`), plus the cross-cutting tests from the end of section 12.
- `tests/Integration/WpdbStorageTest` runs `WpdbStorage` against a real
  MySQL or MariaDB when `FFP_TEST_DB` is set: schema verification, row
  round trips, the atomic claim, locks, and case 31 with forked processes
  racing for the last slot.
