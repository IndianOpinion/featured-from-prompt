# featured-from-prompt: product requirements

Status: draft for build. Target release 0.1.0.

A WordPress plugin. For a post that needs a featured image, it builds a short
prompt from the post, sends it to an image generation API through a small
adapter interface, checks the image that comes back for size, shape and file
sanity, and stores it in the media library as a suggestion. An editor sees
the suggestion on the post screen and accepts it with one click, rejects it,
or asks for another. Nothing becomes a featured image without that click.
Every request counts against a per-day cap, and a kill switch stops all
requests at once. When generation fails, a documented placeholder policy
decides what the post shows.

Written from scratch; not derived from any third-party plugin.

## 1. Problem

Many posts have no picture of their own: opinion pieces, explainers, short
news items, notes, round-ups. Without a featured image they look broken in
archive grids, in share previews and in feed readers. The usual answers each
go wrong:

- A single site-wide placeholder makes every such post look the same. Once
  it is the featured image of many posts, every one of their share previews
  is the same branded slab.
- A placeholder is a real file of a perfectly good size and shape, so code
  that asks "is this featured image usable?" by size alone says yes, and the
  post is never offered anything better.
- Image generation called straight from a save hook spends money on every
  save, runs again on every retry, and sets pictures nobody has looked at.
- A generator returns another size than the one asked for, or a square when
  a wide picture was wanted, or an error page with a 200 status. Code that
  trusts the request instead of the file ends up with a broken hero.
- A generated picture that is not marked as generated at the moment it is
  stored cannot be labelled later, and other checks mistake it for a
  photograph from a source.
- Two concurrent runs each read "one request left" and both spend it.
- A fallback path somewhere else in the code calls the generator directly
  and skips the cap, the kill switch and the one-attempt rule.

featured-from-prompt does the one job properly: prompt, generate, check,
hold for review, and spend no more than the operator allowed.

## 2. Goals and non-goals

Goals:

1. Build a generic, editable prompt from the post (title, summary, category,
   tags) through a template with named variables.
2. Call any image API through one adapter interface. Ship a configurable
   generic HTTP JSON adapter and an offline fixture adapter for tests and
   demos.
3. Check the real returned file: type, decodable, pixel size, aspect ratio,
   byte size, pixel count. Optionally centre-crop to the target aspect.
4. Store each result as a pending suggestion attached to the post. Never set
   it as the featured image without an explicit editor action.
5. Mark every generated image as generated, with its prompt and provider, at
   the moment it is stored. Label it visibly when it is shown.
6. Enforce a per-day request cap, a per-post cap and a kill switch on every
   path, reserved atomically before the call.
7. Apply a placeholder policy when generation fails, and treat configured
   placeholders as "no image" everywhere.
8. Run generation in the background, never on a front-end request.
9. Integrate softly with `gen-queue` and `spend-meter` when they are active,
   and work fully without them.

Non-goals:

- Publishing anything. The plugin never changes a post's status and never
  sets a featured image on its own.
- Choosing a provider for the operator, or shipping a named vendor adapter.
  Adapters for specific APIs are registered by the operator or third parties
  through the interface in 5.3.
- Generating images inside post content, galleries or blocks other than the
  featured image.
- Editing images beyond one optional centre crop and one optional format
  conversion (4.7). No upscaling, no filters, no text overlays.
- Judging whether a picture suits the story. That is the editor's click.
- Writing provenance into the image file itself (IPTC, XMP, C2PA). The
  record lives in attachment meta. See open question 6.
- Prompt libraries, house styles or editorial rules. The default template is
  generic and the operator edits it.
- Image search, stock libraries, scraping, or any API other than the image
  API the operator configures.

## 3. Users and setting

- Installed by a site operator on a self-hosted WordPress 6.4 or later site,
  as a normal plugin or as a must-use plugin through a one-line loader stub
  documented in the README.
- Used by editors and authors on the post edit screen (block editor and
  classic editor), who generate, review, accept and reject suggestions.
- Configured by an administrator: provider, credentials, caps, prompt
  template, checks, placeholder policy, label.
- Called by other plugins through a documented action (for example an
  importer that wants a suggestion for posts without a picture).
- Runs in WP-Cron (or a real cron calling `wp cron event run`), through
  gen-queue when it is active, and on demand from WP-CLI.

## 4. Behaviour

Terms:

- Provider: an object implementing the adapter interface in 5.3.
- Request: one attempt to obtain one image for one post. It has an id, a
  prompt, a provider and a state.
- Suggestion: an attachment created from a successful request, attached to
  the post, in review state `pending`.
- Placeholder: any attachment ID listed in the placeholder setting, or one
  that the `ffp_is_placeholder` filter says is one.
- Site day: the calendar day in the site's timezone (the WordPress timezone
  setting), from 00:00:00 to 23:59:59.
- Billable attempt: a request whose HTTP call to the provider was sent,
  whatever the outcome.
- Auto request: a request started by a trigger other than an editor's click
  (4.3).

Cases are numbered once across the whole section. Section 12 has one test per
case with the same number.

### 4.1 Eligibility

1. A post is eligible when all hold: the plugin is enabled (case 25); its
   post type is in the enabled list (default `post`); it supports
   thumbnails; it is not in the trash and not an auto-draft; it is not
   password protected and not `private` unless the setting "include private
   and protected posts" is on (default off); the post meta opt-out flag is
   not set; and the `ffp_should_process` filter returns true.
2. For auto requests only, the post must also have no featured image, or a
   featured image that is a placeholder (cases 69 and 70). A manual
   request from an editor is allowed when the post already has a featured
   image; the suggestion then sits beside it and replaces it only on
   Accept.
3. A featured image ID that points to a missing attachment, or to an
   attachment that is not an image, counts as no featured image.
4. An ineligible post gets no request. The reason code is returned to the
   caller and shown in the panel: `disabled`, `post_type_not_enabled`,
   `no_thumbnail_support`, `post_gone`, `post_protected`, `post_opted_out`,
   `filtered_out`, `has_image` (auto only).

### 4.2 Triggers

5. Manual: the "Generate image" button on the post screen (5.8), or the REST
   route in 5.6. This is the default and only trigger enabled at install.
6. On save: when the setting "suggest on save" is on (default off), saving
   an eligible post with a non-empty title queues an auto request, unless
   the post already has a pending suggestion, a queued or running request,
   or an auto attempt marker (case 9). Autosaves and revisions never
   trigger.
7. On publish: when the setting "suggest on publish" is on (default off),
   the transition to `publish` queues an auto request under the same rules
   as case 6. The post is published as normal; the plugin never delays or
   blocks publishing.
8. From other code: `do_action('ffp_request', $post_id, $args)` queues an
   auto request under the same rules. `$args` may carry `prompt` (string),
   `source` (a short slug for the log, default `external`) and `priority`.
   The action never runs a request in the caller's process.
9. One auto attempt per post. When an auto request ends (any outcome other
   than `skipped` before reaching a provider), the post gets an auto attempt
   marker with the time and outcome. While the marker is present, no
   trigger in cases 6 to 8 or 83 queues another auto request for that post.
   Only a person (button, REST, WP-CLI with `--force`) can request again.
   Clearing the marker is an explicit button and CLI action.
