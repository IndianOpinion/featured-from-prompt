=== Featured from prompt ===
Contributors: indianopinion
Tags: featured image, image generation, editorial, media, thumbnails
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Suggests a featured image generated from a prompt built from the post. An editor accepts it with one click. Daily cap and kill switch included.

== Description ==

Written from scratch; not derived from any third-party plugin.

For a post that needs a featured image, the plugin builds a short prompt from the post, sends it to the image API you configure through a small adapter, checks the image that comes back for type, size and shape, and stores it as a pending suggestion. An editor sees it on the post screen and accepts it, rejects it, or asks for another. Nothing becomes a featured image without that click.

* Generic HTTP JSON adapter for any image API (one-shot or submit-then-poll), plus an offline test fixture.
* Real checks on the returned bytes: type, decode, pixel size, aspect, byte size, pixel count. Optional crop and conversion.
* Per-day cap, per-post cap and a kill switch on every path, reserved atomically before each call.
* One automatic attempt per post; a log-only mode to try automatic triggers safely.
* Pending images stay in a protected directory until accepted.
* Generated images are marked as generated and labelled where readers see them.
* Placeholder policy for failures; placeholders count as "no image".
* REST API, WP-CLI, Site Health test, personal data exporter and eraser.

Prompts are sent to the third-party image API you configure and are subject to its terms and retention.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/` and activate it.
2. Settings, Featured from prompt: choose a provider (the test fixture needs no API), tick Enabled, save.
3. Press Test provider.
4. On a post, press Generate image in the Featured image suggestion panel, then Accept.

With `DISABLE_WP_CRON`, run `wp cron event run --due-now` every minute. On nginx, deny `/wp-content/ffp-private/`.

== Frequently Asked Questions ==

= Will it set a featured image on its own? =

No. It only writes suggestions. A person accepts them.

= What limits spending? =

The kill switch, the daily cap and the per-post cap. Account errors pause the provider.

= Does it support multipart uploads or write provenance into the file? =

Not in 0.1.0.

== Changelog ==

= 0.1.0 =
* First release.
