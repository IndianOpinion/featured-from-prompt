# featured-from-prompt

A WordPress plugin that suggests a featured image for a post: it builds a prompt from the post, asks any image API through a small adapter, checks the file that comes back, and holds it for an editor who accepts it with one click. It exists because posts without a picture look broken in grids, share previews and feed readers, and because image generation wired straight into a save hook spends money on every save and publishes pictures nobody has looked at.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

Measured: against a real MySQL server, 8 workers racing for the last slot under the daily cap took exactly one slot in each of 20 rounds (the cap never overshot).

## Contents

- [What it does](#what-it-does)
- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [Hooks, PHP API, REST and WP-CLI](#hooks-php-api-rest-and-wp-cli)
- [Privacy and safety](#privacy-and-safety)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## What it does

- Builds a generic, editable prompt from the title, a short summary, the category and tags.
- Calls any image API through one adapter interface. Ships a configurable `generic-http` JSON adapter (one-shot or submit-then-poll) and an offline `fixture` adapter for demos and tests.
- Checks the real bytes: type by magic number, decodable, pixel size, aspect ratio, byte size, pixel count. Optional centre crop and format conversion.
- Stores each result as a **pending suggestion** in a protected directory outside the uploads tree. Nothing becomes a featured image until a person clicks Accept.
- Marks every generated image as generated, with its prompt and provider, at the moment it is stored, and labels it where readers see it.
- Enforces a per-day cap, a per-post cap and a kill switch on every path, reserved atomically before the call.
- Applies a placeholder policy when generation fails, and treats placeholders as "no image" everywhere.
- Runs in the background (WP-Cron, a real cron, or `gen-queue` when present). Never on a front-end request.

## Install

Requirements: PHP 8.1 or later, WordPress 6.4 or later, MySQL or MariaDB. GD or Imagick is optional (needed only for crop and conversion).

### Without Composer

1. Download the release zip, or clone the repository into `wp-content/plugins/featured-from-prompt`.
2. Activate **Featured from prompt** under Plugins.

The plugin has no runtime dependencies. It loads its own classes from `src/autoload.php`.

### With Composer

```sh
composer require indianopinion/featured-from-prompt
```

The package type is `wordpress-plugin`, so `composer/installers` (if your project uses it) puts it in `wp-content/plugins/`.

### As a must-use plugin

Put the plugin folder at `wp-content/mu-plugins/featured-from-prompt/` and copy [`examples/mu-loader.php`](examples/mu-loader.php) to `wp-content/mu-plugins/featured-from-prompt-loader.php`:

```php
<?php
require_once __DIR__ . '/featured-from-prompt/featured-from-prompt.php';
```

Must-use plugins have no activation hook, so the tables are created on the first request.

### Protect the pending directory

Pending images live in `wp-content/ffp-private/` (or the path in the `FFP_PRIVATE_DIR` constant, which can sit outside the web root). Apache and IIS get deny files automatically. On nginx add:

```nginx
location ^~ /wp-content/ffp-private/ { deny all; }
```

The plugin fails closed: it refuses to store anything when the directory would sit inside the uploads tree, when its path contains `..`, or when the deny files cannot be written. On Accept the file is moved into uploads only when its name is one the plugin made and its bytes still sniff as the image type on record.

### Cron

Requests run from WP-Cron every minute while work is queued. With `DISABLE_WP_CRON` set, run cron yourself:

```sh
* * * * * cd /path/to/wordpress && wp cron event run --due-now --quiet
```

## Quick start

1. Settings, Featured from prompt. Choose a provider.
   - To try it with no API and no cost, pick **Test fixture (no API calls)**.
   - For a real API, pick **Generic HTTP JSON** and fill in the endpoint, the auth header, the body template and the path to the image in the answer.
2. Tick **Enabled** and save. (Enabled stays off until a provider is saved.)
3. Press **Test provider**. One counted request is sent; the image is checked and thrown away.
4. Open a post without a picture. In the **Featured image suggestion** panel, read or edit the prompt and press **Generate image**. The panel polls until the suggestion is ready.
5. Press **Accept** to make it the featured image, or **Reject**, or **Generate another**.

Run it without WordPress: `composer install && php examples/offline-demo.php` queues, runs and accepts a suggestion with the fixture provider.

A `generic-http` setup for an API that answers with base64 data:

| Field | Value |
|---|---|
| Endpoint URL | `https://api.images.example.com/v1/generations` |
| Auth header name / value | `Authorization` / `Bearer <your key>` |
| Body template | `{"prompt": "{prompt}", "size": "{width}x{height}", "n": 1}` |
| Response holds | Base64 image data |
| Path to the image | `data.0.b64_json` |
| Supported sizes | `1024x1024, 1792x1024` |
| Refusal marker | `content_policy` |

For an asynchronous API, set **Asynchronous APIs: path to the job URL** (for example `urls.get`), the status path and the done and failed values. The plugin submits once and polls the job URL until it finishes or the timeout runs out. Polls are not counted; the submit is.

## Configuration

Every setting is on the settings screen, validated and bounded. Constants override settings and are shown read-only.

| Setting | Default | Range or values |
|---|---|---|
| Enabled (kill switch) | off until a provider is saved | on, off |
| Provider | none | registered provider IDs |
| Post types | `post` | public types with thumbnails |
| Include private and protected posts | off | on, off |
| Auto mode | `off` | `off`, `log` (no calls), `on` |
| Suggest on save / on publish | off / off | on, off |
| Prompt template | generic, see below | 20 to 4000 characters |
| Style | "clean editorial illustration, muted colours, wide composition" | up to 300 characters |
| Negative prompt | empty | up to 1000 characters |
| Summary words / tags in prompt | 60 / 5 | 0 to 200 / 0 to 15 |
| Prompt length limit | 1000 | 100 to 4000 characters |
| Target aspect / size (any-size APIs) | 16:9 / 1536 by 864 | ratio 0.25 to 8.0 / 256 to 4096 |
| Minimum width and height | 1024 and 576 | 100 to 8000 |
| Aspect window | 1.3 to 2.1 | 0.25 to 8.0 |
| Crop to target aspect | off | on, off |
| Output format, quality | `keep`, 82 | `keep`, `jpeg`, `webp`; 40 to 100 |
| Maximum file size / pixels | 15 MB / 40 MP | 1 to 50 MB / 1 to 100 MP |
| Timeout | 90 s | 10 to 300 s |
| Attempts on transient errors | 3 | 1 to 5 |
| Pause after a quota error | 6 h | 15 min to 7 days |
| Per-day cap (site) | 20 | 0 to 10000 (0 runs nothing) |
| Per-post cap per day | 3 | 1 to 20 |
| Batch size, time budget | 3, 120 s | 1 to 20, 30 to 600 s |
| Queue age limit (auto) | 2 days | 1 hour to 30 days |
| On failure | `none` | `none`, `placeholder` |
| Fallback placeholder / placeholder IDs | none / the fallback | image attachment IDs |
| Label mode, text | `caption`, "AI-generated illustration" | `caption`, `append`, `both`, `off` |
| Alt text template | "Illustration: {title}" | up to 250 characters |
| Pending limit | 30 days | 1 to 365 |
| Keep rejected, superseded, expired | 14 days | 0 to 365, -1 keeps forever |
| Keep request rows | 90 days | 7 to 3650 |
| Hide pending from the media picker | on | on, off |
| Log every request | off | on, off |
| Keep everything on uninstall | off | on, off |

Constants:

| Constant | Effect |
|---|---|
| `FFP_DISABLED` | `true` stops every provider call at once. |
| `FFP_DAY_CAP` | Lowers the per-day cap. Can never raise it. |
| `FFP_PROVIDER` | Forces the provider ID. |
| `FFP_SECRET_<PROVIDER>_<FIELD>` | A secret from code, for example `FFP_SECRET_GENERIC_HTTP_AUTH_VALUE`. Wins over the stored value. |
| `FFP_ALLOW_LOCAL_ENDPOINT` | `true` allows a `generic-http` endpoint on a private address (a self-hosted model). |
| `FFP_PRIVATE_DIR` | Where pending files live. Point it outside the web root if you can. |

The default prompt template is generic on purpose:

> An editorial illustration for an article titled "{title}". It is about: {summary}. Topic: {category}. Style: {style}. Do not include any text, letters, numbers, logos, watermarks, signatures or captions. Do not depict any real, identifiable person.

Variables: `{title}`, `{summary}`, `{category}`, `{tags}`, `{post_type}`, `{style}`, `{site_language}`. Unknown variables are left as typed and logged once.

## How it works

```
trigger (button, REST, save, publish, ffp_request, backfill, CLI)
  -> Queue::request: eligibility, auto mode, one-attempt marker, duplicate guard, prompt
  -> queue row (own table, or gen-queue)               nothing is spent here
  -> Worker::run (cron batch or gen-queue job)
       kill switch -> provider -> pause -> eligibility -> queue age -> post lock
       -> atomic reservation (per post, then per day) -> spend-meter
       -> provider call -> download (safe HTTP) -> checks -> crop/convert
       -> pending suggestion in the private directory, record written
  -> editor: Accept (file moves into uploads, sizes built, featured image set)
             Reject, or Generate another
```

- **Only a person makes a generated image featured**: Accept in the panel, the REST route or WP-CLI, or a deliberate pick of a pending suggestion in the media modal. No trigger, worker, backfill, placeholder policy or clean-up does.
- **Caps are reserved before the call** with one atomic `UPDATE ... WHERE count < cap`. A reservation is refunded only when no HTTP request was sent.
- **One automatic attempt per post.** After an automatic request ends, only a person can try again (button, REST, or WP-CLI with `--force`).
- **Account errors pause the provider**, not the post: a 401 pauses until an administrator resumes it; a quota error pauses for the quota pause setting.
- **Every decision has a reason code** on the post, in the log line and in the panel.
- **Switches are read fresh** from the database at every guard, never from a persistent object cache.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the modules, the data and every design decision.

## Companion plugins

All three are optional and reached only through their public hooks.

- **spend-meter** (contract `SPEND_METER_API` 1). Before each call the worker applies `spend_meter_pre_spend` with an intent: purpose `featured_image`, caller `featured-from-prompt`, the provider id, the model (the provider id when no model is set) and `est_units` 1. `null` means no meter. Anything but `allowed: true` ends the request `spend_denied` and refunds the plugin's own reservation. A sent call is reported with one `spend_meter_record` call array (`ok`, `error_billed` or `error_unbilled`); a call that was never sent releases the reservation with `spend_meter_release`. The provider test on the settings screen goes through the same gate.
- **gen-queue**. When `GEN_QUEUE_VERSION` is defined, the plugin registers the job type `featured_from_prompt.generate` on `gen_queue_register_types` and enqueues through `gen_queue_enqueue_job` with the idempotency key `ffp:<post_id>:<request_id>`. The handler runs every guard. Retries and waits go back to the own queue, so a gen-queue job runs once. gen-queue's own daily cap applies too: with no cap set there, nothing runs.
- **prompt-library**. When it holds a live template with the slug `featured-from-prompt` (filter `ffp_prompt_library_slug`), the prompt is rendered through `promptlib_render` with the variables above as values (`title`, `summary`, `category`, `tags`, `post_type`, `style`, `site_language`). Otherwise, or on any render error, the plugin's own template is used.

## Hooks, PHP API, REST and WP-CLI

PHP API (namespace `IndianOpinion\FeaturedFromPrompt`, with global `ffp_*` wrappers): `request()`, `run_request()`, `preview_prompt()`, `accept()`, `reject()`, `status()`, `is_generated()`, `is_placeholder()`, `register_provider()`.

Filters: `ffp_enabled`, `ffp_should_process`, `ffp_prompt_variables`, `ffp_prompt_template`, `ffp_primary_term`, `ffp_prompt`, `ffp_image_rules`, `ffp_http_args`, `ffp_attachment_data`, `ffp_placeholder_id`, `ffp_is_placeholder`, `ffp_label_html`, `ffp_label_context`, `ffp_keep_attachment`, `ffp_capability_generate`, `ffp_capability_settings`, `ffp_day_cap`, `ffp_post_cap` (lower only), `ffp_private_dir`, `ffp_use_gen_queue`, `ffp_prompt_library_slug`.

Actions: `ffp_register_providers`, `ffp_request`, `ffp_request_queued`, `ffp_request_finished`, `ffp_suggestion_ready`, `ffp_accepted`, `ffp_rejected`, `ffp_placeholder_set`, `ffp_provider_paused`, `ffp_cap_reached`, `ffp_log`. `ffp_accepted` is the signal for share-card renderers and page caches to refresh anything built from the old picture.

REST namespace `featured-from-prompt/v1`: `GET posts/{id}/status`, `GET posts/{id}/prompt`, `POST posts/{id}/generate`, `POST posts/{id}/cancel`, `POST posts/{id}/opt-out`, `POST posts/{id}/clear-marker`, `POST suggestions/{id}/accept`, `POST suggestions/{id}/reject`, `GET suggestions/{id}/preview`. Media items gain read-only `ffp_generated` and `ffp_review_state`.

WP-CLI `wp featured-from-prompt`: `generate`, `backfill`, `prompt`, `status`, `accept`, `reject`, `queue list|run|cancel`, `provider list|test|pause|resume`, `caps`, `cleanup`, `clear-marker`. Exit codes: 0 success or dry run, 1 a request failed, 2 bad arguments, 3 the kill switch is off.

Writing an adapter for another API: implement `Provider\ImageProvider` and register it on `ffp_register_providers`. See [examples/custom-provider.php](examples/custom-provider.php).

## Privacy and safety

- Prompts, the negative prompt and the size are sent to the third-party image API you configure, and are subject to its terms and retention. Post content is reduced to the summary words, with URLs and e-mail addresses removed. Private and password-protected posts are excluded by default.
- The default template asks for no identifiable person. Changing that carries legal and ethical risk; think before you do.
- Secrets are read from constants first, otherwise stored in non-autoloaded options, never shown again, never logged, never in REST answers or records.
- Result downloads use HTTPS, a byte limit and at most 3 redirects. The plugin follows each redirect itself and checks every hop: no private, loopback, link-local or reserved address, written literally or reached through DNS. The same check applies to the job URL of a submit-then-poll API and to the endpoint (unless `FFP_ALLOW_LOCAL_ENDPOINT` is true). Submit and polls together stay inside the request timeout. The type is sniffed from the bytes and the pixel count is checked before any image editor touches the file.
- Pending files have random names and no public URL; previews go through an authenticated REST route. Once accepted, a file is an ordinary upload and public by URL, like any other.
- The plugin stores the requesting and reviewing user IDs and plugs into the WordPress personal data exporter and eraser.

## Limitations

- No provenance is written into the image file (IPTC, XMP or content credentials) in 0.1.0. The record lives in attachment meta. It is on the roadmap.
- `generic-http` speaks JSON only; multipart form uploads are not supported in 0.1.0.
- No per-user caps. Use `spend-meter` for budgets by user or by month.
- Only the featured image. No images inside post content or galleries.
- AVIF, GIF and SVG results are refused.
- A network-wide cap on multisite is out of scope; every site has its own caps and queue.
- Offload plugins see a generated file only when it is accepted (pending files stay in the protected directory).

## FAQ

**Will it ever set a picture on its own?** No. It writes a pending suggestion. A person accepts it.

**What stops it spending money?** The kill switch, the per-day cap and the per-post cap, reserved atomically before every call, plus a pause on account errors and one automatic attempt per post. `spend-meter`, when active, is a second gate, never a bypass.

**How do I roll out automatic suggestions safely?** Set Auto mode to `log` first. Triggers then build the prompt and run every guard, record what would have happened (`would_request`) and call nothing. Read the recent requests table, then switch to `on`.

**Why is my post's request stuck as queued?** The panel and `wp featured-from-prompt status <id>` show the reason code: `disabled`, `provider_paused`, `cap_day`, `locked` or `rate_limited` all wait; everything else ends.

**Why did nothing happen when generation failed?** The default `on_failure` is `none`: a shared placeholder on many posts makes every share preview the same. Set it to `placeholder` if you want the fallback image.

**Does it work with page caches and CDNs?** It sends no cache headers. Listen to `ffp_accepted` to purge the post.

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See [LICENSE](LICENSE).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).