10. Auto mode. The setting `auto_mode` is `off`, `log` or `on`. With `off`
    the triggers in cases 6 to 8 do nothing. With `log` they build the
    prompt, run every guard in 4.4 except the reservation, record what would
    have happened with reason `would_request`, and call no provider. With
    `on` they queue a request. Default `off`. Changing "suggest on save" or
    "suggest on publish" does not change `auto_mode`.
11. Duplicate guard. A post has at most one request in `queued` or `running`
    at a time. A second trigger returns the existing request id with reason
    `already_queued`. A manual request while a suggestion is pending is
    allowed (it is how "Generate another" works), subject to the per-post
    cap.

### 4.3 Building the prompt

12. The prompt is rendered from a template setting. Variables:
    `{title}`, `{summary}`, `{category}`, `{tags}`, `{post_type}`,
    `{style}`, `{site_language}`. Unknown variables are left as typed and
    logged once per template change.
13. `{title}`: the post title, HTML entities decoded, tags stripped.
14. `{summary}`: the manual excerpt if set; else the first N words of the
    content (default 60, setting 0 to 200). Before counting: shortcodes and
    blocks are rendered to text with `strip_shortcodes` and
    `wp_strip_all_tags`, then URLs, e-mail addresses, and runs of
    whitespace are removed. N = 0 leaves `{summary}` empty.
15. `{category}`: the primary category name when a primary category is
    known through the `ffp_primary_term` filter, else the first assigned
    category by term order, else empty. `Uncategorized` (the default
    category) counts as empty.
16. `{tags}`: up to 5 tag names (setting 0 to 15), comma separated, in term
    order.
17. `{style}`: the style setting, free text, default
    "clean editorial illustration, muted colours, wide composition".
18. Default template, generic by design:
    "An editorial illustration for an article titled \"{title}\". It is
    about: {summary}. Topic: {category}. Style: {style}. Do not include any
    text, letters, numbers, logos, watermarks, signatures or captions. Do
    not depict any real, identifiable person."
19. An optional negative prompt setting (default empty) is passed to
    providers that declare support for it, and ignored otherwise.
20. Clean-up after rendering: control characters removed, quotes
    normalised, runs of punctuation and whitespace collapsed, empty
    labelled fragments removed (for example "Topic: ." when `{category}` is
    empty).
21. Length: the prompt is cut to the smaller of the setting (default 1000
    characters) and the provider's declared maximum, at the last word
    boundary, counted in Unicode characters, never bytes. A cut prompt is
    recorded with `prompt_truncated`.
22. Empty: if the title is empty after clean-up and no prompt override is
    given, the request ends with `prompt_empty` before any guard is touched.
23. Override: an editor may edit the prompt in the panel before generating.
    The edited text replaces the rendered prompt for that request only. It
    goes through cases 20 and 21 and the `ffp_prompt` filter. Its length is
    capped the same way.
24. The `ffp_prompt` filter receives the final prompt, the post ID and the
    context `{template, variables, override (bool), negative_prompt}` and
    must return a string; anything else is ignored and logged.

### 4.4 Guards before a call

Guards run in this order in the worker, just before the provider call, and
again in the same order for a dry run. The first that fails ends the request.

25. Kill switch. The plugin is off when the setting "enabled" is off, or the
    constant `FFP_DISABLED` is defined and true, or the `ffp_enabled` filter
    returns false. Default: enabled is off until a provider is configured
    and saved. Off means: no provider call anywhere, queued requests stay
    queued (state unchanged), triggers record `disabled` and queue nothing,
    and the panel says so. Read fresh on every guard check, never cached
    for the length of a process.
26. Provider. A provider is selected and `is_configured()` is true. Else
    `provider_not_configured`. A selected provider ID with no registered
    adapter ends with `no_provider`.
27. Provider pause. After an account-level failure (case 39) the provider is
    paused until a time or until an administrator resumes it. While paused,
    requests stay queued with reason `provider_paused` and are not counted.
28. Post still eligible, re-checked in the worker (4.1). An auto request for
    a post that got a featured image since it was queued ends with
    `has_image`. A post deleted or trashed ends with `post_gone`.
29. Per-post cap. At most N billable attempts per post per site day
    (default 3, setting 1 to 20), manual and auto together. Over it:
    `cap_post`. The panel shows how many remain.
30. Per-day cap. At most N billable attempts per site per site day (default
    20, setting 0 to 10000). 0 means nothing runs (`cap_day`); there is no
    "unlimited" value. Over it: `cap_day`; queued requests stay queued
    until the next site day and are then processed in order, auto requests
    only if they are younger than the queue age limit (default 2 days, case
    82).
31. Atomic reservation. The per-day and per-post counters are reserved
    before the HTTP call by a single atomic database operation that
    increments only while below the cap. Two workers at cap minus one can
    never both pass. A reservation that fails reports the cap reason.
32. Refund. A reservation is given back only when no HTTP request was sent
    (for example the adapter threw before sending, or the lock was lost).
    Once the request is sent the attempt counts, whether it succeeded,
    failed, or timed out. This errs on the side of under-spending.
33. Spend check. When spend-meter is active, the worker calls
    `apply_filters('spend_meter_pre_spend', null, $intent)` with purpose
    `featured_image`, caller `featured-from-prompt`, the provider ID, the
    model, and `est_units` 1. A `null` result means no meter; the plugin's
    own caps still apply. A result whose `allowed` is not exactly `true`
    ends the request with `spend_denied` and the meter's reason code in the
    record, and the own reservation is refunded. The own cap and kill
    switch are never skipped because a meter allowed the call.
34. Per-post lock. A post-scoped lock (an atomic add with an expiry of the
    provider timeout plus 60 seconds) prevents two workers running the same
    post. A worker that cannot take it leaves the request queued with
    `locked` and does not count.

### 4.5 Calling the provider

35. The worker builds an image request: prompt, negative prompt, requested
    width and height, output format preference, count 1, a request id, and
    a timeout (default 90 seconds, setting 10 to 300).
36. Requested size: the provider declares supported sizes or aspect ratios
    (5.3). The plugin asks for the supported size whose aspect is closest
    to the target aspect (default 16:9) and, among those, the smallest that
    meets the minimum width and height. A provider that accepts any size
    gets the target width and height (default 1536 by 864). The choice is
    recorded.
37. Success: the adapter returns an image result: either bytes or a URL,
    plus optional MIME hint, width and height hints, revised prompt, model,
    units billed, provider cost, and provider request id.
38. A result URL is downloaded with the WordPress HTTP API in safe mode
    (`reject_unsafe_urls`), HTTPS only unless the provider declares the URL
    host as local, a byte limit (case 47), the same timeout, and at most
    3 redirects. A download failure ends with `download_failed` (retryable)
    and still counts.
39. Account-level errors: the adapter throws an authentication error (401,
    403, bad key) or a quota error (no balance, billing disabled). The
    request ends with `auth_failed` or `quota_exhausted`, and the provider
    is paused (case 27): until an administrator resumes it for auth errors,
    and for 6 hours (setting) for quota errors. An admin notice and the
    `ffp_provider_paused` action fire once per pause. Other queued requests
    are not each failed against the same account error.
40. Rate limit (429, or the provider's documented equivalent): the request
    returns to `queued` with a retry time from the provider's retry hint
    (seconds or HTTP date), capped at 1 hour, else 5 minutes. The attempt
    counts (it was sent).
41. Content policy refusal: the provider says the prompt was refused. The
    request ends with `prompt_refused`, final, with the provider's message
    stored (trimmed to 500 characters). An auto request does not retry. The
    panel shows the message so the editor can change the prompt and try
    again by hand.
42. Transient errors (timeout, connection reset, 5xx, malformed JSON): the
    request returns to `queued` with backoff 1, 5 and 15 minutes, up to 3
    attempts in total (setting 1 to 5), each counted. After the last it
    ends `failed` with the last reason (`timeout`, `transient`,
    `bad_response`).
43. A provider that returns more than one image: the first is used, the
    rest are discarded and not stored. Count is always requested as 1.
44. A provider that returns neither bytes nor a URL ends with
    `bad_response`.
45. The adapter never throws anything except the exception types in 5.3. Any
    other throwable is caught, logged with its class and message, and
    mapped to `transient`.

### 4.6 Checking the image

Every check uses the real bytes, never the request or the hints.

46. Type: the bytes are sniffed by magic number. Only PNG, JPEG and WebP
    are accepted. Anything else (HTML error page, JSON, GIF, SVG, AVIF in
    0.1.0) ends with `not_image`. The provider's MIME hint is recorded but
    never trusted.
47. Byte size: at most 15 MB by default (setting 1 to 50). Over it:
    `file_too_large`, checked while downloading so no more than the limit
    plus one chunk is read. Empty or under 1 KB: `not_image`.
48. Decode: `getimagesizefromstring` must return width and height above 0
    and a type that matches the sniffed type. Else `decode_failed`.
49. Pixel count: width times height at most 40 megapixels (setting). Over
    it: `too_many_pixels`, before any image editor loads the file.
50. Minimum size: width at least 1024 and height at least 576 by default
    (settings 100 to 8000). Failing: `too_small`. A provider that ignores
    the requested size and returns 512 by 512 fails here.
51. Aspect: width divided by height between 1.3 and 2.1 by default
    (settings 0.25 to 8.0, lower below upper). Outside it: `bad_aspect`,
    unless case 52 applies.
52. Optional crop. With "crop to target aspect" on (default off), an image
    outside the aspect window is centre-cropped with `WP_Image_Editor` to
    the target aspect, then cases 50 and 51 run again on the cropped result.
    If the crop makes it too small, the request ends with `too_small`. The
    record notes `cropped` with the original size.
53. Optional conversion. Setting `output_format` is `keep` (default),
    `jpeg` or `webp`, with a quality setting (default 82). Conversion uses
    `WP_Image_Editor`; if the editor cannot write the format, the original
    is kept and `convert_skipped` is recorded. Conversion never fails a
    request.
54. The `ffp_image_rules` filter receives `{min_width, min_height,
    min_ratio, max_ratio, max_bytes, max_pixels}` and the post ID, so a post
    type or category can use other rules.
55. A check failure is final for that request and counts as a billable
    attempt. It does not trigger an automatic retry: the provider will most
    likely return the same shape again. The panel offers "Generate another".

### 4.7 Storing the suggestion

56. A passing image is written to the uploads directory with a random,
    non-guessable file name (`ffp-` plus 16 random hex characters plus the
    real extension) and added with `media_handle_sideload`, so offload and
    media plugins see a normal upload. `post_parent` is the post.
57. In the same step, before the attachment is returned to any caller, the
    plugin writes the generation record on the attachment (section 6):
    generated flag, review state `pending`, prompt, negative prompt,
    provider ID, model, revised prompt, request id, requested and real
    size, created time, and the user who asked (0 for auto). If writing the
    record fails, the attachment is deleted and the request ends with
    `record_failed`. A generated image without its record must not exist.
58. Title: "Generated image for: " plus the post title. Alt text: the
    setting template, default "Illustration: {title}", plain text, at most
    250 characters. Caption: the label text when labelling uses captions
    (case 72). The `ffp_attachment_data` filter may change title, alt and
    caption before insert.
59. The post records the request outcome (section 6) and the suggestion's
    attachment ID. The post's featured image is not touched. The
    `ffp_suggestion_ready` action fires with post ID, attachment ID and the
    request record. The temporary file is removed on every path.

### 4.8 Review

60. The panel on the post screen lists pending suggestions for the post,
    newest first, each with a preview, the prompt used, the provider and
    model, the real size, and three buttons: Accept, Reject, Generate
    another. It also shows the current featured image and whether it is a
    placeholder.
61. Accept. Requires a valid nonce (or REST cookie nonce), the capability
    `edit_post` for that post, and `upload_files`. The attachment must
    exist, be a generated image of this plugin, be in state `pending`, and
    have this post as parent. Then the plugin sets it as the featured image
    with `set_post_thumbnail`, sets state `accepted` with the user and the
    time, sets every other pending suggestion of the post to `superseded`,
    and fires `ffp_accepted` with post ID, attachment ID, the previous
    featured image ID, and the user ID. Any failure leaves everything as it
    was and returns a reason (`not_pending`, `not_generated`,
    `wrong_post`, `forbidden`, `bad_nonce`, `post_gone`).
62. Accept over an existing image. If the post has a featured image that is
    not a placeholder, the panel asks for confirmation first. Accept then
    replaces it. The previous attachment is never deleted.
63. Reject. Same nonce and capability checks. State becomes `rejected` with
    the user, time and an optional short reason (at most 200 characters,
    plain text). The attachment stays until retention removes it (4.11).
    Fires `ffp_rejected`. Rejecting does not start a new request.
64. Generate another. Equivalent to a manual request (case 5) with the
    current prompt or an edited one. The earlier suggestions stay pending
    until accepted, rejected or superseded.
65. Changing the featured image elsewhere. If an editor sets a different
    featured image through WordPress itself while suggestions are pending,
    the suggestions stay pending. If the editor sets a pending suggestion as
    featured through the media modal instead of Accept, the plugin treats it
    as an Accept (state, supersede, action) on the
    `updated_post_meta`/`added_post_meta` hook for the thumbnail key, guarded
    against re-entry (case 85). In that path the plugin only records the
    editor's own choice; it never sets the featured image itself. Only a
    person can make a generated image featured: through Accept (panel, REST
    or WP-CLI) or through this deliberate pick in the media modal. No
    trigger, worker, backfill, placeholder policy or clean-up ever does.

### 4.9 Placeholder policy

66. When a request ends without a suggestion (any final failure, cap, spend
    denial, or queue age limit), the setting `on_failure` decides:
    - `none` (default): leave the post as it is. No featured image is set.
    - `placeholder`: if the post has no featured image, set the configured
      fallback placeholder attachment (if it exists and is an image) and
      record `placeholder_set`. Never replaces an existing image.
    The fallback is an operator-chosen, existing attachment, not generated
    content, so setting it is not a publication of new material.
67. Retryable ends (rate limit, transient, provider paused, locked, cap)
    are not failures yet. The placeholder is set only after the request is
    final (the last retry, or the queue age limit for capped requests), so
    a short outage does not leave posts on the placeholder.
68. The `ffp_placeholder_id` filter receives the configured ID and the post
    ID and may return another (per category, per post type) or 0 for none.
    A returned ID that is not an image attachment is ignored and logged.
69. Placeholders are known by ID, never by size. The placeholder setting is
    a list of attachment IDs (the fallback plus any others the site uses,
    for example one set by another plugin). The `ffp_is_placeholder` filter
    has the final say. The public function `is_placeholder()` (5.2) lets
    other code ask the same question.
70. A post whose featured image is a placeholder counts as having no image
    for eligibility (case 2) and in every count the plugin reports.
    Accepting a suggestion replaces the placeholder.
71. The `ffp_placeholder_set` action fires after the placeholder is set,
    with post ID, attachment ID and the request record.

### 4.10 Labelling

72. Every generated image carries a visible label when shown as a featured
    image, controlled by `label_mode`: `caption` (default: the label text is
    the attachment caption, which themes print under the featured image),
    `append` (the plugin appends a small paragraph after the thumbnail HTML
    on single views of the post through `post_thumbnail_html`), `both`, or
    `off`. Label text setting, default "AI-generated illustration".
73. No double label. With `append`, the plugin does not add the paragraph
    when the thumbnail HTML already contains the label text or the
    plugin's own label class. The `ffp_label_html` filter may change or
    remove it.
74. The label is added only on front-end single views of the post the image
    belongs to, and on the infinite-scroll or REST render of that post when
    the `ffp_label_context` filter says so. Never in admin, feeds or
    sitemaps (feeds keep the caption only).
75. The generated flag is readable through the public function
    `is_generated()` (5.2) and as REST field `ffp_generated` on media items,
    so themes, share-card and schema plugins can label or exclude it.

### 4.11 Retention and clean-up

76. Pending suggestions older than the pending limit (default 30 days,
    setting 1 to 365) become `expired`.
77. Rejected, superseded and expired suggestions are deleted (attachment
    and files, through `wp_delete_attachment` with force) after the
    retention period (default 14 days after the state change, setting 0 to
    365, or "keep forever"). An attachment that is the featured image of
    any post, or that the `ffp_keep_attachment` filter keeps, is never
    deleted, and its state is set back to `accepted` with a log line.
78. Accepted images are never deleted by the plugin.
79. Clean-up runs daily from WP-Cron in batches of 50 with a time budget of
    20 seconds per run, and from WP-CLI.

### 4.12 Queue and backfill

80. Without gen-queue: requests live in the plugin's own queue (section 6).
    A WP-Cron event every minute while work is queued, and `spawn_cron`
    after a manual request, runs a batch (default 3 requests, setting 1 to
    20) within a time budget (default 120 seconds). A site lock prevents
    two batches running at once; a batch that cannot take it exits with
    `locked` without touching rows.
81. With gen-queue active (constant `GEN_QUEUE_VERSION` defined), the plugin
    registers a job type `featured_from_prompt.generate` and enqueues
    through the `gen_queue_enqueue_job` filter with idempotency key
    `ffp:<post_id>:<request_id>`. If the filter returns `null` or a
    `WP_Error`, the plugin falls back to its own queue and logs it. Its own
    guards (4.4) still run inside the job handler.
82. Queue age limit. Auto requests still queued after the age limit
    (default 2 days) end with `expired_in_queue`. Manual requests do not
    expire in the queue but can be cancelled from the panel.
83. WP-CLI `backfill` (5.7) queues auto requests for eligible posts without
    a featured image (or with a placeholder), with filters for post type,
    status, date range and a limit, newest first. It respects the auto
    attempt marker unless `--force`. `--dry-run` lists posts and the reason
    each would be skipped, and shows how many requests today's cap allows.
84. Backfill never queues more requests than the per-day cap has left today
    unless `--spread` is given, which queues them all and lets the cap pace
    them day by day.

### 4.13 Robustness

85. Re-entry guard. Setting or changing the featured image fires hooks that
    could start another trigger or a second Accept. A per-request,
    in-process guard stops the plugin reacting to its own writes.
86. Idempotent worker. Running the same request twice (a cron overlap, a
    retried job) does nothing the second time: a request in a final state
    is skipped with `already_done`.
87. Crash safety. A request is marked `running` with a heartbeat time
    before the HTTP call. A request found `running` with a heartbeat older
    than the timeout plus 5 minutes is marked `failed` with
    `worker_lost`; it is not retried automatically, because the call may
    have been billed. It counts.
88. Object cache. Kill switch, pause state and counters are read from the
    database, not a persistent object cache, at guard time.
89. Multisite. Every setting, counter and queue is per site. Network
    activation sets defaults on each site on first use. A network-wide cap
    is out of scope for 0.1.0 (spend-meter can provide one).

### 4.14 Failure modes summary

| Failure | Behaviour | Why |
|---|---|---|
| Kill switch off | Nothing called; queue kept unchanged | Instant and lossless stop |
| Cap reached (day or post) | Request waits (day) or ends (post); no call | Spend never exceeds the cap |
| Spend meter denies | Request ends `spend_denied`; own reservation refunded | Meter is a second gate, never a bypass |
| Auth or quota error | Provider paused; other requests wait | One account fault must not burn every request |
| Rate limit | Re-queued with the provider's hint | Transient |
| Timeout, 5xx, bad JSON | Up to 3 attempts with backoff, then `failed` | Transient, but each try costs |
| Prompt refused | Final; message shown to the editor | Retrying the same prompt will not help |
| Wrong type, too small, wrong shape | Final for the request; "Generate another" offered | Same prompt likely gives the same shape |
| Record write fails | Attachment deleted; `record_failed` | Never keep an unmarked generated image (fail closed) |
| Worker dies mid-call | `worker_lost`, counted, not retried | The call may have been billed |
| Final failure | `on_failure` policy, default leave as is | A shared placeholder harms every share preview |
| Editor never reviews | Suggestion expires and is cleaned up | Nothing is published without a click |

## 5. Public interface

### 5.1 Plugin

- Slug and text domain `featured-from-prompt`. Main file
  `featured-from-prompt.php`. Namespace `IndianOpinion\FeaturedFromPrompt`.
  Hook, option and meta prefix `ffp_`. Constant `FFP_VERSION`.
- Must-use loader: a one-file stub that `require`s the main file,
  documented in the README.
- Requires PHP 8.1 and WordPress 6.4. No runtime Composer dependency. Uses
  the WordPress HTTP API and `WP_Image_Editor`; no SDKs.

### 5.2 PHP API (namespace functions, with global `ffp_*` wrappers)

- `request(int $post_id, array $args = []): RequestResult` queues a
  request. `$args`: `prompt` (string override), `manual` (bool, default
  true when called by a user), `source` (string), `priority` (int), `force`
  (bool: ignore the auto attempt marker; manual only). Returns the request
  id or a reason code.
- `run_request(string $request_id, array $args = []): RequestRecord` runs
  one queued request now (WP-CLI and tests). `dry_run` (bool): build the
  prompt and run the guards without reserving or calling.
- `preview_prompt(int $post_id, ?string $override = null): PromptPreview`
  returns the rendered prompt, the variables, whether it was cut, and the
  provider's maximum.
- `accept(int $attachment_id, int $user_id): AcceptResult` and
  `reject(int $attachment_id, int $user_id, string $reason = ''):
  RejectResult`, with the checks in cases 61 and 63 (the capability check
  uses `$user_id`).
- `status(int $post_id): array` returns the post record (section 6) plus
  pending suggestions and the caps remaining.
- `is_generated(int $attachment_id): bool`.
- `is_placeholder(int $attachment_id): bool`.
- `register_provider(ImageProvider $provider): void`, normally called from
  the `ffp_register_providers` action.

### 5.3 Provider adapter interface

`interface ImageProvider`:

- `id(): string` stable slug, `^[a-z0-9][a-z0-9_-]{0,39}$`.
- `label(): string` for the settings screen.
- `capabilities(): ProviderCapabilities` value object: `sizes` (list of
  `[width, height]`, or empty for any size within `min_side` and
  `max_side`), `aspect_ratios` (list of strings such as `16:9`, used when
  the API takes a ratio instead of a size), `max_prompt_chars` (int),
  `negative_prompt` (bool), `formats` (list of `png`, `jpeg`, `webp`),
  `returns` (`bytes`, `url` or both), `local_hosts` (list, for case 38).
- `settings_fields(): array` the fields the adapter needs (key, label,
  type `text`, `secret`, `url`, `select`, `number`; required flag; help).
  Secret fields are stored as in 8.
- `is_configured(array $settings): bool`.
- `generate(ImageRequest $request, array $settings): ImageResult`.

`ImageRequest`: `prompt`, `negative_prompt`, `width`, `height`,
`aspect_ratio`, `format`, `timeout`, `request_id`, `post_id`.

`ImageResult`: `bytes` (?string), `url` (?string), `mime_hint`,
`width_hint`, `height_hint`, `revised_prompt`, `model`, `units` (float,
default 1), `provider_cost` (?int, in the provider's smallest currency unit
as reported, stored as given), `provider_request_id`.

Exceptions (namespace, all extending `ProviderException`):
`AuthException`, `QuotaException`, `RateLimitException` (with
`retryAfter(): ?int` seconds), `PromptRefusedException` (with the
provider's message), `TransientException`, `BadResponseException`,
`ConfigException` (thrown before any HTTP call; triggers the refund in
case 32). Adapters must call the provider only through
`wp_remote_request` (or `wp_safe_remote_request`), so the plugin can apply
`ffp_http_args`, timeouts and test doubles.

Shipped adapters:

1. `generic-http`: a configurable JSON adapter. Settings: endpoint URL
   (HTTPS), HTTP method (POST), auth header name and secret value, extra
   headers (non-secret), a request body template in JSON with placeholders
   `{prompt}`, `{negative_prompt}`, `{width}`, `{height}`,
   `{aspect_ratio}`, `{format}` (each JSON-escaped when substituted), a
   response path to base64 image data, or to an image URL (dot path such as
   `data.0.b64`), optional paths for revised prompt and model, a list of
   supported sizes, and the maximum prompt length. HTTP 401 and 403 map to
   `AuthException`, 402 to `QuotaException`, 429 to `RateLimitException`
   (reading `Retry-After`), 400 with a configurable refusal marker in the
   body to `PromptRefusedException`, other 4xx to `BadResponseException`,
   5xx and network errors to `TransientException`.
2. `fixture`: offline, no network, no cost. Returns a bundled PNG chosen by
   setting (a valid 1536 by 864 image, a square 1024 by 1024, a tiny
   512 by 512, and a non-image) so every check can be exercised on a demo
   site. Labelled "Test fixture (no API calls)" and never billed, but still
   counted against the caps so the caps can be tested.

### 5.4 Filters

| Filter | Arguments | Use |
|---|---|---|
| `ffp_enabled` | bool | Kill switch from code |
| `ffp_should_process` | bool, post ID, context | Skip posts by any rule |
| `ffp_prompt_variables` | array, post ID | Add or change template variables |
| `ffp_primary_term` | ?WP_Term, post ID | Supply the primary category |
| `ffp_prompt` | string, post ID, context | Final prompt |
| `ffp_image_rules` | rules, post ID | Per-post size and aspect |
| `ffp_http_args` | args, URL, purpose (`generate`, `download`) | HTTP args; cannot turn off safe mode for downloads |
| `ffp_attachment_data` | array, post ID, record | Title, alt, caption before insert |
| `ffp_placeholder_id` | ID, post ID | Per-post fallback placeholder |
| `ffp_is_placeholder` | bool, attachment ID | Final say on placeholders |
| `ffp_label_html` | HTML, attachment ID, post ID | Change or remove the label |
| `ffp_label_context` | bool, post ID | Where the appended label shows |
| `ffp_keep_attachment` | bool, attachment ID | Protect from clean-up |
| `ffp_capability_generate` | string | Default `edit_others_posts` |
| `ffp_capability_settings` | string | Default `manage_options` |
| `ffp_day_cap` / `ffp_post_cap` | int | Override caps from code (only lower, never above the setting) |

### 5.5 Actions

`ffp_register_providers` (registry), `ffp_request` (post ID, args: queue an
auto request), `ffp_request_queued` (record), `ffp_request_finished`
(record), `ffp_suggestion_ready` (post ID, attachment ID, record),
`ffp_accepted` (post ID, attachment ID, previous ID, user ID),
`ffp_rejected` (post ID, attachment ID, user ID, reason),
`ffp_placeholder_set` (post ID, attachment ID, record),
`ffp_provider_paused` (provider ID, reason, until),
`ffp_cap_reached` (scope `day` or `post`, post ID or 0, day).

`ffp_accepted` is the signal for share-card renderers and page caches to
refresh anything derived from the previous featured image.

### 5.6 REST API

Namespace `featured-from-prompt/v1`. Every route has a permission
callback; cookie auth needs the REST nonce.

- `GET /posts/{id}/status`: post record, pending suggestions, caps left.
  Needs `edit_post`.
- `GET /posts/{id}/prompt`: prompt preview. Needs `edit_post`.
- `POST /posts/{id}/generate` body `{prompt?}`: queue a manual request.
  Needs `edit_post`, `upload_files` and the generate capability.
- `POST /suggestions/{attachment_id}/accept` and `/reject` body
  `{reason?}`. Needs `edit_post` on the parent and `upload_files`.
- `POST /posts/{id}/cancel`: cancel a queued request. Needs `edit_post`.
- Media items gain read-only fields `ffp_generated` and `ffp_review_state`.

Errors use `WP_Error` with the reason codes as the error code and HTTP 400,
403, 404, 409 (state conflict) or 429 (cap).

### 5.7 WP-CLI

Command `wp featured-from-prompt`:

- `generate <id>... [--prompt=<text>] [--now] [--force] [--dry-run]`
- `backfill [--post_type=<t>] [--status=<s>] [--after=<date>]
  [--before=<date>] [--limit=<n>] [--spread] [--force] [--dry-run]`
- `prompt <id>` prints the rendered prompt and variables.
- `status <id> [--format=table|json]`
- `accept <attachment_id> --user=<id>` and `reject <attachment_id>
  --user=<id> [--reason=<text>]` (the user must have the capabilities).
- `queue list|run|cancel <request_id>`
- `provider list|test|pause|resume <id>` (`test` sends one real request
  and counts it, after a confirmation unless `--yes`).
- `caps` prints today's counts and limits.
- `cleanup [--dry-run]`
- `clear-marker <id>...`

Exit codes: 0 on success or dry run, 1 when any request ends in a failure
reason, 2 on bad arguments, 3 when the kill switch is off.

### 5.8 Admin

- Settings page under Settings, capability per the filter, nonce on save,
  every field validated and bounded (section 7). Secret fields show only
  "set" or "not set" and a replace control. A "Test provider" button
  (sends one counted request after confirmation) and a "Test with fixture"
  button.
- Post panel: a block editor sidebar panel and a classic editor meta box
  with the same content: prompt preview with edit box, Generate button,
  pending suggestions (case 60), request state in plain words, caps left,
  opt-out checkbox, clear-marker button. The panel polls the status route
  every 5 seconds while a request is queued or running, for at most 5
  minutes.
- A "Recent requests" table on the settings page: last 200 requests with
  post, trigger, outcome, reason, provider, size and time.
- Media library: a filter "Generated, pending review" and a column for
  review state. Pending suggestions are excluded from the media modal used
  to pick images for other posts (setting, default on).

## 6. Data

Options (one autoloaded settings array `ffp_settings`, plus non-autoloaded
entries for secrets, provider pauses and counters):

- Settings: section 7.
- Provider secrets: per provider and field, not autoloaded, see 8.
- Provider pause: provider ID, reason, until time, set time.
- Counters: per site day, `{day, count}` for the site, and per post per day
  in post meta (below). Day counters older than 7 days are removed by the
  daily clean-up.
- Recent requests: a ring of the last 200 request summaries (no prompt
  text beyond 200 characters), not autoloaded.

Own queue (when gen-queue is not used): a custom table
`{prefix}ffp_requests` created on activation and upgraded with a schema
version option. Columns: id (UUID), post ID, state (`queued`, `running`,
`done`, `failed`, `skipped`, `cancelled`), trigger (`manual`, `save`,
`publish`, `external`, `backfill`, `cli`), user ID, prompt, negative
prompt, provider ID, priority, attempts, next run time, heartbeat, reason,
attachment ID, created and updated times, a JSON record. Indexes on
(state, next run time) and (post ID). After any schema change the builder
verifies the columns exist (open question 2); `dbDelta` alone is not
trusted.

Post meta:

- Opt-out flag.
- Last request record: request id, trigger, outcome, reason, attachment
  ID, time.
- Auto attempt marker: time and outcome.
- Per-day attempt count: `{day, count}`.
- Placeholder set by the plugin: attachment ID and time (so the plugin knows
  it set it).

Attachment meta on every generated image: generated flag; review state
(`pending`, `accepted`, `rejected`, `superseded`, `expired`); state
history (time, user, state, reason; last 10 entries); prompt; negative
prompt; revised prompt; provider ID; model; request id; provider request id;
requested size; real size; cropped and converted flags; created time;
requesting user.

Retention: section 4.11. Request rows in a final state are removed after
90 days (setting).

Uninstall (`uninstall.php`): removes options, secrets, counters, scheduled
events, the queue table, and plugin post meta on posts. Pending, rejected,
superseded and expired generated attachments that are not the featured
image of any post are deleted. Accepted generated images and their
attachment meta are kept, because that meta is the record that they were
generated and drives their label in any theme that reads it. A setting
"keep everything on uninstall" (default off) skips all of this except the
scheduled events.

## 7. Configuration and sensible defaults

| Setting | Default | Range or values |
|---|---|---|
| Enabled | off until a provider is saved | on, off |
| Provider | none | registered provider IDs |
| Post types | `post` | public types supporting thumbnails |
| Include private and protected posts | off | on, off |
| Auto mode | `off` | `off`, `log`, `on` |
| Suggest on save | off | on, off |
| Suggest on publish | off | on, off |
| Prompt template | case 18 | 20 to 4000 characters |
| Style | case 17 | up to 300 characters |
| Negative prompt | empty | up to 1000 characters |
| Summary words | 60 | 0 to 200 |
| Tags in prompt | 5 | 0 to 15 |
| Prompt length limit | 1000 | 100 to 4000 characters |
| Target aspect | 16:9 | `W:H`, ratio 0.25 to 8.0 |
| Target size (any-size providers) | 1536 by 864 | 256 to 4096 per side |
| Minimum width and height | 1024 and 576 | 100 to 8000 |
| Aspect window | 1.3 to 2.1 | 0.25 to 8.0, lower below upper |
| Crop to target aspect | off | on, off |
| Output format, quality | `keep`, 82 | `keep`, `jpeg`, `webp`; 40 to 100 |
| Maximum file size | 15 MB | 1 to 50 MB |
| Maximum pixels | 40 MP | 1 to 100 MP |
| Timeout | 90 s | 10 to 300 s |
| Attempts on transient errors | 3 | 1 to 5 |
| Quota pause | 6 h | 15 min to 7 days |
| Per-day cap | 20 | 0 to 10000 (0 means none run) |
| Per-post cap per day | 3 | 1 to 20 |
| Batch size, time budget | 3, 120 s | 1 to 20, 30 to 600 s |
| Queue age limit (auto) | 2 days | 1 hour to 30 days |
| On failure | `none` | `none`, `placeholder` |
| Fallback placeholder | none | an image attachment ID |
| Placeholder IDs | the fallback | list of image attachment IDs |
| Label mode, text | `caption`, "AI-generated illustration" | `caption`, `append`, `both`, `off`; up to 100 characters |
| Alt text template | "Illustration: {title}" | up to 250 characters |
| Pending limit | 30 days | 1 to 365 |
| Retention after reject, supersede, expire | 14 days | 0 to 365, or keep |
| Request row retention | 90 days | 7 to 3650 |
| Hide pending from media modal | on | on, off |
| Keep everything on uninstall | off | on, off |

Constants (override settings, shown read-only on the screen):
`FFP_DISABLED` (bool), `FFP_DAY_CAP` (int, can only lower the setting),
`FFP_PROVIDER` (ID), and per-field secrets as
`FFP_SECRET_<PROVIDER>_<FIELD>` in upper case, which take precedence over
stored secrets.

A changed setting takes effect on the next guard check; queued requests
keep their prompt but use the current provider, caps and checks.

## 8. Security and privacy

- Capabilities: generate needs the generate capability (default
  `edit_others_posts`, because it spends money) plus `edit_post` on the
  post and `upload_files`; accept and reject need `edit_post` on the
  parent and `upload_files`; settings need `manage_options`. Every admin
  form has a nonce; every REST route a permission callback.
- Secrets: read from constants first. When stored, kept in non-autoloaded
  options, never printed back to any screen, never put in logs, request
  records, REST responses or exceptions (adapters redact header values in
  error messages). The settings export, if any, omits them.
- Outbound data: only the prompt (and negative prompt) and the size go to
  the provider. Post content is reduced to the summary words; URLs and
  e-mail addresses are removed (case 14). Private and protected posts are
  excluded by default. The README states plainly that prompts are sent to
  the third-party API the operator configures and are subject to its terms
  and retention.
- No real people by default: the default template asks for no identifiable
  person. The README warns operators that changing this carries legal and
  ethical risk.
- Downloads: safe HTTP only (no private, loopback or link-local addresses),
  HTTPS, byte limit, redirect limit, type sniffed from bytes, decoded and
  measured before any image editor touches it, pixel limit against
  decompression bombs.
- The endpoint URL of `generic-http` must be HTTPS and must not resolve to
  a private address unless the constant `FFP_ALLOW_LOCAL_ENDPOINT` is true
  (for self-hosted models on the same network).
- Files: random names so pending suggestions are not guessable. The README
  notes that uploads are public by URL once written, as with any upload.
- Output: every value printed in admin is escaped for its context; prompts
  and provider messages are treated as untrusted text.
- No `eval`, no shell, no `@` suppression. Typed exceptions inside; reason
  codes outside.
- Personal data: the plugin stores the requesting and reviewing user IDs.
  It registers with the WordPress personal data exporter and eraser to
  export them and to replace them with 0 on erasure.

## 9. Performance budget

- Front end: no queries beyond reading already loaded attachment meta when
  `label_mode` includes `append`; no HTTP calls ever.
- Post edit screen: one REST status call on load, then polling only while a
  request is active (5.8). The panel script and style are loaded only
  on enabled post types.
- Save and publish hooks: under 5 ms and no HTTP; they only insert a queue
  row.
- Worker: one provider call at a time per batch, bounded by the timeout;
  memory bounded by the byte and pixel limits plus one image editor
  instance.
- Clean-up: batches of 50, 20 seconds per run.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 or later. Single site and multisite.
- Block editor and classic editor.
- Works with offload-media plugins that hook `media_handle_sideload` and
  `wp_generate_attachment_metadata`; crop and conversion run before the
  sideload so offload sees the final file.
- Image editor: GD or Imagick through `WP_Image_Editor`; the plugin works
  without either (no crop, no conversion, both recorded as skipped).
- Page caches and CDNs: the plugin sends no cache headers; it fires
  `ffp_accepted` so cache plugins can purge the post.
- WP-Cron or a real cron. With `DISABLE_WP_CRON` the README shows the cron
  line.
- gen-queue and spend-meter: optional, detected at runtime through their
  public hooks; never required.
- Themes: the label works through the attachment caption (most themes print
  it) or the `post_thumbnail_html` filter.

## 11. Observability

- Every request writes one log line through `error_log` when `WP_DEBUG_LOG`
  is on, or always when the setting "log requests" is on: prefix
  `[featured-from-prompt]`, request id, post ID, trigger, outcome, reason,
  provider, real size, duration. Never the secret, never the full prompt
  (first 80 characters only).
- The post panel shows the last request in plain words with its reason
  code.
- The settings page shows today's count against the cap, the per-provider
  pause state, the queue length, the oldest queued request, and the recent
  requests table.
- A Site Health test reports: provider configured, kill switch state, cron
  running (last batch time), queue backlog, and whether an image editor is
  available.
- `wp featured-from-prompt caps` and `queue list` show the same from the
  command line.

## 12. Acceptance tests

Each test maps to the case of the same number. Tests use a stub WordPress
layer and an in-memory provider double unless stated.

1. An eligible post passes; each listed condition, broken alone, makes it
   ineligible.
2. An auto request is refused with `has_image` for a post with a real
   featured image and allowed when the featured image is a placeholder; a
   manual request is allowed in both cases.
3. A featured image ID pointing to a deleted attachment, or to a PDF
   attachment, is treated as no image.
4. Each ineligibility returns its exact reason code.
5. Clicking Generate (REST route) with valid nonce and capabilities creates
   one queued manual request.
6. With "suggest on save" on and `auto_mode` `on`, saving an eligible post
   queues one request; an autosave and a revision queue none; a second save
   queues none.
7. Publishing with "suggest on publish" on queues one request and the post
   is published at once.
8. `do_action('ffp_request', $id, [])` queues one auto request and makes no
   provider call in the same process.
9. After an auto request ends, further auto triggers queue nothing; a
   manual request with force still queues; clearing the marker allows auto
   again.
10. With `auto_mode` `log`, a trigger records `would_request`, reserves
    nothing and makes no provider call; with `off` it does nothing.
11. A second trigger while one request is queued returns `already_queued`
    and the same id.
12. The template renders each variable; an unknown `{foo}` stays as typed.
13. A title with entities and tags renders as plain text.
14. The summary uses the excerpt when set, else the first N words with
    shortcodes, tags, URLs and e-mail addresses removed; N = 0 gives empty.
15. Primary term from the filter wins; else first category; `Uncategorized`
    gives empty.
16. Seven tags with a limit of 5 give the first 5 in order.
17. The style setting appears in the prompt.
18. The default template renders as specified for a sample post and
    contains no site name.
19. A negative prompt reaches a provider that supports it and not one that
    does not.
20. An empty category removes "Topic: ." from the output.
21. A prompt longer than the provider maximum is cut at a word boundary in
    characters (tested with Devanagari and emoji text) and records
    `prompt_truncated`.
22. A post with an empty title and no override ends `prompt_empty` with no
    counter change.
23. An override replaces the rendered prompt, is cleaned and cut.
24. A filter returning a non-string is ignored and logged.
25. With the setting off, with `FFP_DISABLED`, and with the filter false,
    no provider call happens, queued rows keep state `queued`, and a
    trigger records `disabled`.
26. No provider gives `provider_not_configured`; an unknown provider ID
    gives `no_provider`.
27. A paused provider leaves requests queued with `provider_paused` and no
    count.
28. A post trashed after queuing ends `post_gone`; an auto request for a
    post that got an image ends `has_image`.
29. The fourth attempt on one post in one day with cap 3 ends `cap_post`.
30. With day cap 2, the third request waits as `cap_day` and runs on the
    next site day (clock moved across midnight in the site timezone, also
    across a daylight-saving change); cap 0 runs nothing.
31. Two reservations at cap minus one, run concurrently against a real
    database in CI, give exactly one success.
32. An adapter throwing `ConfigException` refunds the reservation; a
    timeout after sending does not.
33. A spend-meter stub returning `null` lets the own caps decide; a deny
    ends `spend_denied` with the meter reason and refunds; an allow with
    the own cap reached still ends `cap_day`; record or release follows
    every allowed reservation exactly once.
34. A second worker on a locked post leaves the request queued with
    `locked` and no count.
35. The provider double receives prompt, size, format, count 1, request id
    and timeout.
36. For a provider with sizes 1024x1024, 1536x1024, 1792x1024 and target
    16:9, the request asks for 1792x1024; for an any-size provider it asks
    for 1536x864.
37. A bytes result and a URL result both lead to a stored suggestion.
38. A result URL on a private address, over HTTP, or redirecting 4 times is
    refused with `download_failed` and counted.
39. A 401 pauses the provider until resumed; a quota error pauses it for
    the setting; one notice and one action fire; other queued requests are
    not attempted.
40. A 429 with `Retry-After: 120` re-queues for 120 seconds; without it,
    5 minutes; with 7200, 1 hour.
41. A refusal ends `prompt_refused`, stores the trimmed message, and an auto
    request is not retried.
42. Three timeouts end `failed` with `timeout` after backoff 1 and 5
    minutes; each attempt counts.
43. A result with two images stores only the first.
44. A result with neither bytes nor URL ends `bad_response`.
45. An adapter throwing a `TypeError` is mapped to `transient` and logged.
46. An HTML body, a GIF and an SVG each end `not_image`, whatever the MIME
    hint says.
47. A 16 MB stream with a 15 MB limit stops reading near the limit and ends
    `file_too_large`; a 500-byte body ends `not_image`.
48. A truncated PNG ends `decode_failed`; a JPEG with a PNG extension hint
    is accepted as JPEG.
49. A small file declaring 20000 by 20000 pixels ends `too_many_pixels`
    before an image editor is created.
50. A 512 by 512 result ends `too_small`.
51. A 1024 by 1024 result ends `bad_aspect` with crop off; 1792 by 1024
    passes.
52. With crop on, 1024 by 1024 becomes 1024 by 576 and passes; 800 by 800
    cropped becomes too small and ends `too_small`.
53. `jpeg` output converts a PNG; with no image editor the original is kept
    and `convert_skipped` recorded.
54. A filter raising the minimum width for one post type is applied only
    to that type.
55. A check failure is final, counted, and not retried automatically.
56. The stored file name matches `ffp-[0-9a-f]{16}\.(png|jpe?g|webp)` and
    the attachment's parent is the post.
57. Every stored suggestion has the full record; a forced meta write
    failure deletes the attachment and ends `record_failed`.
58. Title, alt and caption follow the templates; alt is cut at 250
    characters.
59. The featured image is unchanged after a suggestion is stored; the
    action fires once; no temporary file remains on any path.
60. The panel status route lists pending suggestions newest first with the
    listed fields.
61. Accept sets the featured image, state `accepted`, supersedes the
    others and fires the action with the previous ID; each failed check
    returns its reason and changes nothing; a user without `edit_post` is
    refused.
62. Accepting over a real image replaces it and keeps the old attachment.
63. Reject records state, user, reason (cut at 200 characters) and fires
    the action; no new request starts.
64. Generate another while one suggestion is pending creates a second
    pending suggestion; both stay pending.
65. Setting a pending suggestion as featured through the media modal marks
    it accepted and supersedes the others; setting an unrelated image
    leaves suggestions pending.
66. A final failure with `none` leaves the post unchanged; with
    `placeholder` it sets the fallback only when the post has no featured
    image.
67. A transient failure sets no placeholder until the last attempt.
68. The filter returning another image ID uses it; returning a non-image ID
    is ignored and logged; returning 0 sets none.
69. A listed placeholder of an ordinary size and wide shape is reported by
    `is_placeholder()` and never counted as a usable image; the filter can
    add another ID.
70. A post on a placeholder is eligible for auto requests and reported as
    having no image; Accept replaces the placeholder.
71. The placeholder action fires once with the record.
72. Each label mode produces the documented output on a single view.
73. With `append`, HTML already containing the label text gets no second
    label.
74. No appended label in admin, feeds, sitemaps, or another post's view.
75. `is_generated()` and the REST field are true for a suggestion and false
    for an ordinary upload.
76. A pending suggestion older than the limit becomes `expired`.
77. A rejected suggestion past retention is deleted; one that became a
    featured image is kept and set back to `accepted`; the keep filter
    protects one.
78. Accepted images survive clean-up at any age.
79. Clean-up stops at the batch size and the time budget.
80. A batch runs at most the batch size; a second concurrent batch exits
    with `locked` without touching rows.
81. With a gen-queue stub defining the constant and returning a job id, the
    request is enqueued with the documented type and key; returning `null`
    falls back to the own queue; the guards run inside the handler.
82. An auto request older than the age limit ends `expired_in_queue`; a
    manual one does not.
83. Backfill dry run lists eligible posts newest first with skip reasons and
    makes no change; markers are respected unless `--force`.
84. Backfill queues no more than today's remaining cap without `--spread`,
    and all with it.
85. Accept, which sets the thumbnail, does not trigger a second Accept or a
    new request through the save hook.
86. Running a request already `done` returns `already_done` and calls
    nothing.
87. A `running` request with an old heartbeat becomes `failed` with
    `worker_lost`, counted, not retried.
88. With a persistent object cache stub holding a stale "enabled" value,
    a switch turned off in the database stops the next guard check.
89. On multisite, caps and queues of two sites are independent.

Also tested, outside the numbered cases: a sweep over every trigger, the
worker, backfill, the placeholder policy and clean-up proves that none of
them ever sets a generated image as the featured image, and that only
Accept or the media modal pick by a person does; uninstall removes what section 6
lists and keeps accepted images with their meta; secrets never appear in
logs, REST responses, records or exception messages; every admin form
rejects a bad nonce; every REST route rejects a user without the
capability; the personal data exporter and eraser work; the
`generic-http` adapter maps each documented status to its exception and
reads base64 and URL responses by dot path.

## 13. Open questions

To be answered by the builder in `docs/ARCHITECTURE.md`.

1. Run mode for manual requests: always background with polling (as
   specified), or an optional synchronous mode for sites where WP-Cron is
   unreliable, bounded by the PHP time limit?
2. Own queue storage: a custom table (as specified) or a private post type
   or option rows for very small sites? If a table, how is the schema
   verified after upgrade on hosts where `dbDelta` skips column changes?
3. Should the `generic-http` adapter support multipart form requests and
   asynchronous APIs (submit, then poll a job URL) in 0.1.0, or only a
   single synchronous JSON call?
4. Should pending suggestions be stored outside the public uploads path
   (a protected directory served through an authenticated handler) until
   accepted, at the cost of offload plugin compatibility?
5. Per-user daily caps for authors who can generate: needed in 0.1.0, or
   left to spend-meter?
6. Should accepted images also carry an embedded provenance marker (IPTC
   digital source type in the file, or a content credential), and with
   which dependency-free method?
7. Should the prompt template integrate with `prompt-library` when it is
   released, through a soft filter like gen-queue and spend-meter?
8. How should `accept` behave when the post is locked by another editor
   (the WordPress post lock): refuse, or accept with a notice?

## 14. Lessons from production

Written as rules for the builder.

1. Know a placeholder by its ID, never by its size. A placeholder is a real
   picture of a good size and shape, and every size and aspect test passes
   it.
2. Do not make one shared placeholder the default answer to failure. On
   many posts it makes every share preview identical. Default to leaving
   the post as it is, and let share-card tools draw a per-post card.
3. Mark a generated image as generated in the same step that stores it.
   Every later check, label and filter reads that mark; an image stored
   without it is indistinguishable from a photograph and cannot be labelled
   later.
4. Label generated images where readers see them, and never twice: check
   whether the theme already prints the label in the caption.
5. Every path that can start generation goes through the same kill switch,
   cap and one-attempt marker, including fallback paths written later in
   other code. Expose one entry point and make the provider unreachable
   except through it.
6. Try automatic generation once per post. After a final failure, only a
   person may try again; otherwise one bad prompt retries forever and
   spends every day.
7. Check the file you received, not the request you sent. Providers return
   other sizes, squares for wide requests, and error pages with a success
   status.
8. Reserve the cap before the call, in one atomic operation. A cap read at
   the start of a run, or from a cache, lets concurrent runners overshoot.
9. An account error (bad key, empty balance) pauses the provider, not one
   post. Otherwise every queued request burns its attempts on the same
   fault.
10. A request that may have been billed is never retried blind. If the
    worker died after sending, mark it lost and let a person decide.
11. Guard against re-entry. Setting a featured image fires hooks, and a
    plugin that listens to them can start itself again.
12. When the featured image changes, tell everything derived from the old
    one (share cards, page caches) through an action; a card rendered over
    the old picture is often cached for a long time.
13. Record a reason code for every decision, on the post and in the log, so
    an operator can answer "why has this post no picture" without reading
    code.
14. Roll out any automatic trigger in a log-only mode first, and read what
    it would have done before letting it spend.
15. Never let a switch, a cap or a pause be cached for the life of a
    process; read it fresh at the moment of the call.
