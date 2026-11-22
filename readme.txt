=== Natilus ===
Contributors: akbarpoori
Tags: publishing, content, elementor, seo, gutenberg, classic-editor, bulk, workflow
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.0
Stable tag: 2.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Paste your FINISHED article and Natilus publishes it to WordPress properly — روتیتر (kicker) and زیرتیتر (subtitle) alongside the main title, synced with whatever fields your active theme uses, media import, galleries, featured image, native Elementor / WPBakery / Classic / Gutenberg structures, Yoast & Rank Math SEO, checklist, rollback, bulk publish and an optional drip queue. A publisher, not a generator.

== Description ==

Natilus is a WordPress PUBLISHER. You write (or generate anywhere you like — ChatGPT, Claude, your own tools) and paste the finished article into the Natilus box under the editor; the plugin publishes it EXACTLY as pasted — it never rewrites, regenerates or asks how many words anything should be.

What Natilus does handle for you:

* Direct publish: paste HTML or plain text (plain text gets standard paragraph formatting — formatting, never rewriting), choose the target (append to the current post, replace it, or create a new post with title/status/category/tags), one click and it is in WordPress.
* Headline set (NEW v2.2): the full Persian journalism headline structure — روتیتر (kicker, the small headline above the title) and زیرتیتر (subtitle, the explanatory line below the title) — in the publish widget AND in bulk publish (`^ روتیتر` / `~ زیرتیتر` header lines). They are stored canonically AND mirrored into the fields your ACTIVE theme actually uses (auto-detected from the live site structure: registered meta keys + the meta keys present in your database), exposed as REST fields (kic_kicker / kic_subtitle), and — when the theme has no native kicker/subtitle field at all — displayed by the plugin itself on the single post view with clean, theme-inheriting markup. Developers get filters (kic_theme_sync_candidates, kic_kicker_html, kic_subtitle_html), shortcodes ([kic_kicker] / [kic_subtitle]) and template tags (kic_the_kicker() / kic_the_subtitle()).
* Media: external image/video URLs are imported into the WordPress media library, original URLs are replaced with local ones, optional galleries and featured image.
* Builders: native Elementor documents (sections/columns/widgets), native WPBakery VC shortcodes, Classic and Gutenberg modes — the structure lands correctly the first time.
* SEO: Yoast & Rank Math title/description/focus keyword, JSON-LD schema — quick fields right in the publish box.
* Safety: automatic snapshot before every publish and one-click rollback (rate limiting applies only to the optional AI suggestions, never to publishing).
* Bulk publish: paste many finished articles at once (blocks separated by a `---` line, optional `# title`, `^ kicker` and `~ subtitle` first lines) — drafts or published posts, optionally scheduled into the drip queue.
* Drip queue & calendar: schedule drafts over days/hours, drag-and-drop rescheduling, pause/resume, "publish next now".
* Checklist: read-only pre-publish warnings (featured image, category, slug duplicates, SEO meta, alt texts, internal links) — nothing is auto-fixed.
* Reports & transfer: internal-link opportunity report, transferable settings bundle, WP-CLI queue commands, bilingual UI (Persian/English) + .pot for translators.

Optional suggestions (strictly opt-in):

Category, tag, English-slug and internal-link suggestions exist as OPTIONAL helpers. They never run automatically, are applied only by an explicit click, and need a provider you configure yourself — there is NO default provider and publishing never requires one. Any OpenAI-compatible endpoint works, including key-less local servers (Ollama / LM Studio). The slug is generated from your title by WordPress itself if you skip suggestions entirely.

**Privacy note:** publishing with Natilus sends nothing anywhere. If (and only if) you configure a provider for the optional suggestions, the current post's title/content is sent to that provider with your own key. No tracking, no telemetry, no third-party analytics.

**Design principle:** Natilus is a publisher, not a writer. It does not generate, rewrite or continue content — ever.

= Credits =

Design & Development: Meysam Akbarpoori (meysam@akbarpoori.ir)
Website: https://akbarpoori.ir
GitHub: https://github.com/akbarpoori
LinkedIn: https://www.linkedin.com/in/akbarpoori/

== Installation ==

1. Upload the `ai-content-publishing-agent` folder to `/wp-content/plugins/` (or install the ZIP via Plugins → Add New → Upload).
2. Activate the plugin — done. Publishing works immediately with NO provider and NO setup.
3. Edit any post: paste your finished article into the Natilus box under the editor and click "انتشار محتوا".
4. (Optional, only if you want the suggestion helpers) Natilus → Settings → provider.

== Frequently Asked Questions ==

= Does Natilus generate or rewrite my content? =

No — never. It is a publisher: your pasted content is published word-for-word. Plain text (with no HTML tags) only gets standard WordPress paragraph formatting; that is formatting, not rewriting. There are no word-count questions and no generation step anywhere.

= Do I need an AI provider? =

No. There is no default provider and publishing requires none. Only the optional suggestion helpers (category / English slug / internal links) use a provider, and only if you configure one yourself — a local Ollama or LM Studio server works too, with an empty API key.

= Where is my data sent? =

Publishing sends nothing anywhere. If (and only if) you configure a provider for the optional suggestions, the current post's title/content is sent to that provider with your own key. The plugin itself has no telemetry, no tracking and no third-party analytics.

= Can I keep working if my AI provider dies mid-way? =

The provider & model priority chain (for the optional suggestion helpers) shifts to your next configured model/provider automatically and records the failover in the chain health history.

= How do I move settings to another site? =

Settings → "Transfer settings" → download a portable JSON bundle (API keys excluded by default) and import it on the other site.

= My host blocks WP-Cron. What can I do? =

Use the WP-CLI commands: `wp kic queue --action=run` and `wp kic tokens --days=7`.

== Changelog ==

= 2.7.0 =
* Elementor: native Documents API write (normalize + CSS regen) with raw-meta fallback, clear_cache after publish, _elementor_page_settings set
* Elementor: Flexbox Container layout auto-used on Elementor >= 3.16 (legacy section/column only for older installs)
* Elementor: «open in Elementor» link after publish; post_content now also synced on replace (not only when empty)
* Easy Table of Contents: [ez-toc] shortcode widget auto-prepended on Elementor docs when ezTOC is active; stable heading IDs (kic-hN) added to all published h2-h6 for TOC anchors
* Prepublish: readability checks (long sentences >30w, wall-of-text paragraphs >120w), TOC hint for heading-rich posts, duplicate-title guard
* Schema: Article JSON-LD now emitted for every Natilus-published post even without an SEO payload; featured image preferred as schema image
* Feature: optional «مطالب مرتبط» block (3 same-category posts) appended on publish — Settings > ویژگی‌ها
* Publish flushes the post cache so fresh content shows immediately

= 2.6.0 =
* Fix: rollback now restores full publish state (Elementor data, featured image, SEO, kicker/subtitle, terms) — not just post_content
* Fix: ready-content publishing keeps legitimate embeds — iframe (http/https only), figure, video/audio, tables, details/summary, data-src images (was silently stripped by wp_kses_post)
* Fix: organize preview HTML sanitized client-side (script/on*/javascript: stripped before innerHTML)
* Fix: AI JSON parse recovers the outermost {...} block when the model wraps output in prose
* Fix: media sideload batch gets a real time budget + HEAD pre-check (MIME/size) — no more mid-batch death
* Fix: legacy Elementor posts get post_content rebuilt from _elementor_data (one-time migration)
* Feature: offline organize — H1:/H2:/H3:/• markers structure pasted text with no AI provider needed
* UX: live char/word counter under content box + post-size warning, Ctrl+Enter publish / Ctrl+Shift+Enter organize, undo-restore of the raw text after organize, organize re-fill now refreshes its own previous values without clobbering hand-typed fields
* Fix: kubit-media filename remnant renamed natilus-media; MIN_HTML_CHARS 80→20; slug shown in site preview

= 2.5.2 =
* CRITICAL fix: _elementor_edit_mode now writes "builder" (what Elementor checks) — posts published in Elementor mode rendered EMPTY body before
* Fix: Elementor-post detection accepts any non-empty edit_mode
* One-time migration repairs previously published posts

= 2.5.1 =
* Fix: cats/tags accept array or CSV (no more array-to-string warning)
* Fix: Elementor-mode publishes now sync post_content so WP editor/search/excerpts show content
* Complete OG/Twitter fallback: og:type/url/image + twitter:card/title/description/image
* Verified on live WordPress (activation, metabox, real AJAX publish, Elementor data)

= 2.5.0 =
* Import-first layout: content box on top, prompt below — organize mode only, no silent generation
* Site-like preview before publishing + media thumbnail grid with one-click featured pick
* English-slug checkbox, auto editor detection (Elementor/Classic/Gutenberg), OG/Twitter meta for Yoast/Rank Math, rel=follow/nofollow link rules

= 2.4.0 =
* THE GENERATION RELEASE — optional prompt-based article writing as a clear 3-step flow: (۱) give a topic/instruction (or paste raw notes) → (۲) review the laid-out preview (title, kicker, subtitle, body, SEO) → (۳) confirm and continue to the normal publish button. The configured provider chain drafts a complete publication-ready article straight into the SAME content box — for first publishes and brand-new drafts alike. Nothing is written until you confirm AND click «انتشار محتوا».
* NEW — «تولید تصویر با هوش مصنوعی» checkbox: asks the provider for a cover image via the OpenAI-compatible /images/generations endpoint and appends the URL to the media list, so it flows through the normal sideload → media library → gallery/featured-image pipeline on publish. Image model configurable in Settings → Features (image_model).
* NEW — kic_generate / kic_generate_image AJAX endpoints: nonce + capability + per-user rate limit + budget guard + prompt/output length caps + wp_kses_post sanitizing; generation output is preview-only (read_only) and never auto-published.
* NEW — Settings → Features toggles: generate_enabled (on by default) and image_model override.
* FIX — pasted content larger than PHP's post_max_size was silently dropped by PHP, so a complete article misleadingly reported «no content». Both the compose widget and the bulk publisher now detect the oversize body and show an explicit post_max_size error instead.
* The ready-content path is unchanged: with no prompt, your text is still published exactly as pasted.

= 2.3.0 =
* THE PROMPT-EDIT RELEASE — optional prompt-based AI edit of the CURRENT post. You write ONLY the instruction (e.g. «پاراگراف آخر را کوتاه کن») in the edit box; the server itself loads the saved post content, the model applies your instruction to it, and the result is PREVIEWED before anything is written. You never re-paste the article.
* NEW — «ویرایش این نوشته با پرامپت» collapsible panel in the publish widget with an instruction box, one-click preview (tabs: edited / current, model + token usage shown) and an explicit apply step.
* NEW — kic_ai_edit / kic_ai_edit_apply AJAX endpoints: read-only preview first, apply writes only after approval, always with a revision snapshot (rollback-safe) via wp_update_post.
* NEW — hard safety caps: instruction ≤ 2000 chars, article ≤ 60000 chars (longer posts are refused with a clear message instead of silently truncated), minimum-output sanity check before the preview is shown.
* The edit path reuses the publisher's own model-access layer: provider priority chain with automatic failover, auto-continue for long outputs, per-user rate limiter, budget guard and token usage tracking — the same trusted layer as every optional helper.
* Strictly opt-in: gated by the new ai_edit_enabled setting (Settings → Features) and only ever triggered by an explicit author click; publishing itself still needs no provider at all.
* Uninstall sweeps the new edit-state transients; kic-diagnostic.php file manifest updated for the 2.3.0 package.

= 2.2.0 =
* THE HEADLINE-SET RELEASE — full روتیتر (kicker) and زیرتیتر (subtitle) support alongside the main title, synchronized with the structure of whatever theme the site actually uses.
* NEW — headline fields in the publish widget: روتیتر and زیرتیتر inputs travel with the title (same empty = keep-current semantics for existing posts) and are stored canonically (_kic_kicker / _kic_subtitle).
* NEW — theme structure sync: the meta keys the ACTIVE theme and its plugins really use are auto-detected from the live site (WordPress-registered post meta + the meta keys actually present in the postmeta table, semantic pattern matching, cached 12 h) and the same text is mirrored into them, so the theme renders the kicker/subtitle exactly the way it was designed to. No hardcoded theme guesses; developers can override everything via the kic_theme_sync_candidates filter or a custom key per field (Settings → Features).
* NEW — universal fallback display: when the theme has no native kicker/subtitle field anywhere, the plugin outputs them itself above the content on the single post view (minimal inline CSS, fonts/colors inherited from the theme, zero extra HTTP requests, letter-spacing kept at 0 so Persian script joins are preserved). Can be turned off in settings.
* NEW — REST fields kic_kicker / kic_subtitle on post/page/product (read AND write, capability-checked) so Gutenberg, mobile apps and integrations can sync the headline set too.
* NEW — bulk publish headers: per-block `^ روتیتر` and `~ زیرتیتر` lines (plus the labeled روتیتر:/زیرتیتر: forms) next to the existing `# title`; each header kind is consumed at most once so body lines that merely look like headers are never eaten.
* NEW — template tags kic_the_kicker() / kic_the_subtitle() and shortcodes [kic_kicker] / [kic_subtitle] for theme developers, plus the kic_kicker_html / kic_subtitle_html / kic_print_kicker / kic_print_subtitle filters for exact placement and markup control.
* NEW — settings section «همگام‌سازی روتیتر و زیرتیتر با قالب سایت»: shows the detected theme and its candidate fields, per-field target select (auto / native field / standard meta only) and a developer custom-key override; saving refreshes the site-structure scan. Select values are whitelisted against the detected keys so the form can never write into arbitrary foreign meta.
* The publish widget shows a one-line live hint of how the current theme will receive the headline set (native field keys vs plugin fallback output), built server-side and translated for the EN interface mode.
* Uninstall already sweeps the new _kic_kicker / _kic_subtitle meta and the detection transient; kic-diagnostic.php file manifest updated for the 2.2.0 package.

= 2.1.0 =
* THE RELIABILITY RELEASE — a full audit of the publisher pipeline fixed every bug that could silently lose or misplace pasted content, plus the model-access path got its own repair pass.
* CRITICAL FIX — the publish button never sent the pasted content to the server (every publish attempt failed with "content is empty"); kic_publish_ready now receives the exact pasted article.
* CRITICAL FIX — appending to a Gutenberg post returned a "client insert" handshake nobody handled: the content was silently lost. Append/replace now write post_content server-side for every editor, so pasted content can never be dropped.
* CRITICAL FIX — "apply selection" for category/tag suggestions always failed (the term ids were serialized as a string, the server expected an array). Arrays now travel as term_ids[].
* CRITICAL FIX — bulk publish + drip never actually scheduled anything (the queue meta was written before the scheduler ran, which then bailed out); the drip checkbox also created ghost calendar items when the feature was disabled. Both fixed — drip slots are armed only when the feature is enabled, and posts are forced to draft so the schedule is meaningful.
* Publishing now honors title & status for EXISTING posts too (empty title = keep the current one, "publish immediately" actually publishes) and reports the real resulting status instead of echoing the request.
* NEW — focus keyword SEO field: optional focus keyword is written to Rank Math (rank_math_focus_keyword) and Yoast (_yoast_wpseo_focuskw) alongside the SEO title/description.
* NEW — internal-link suggestions are back in the widget (the advertised optional helper): request suggestions for your own published pages, tick the ones you like, and only then are <a> links wrapped around existing anchor text in the editor (Gutenberg store or TinyMCE write-back; unsaved until you save).
* NEW — per-provider "Test connection" button on every chain row (uses the stored key + first model), a "refresh" button that bypasses the /models cache, and negative model lists are only cached for 60 seconds.
* NEW — key-less local providers: Ollama / LM Studio / llama.cpp on localhost work with an empty API key (list models, suggestion calls and the connection test), with the Authorization header omitted instead of sent empty.
* FIXED — editing a chain provider wiped its base URL (only the host was prefilled), which silently removed it from service; the full URL is now prefilled and exported bundles no longer contain providers with empty base URLs.
* FIXED — the settings form could not be saved at all on a provider-less install (hidden "required" fields blocked every tab); required was removed and the Kubit default base URL preset was dropped — there is truly no default provider.
* FIXED — the wrong-key fallback silently tested a NEW provider with the OLD stored key (confusing 401s); the stored key is now reused only when the typed base URL matches the stored one.
* Publishing is no longer throttled by the AI rate limiter (it is not an AI action) and authors with edit_posts may publish; suggestion endpoints keep the AI capability + rate limit.
* RESTORED HONESTLY — the internal links suggestion UI lost in 2.0 is back; the prompt templates library, prompt skills, WooCommerce generator, translation module, image generation, streaming compose and the CSV generation queue are now REALLY deleted from the package (~2,400 lines of dead code and 10 dead files removed, including the leftover bulk-generation DB table creation).
* HARDENED — the JPEG quality filter only applies during plugin media imports (the whole-site compression is untouched), apply-terms checks edit_post capability, the Elementor data write is wp_slash()-safe, failed new-post publishes clean up the orphan draft, and uninstall now removes ALL options (including the encrypted provider chain), cron events and the activation fatal log.
* `wp kic tokens` reads the real usage shape again (it always printed zeros) and labels what the numbers mean in a pure publisher (optional suggestions only).
* v2.1.0 activation no longer creates the legacy bulk-generation table; uninstall still drops it on old installs.

= 2.0.0 =
* THE PUBLISHER RELEASE — Natilus 2.0 is a pure PUBLISHER, not a generator: paste your FINISHED article (HTML or plain text) and it is published EXACTLY as written — never regenerated, never rewritten, and it never asks how many words anything should be.
* Direct publish: the widget under the editor is now a pure publisher — one button publishes ready content (append / replace / new post with title, status, category and tags). Endpoint: kic_publish_ready. No generation, no rewriting, no word-count questions, no provider.
* No default provider & no setup nag: no provider is selected or assumed by default; publishing works with zero configuration from the moment of activation; the wizard remains an optional helper for the suggestion features only.
* Bulk publish: paste many finished articles at once (`---` separator, optional `# title` line) — replaces CSV bulk generation; optional drip scheduling feeds the existing publish calendar.
* REMOVED — generation surfaces: the prompt templates library, prompt skills, the WooCommerce product-description generator and the WPML/Polylang AI-translation module are deleted from the package, and the dormant generation AJAX endpoints (kic_compose, kic_compose_stream, kic_confirm, kic_regenerate, kic_generate_image, kic_translate) are no longer registered. Natilus offers nothing that writes or rewrites content.
* Kept: media import, galleries, featured image, native Elementor/WPBakery structures, Yoast & Rank Math SEO, JSON-LD, pre-publish checklist, snapshot/rollback, drip queue & calendar, reports, transfer settings, WP-CLI, bilingual UI — plus the strictly-optional, click-to-apply suggestion helpers (categories / English slug / internal links) for those who configure their own provider.
* kic-diagnostic.php updated: the file-integrity manifest and the minimum-version check now match the 2.0.0 package.

= 1.4.0 =
* NEW — "Publish" tab in the compose widget with three click-to-apply helpers: pre-publish checklist (read-only technical warnings: featured image, category, slug duplicates, SEO meta description, image alt texts, internal links — NOTHING is auto-fixed), category & tag suggestions (the model may ONLY pick from the site's EXISTING terms — ids are whitelisted server-side, new terms are impossible) and an English slug suggestion (sanitized through sanitize_title, uniqueness reported).
* NEW — "Reports & Health" screen: internal-link opportunity report (published posts with zero internal links) and the chain health history (failover events with error notes + self-heal markers, capped ring buffer).
* NEW — "Publish Calendar": month view of the drip queue with drag-and-drop rescheduling (keeps the time of day), per-item reorder arrows, pause/resume (paused queues re-arm cron events +6h so slots survive) and "publish the next item now".
* NEW — Operational transparency emails: weekly summary (posts published, token usage, cost, chain failures) and an unusual-usage alert when one day exceeds 3× the trailing 30-day daily average (5,000-token floor; at most one email/day, dismissible admin notice). Both togglable in settings.
* NEW — "Transfer settings" tab: portable JSON bundle of the provider chain + prompt skills + safe settings. Export downloads a JSON file; import appends providers/skills with fresh ids and merges whitelisted settings. API keys are EXCLUDED by default (an explicit, warned checkbox can include them).
* NEW — 3-step quick-setup wizard (preset → base URL/key/model → connection test + model listing), offered via an admin notice until a provider is configured.
* NEW — WP-CLI commands: wp kic queue (--action=list|run|pause|resume, --month=Y-m) and wp kic tokens (--days=N).
* NEW — Bilingual UI: an "Interface language" setting (Persian / English) switches the compose widget chrome, admin menu labels and the v1.4 screens to English; a languages/ .pot template is included so translators can add more languages (missing keys fall back to Persian — a partial translation can never blank a label).
* Renamed the plugin to "AI Content Publishing Agent" (slug, main file and text domain updated accordingly).
* Rebranded the plugin as "Natilus" — the WordPress display name, admin menu and UI labels now show Natilus (slug, folder, main file and text domain intentionally remain "ai-content-publishing-agent" so existing installs keep their settings on update).
* New read-only AJAX endpoints: kic_prepublish_check, kic_suggest_terms, kic_suggest_slug, kic_queue_data/move/reorder/pause/resume/run_next, kic_bundle_export/import, kic_wizard_finish. Only kic_apply_terms writes — strictly on an explicit user click (existing term ids / a sanitized slug).
* New settings: ui_lang, drip_paused, weekly_report_enabled, spike_alert_enabled. New admin pages: kic-calendar, kic-reports, kic-wizard.

= 1.3.0 =
* NEW — "Links & Titles" tab in the compose widget: suggestion-only internal links, RSS links and headlines. The AI never writes content on its own — every insertion requires an explicit user click.
* NEW — Internal link search: find any published post/page and insert it with one click; no AI involved.
* NEW — AI internal-link suggestions: the model ranks your own published posts against the current article; anchors are matched to text in the article; URLs are whitelisted to the real candidate list so invented links are impossible.
* NEW — RSS external/product links: configure up to 10 feeds (settings → Features); feed items are parsed (RSS 2.0/Atom, cached 30 minutes) and the model proposes only items from those feeds — useful for product and partner-site links.
* NEW — Headline suggestions: 5 alternative titles, one click to apply (Gutenberg store or the classic title field).
* NEW — Insertion safety: anchor wrapping never touches text already inside an existing link; bulk "insert selected" applies each checked suggestion; copy-HTML fallback for builder/editor contexts (e.g. Elementor panel).
* New AJAX endpoints: kic_search_posts, kic_suggest_links, kic_suggest_rss_links, kic_suggest_titles (all read-only — none of them modify post content).
* New setting: rss_feeds (textarea, one feed URL per line).

= 1.2.0 =
* NEW — Provider & model priority chain (up to 3 priority slots per provider, ordered providers): a failing model instantly shifts to the next model and a failing provider shifts to the next provider; recent failures are remembered for 10 minutes and deprioritised, then self-heal.
* NEW — Workspace engine row: choose provider + model per request (in addition to defaults), with a "load models" button that reads the provider's /models endpoint (cached 15 minutes).
* NEW — Practical token guidance: task-type estimates, per-model/action usage history (30 days), current limits display, auto-extend of the token cap for heavier tasks, and auto-continue when a response is cut off on the token limit — continuation requests run automatically and pieces are stitched together (cap configurable, default 3).
* NEW — Prompt skills: save the current compose configuration (prompt, tone, language, content type, builder mode, provider, model, target) as a one-click skill; run it again later from the workspace Skills tab or the Skills admin screen; run counters included.
* NEW — Builder modes: Classic editor / Gutenberg / Elementor / WPBakery with automatic detection. Elementor mode converts generated HTML into a native Elementor document (section > column > heading/text-editor/image widgets written to _elementor_data); WPBakery mode converts it to native VC shortcodes — no "do it in Elementor" instructions needed.
* NEW — Elementor live panel: when the Elementor editor is open, the compose widget is injected as a floating panel next to the live preview; confirming refreshes the preview in place instead of reloading the editor.
* NEW settings tab for the model chain & tokens: chain management UI (add/edit/delete/reorder providers, per-provider model slots), default builder mode, token guidance options and reference tables.
* New AJAX endpoints: kic_get_providers, kic_save_provider, kic_delete_provider, kic_reorder_providers, kic_list_provider_models, kic_token_guide, kic_get_skills, kic_save_skill, kic_delete_skill, kic_skill_used.
* Legacy primary/failover settings migrate into the chain automatically on upgrade; the old pair keeps working when no chain exists.
* Fixed a chain-storage bug where re-reading a saved provider re-encrypted its already-encrypted API key.

= 1.1.3 =
* CRITICAL FIX: "Class declarations may not be nested" fatal during plugin load. The Elementor widget class was declared inside a method, which PHP rejects outright with an uncatchable compile error — this killed every activation on every install (white screen / "critical error"). The widget now lives in its own file (includes/class-kic-content-widget.php) loaded only when Elementor is present.
* Fixed settings-page asset enqueue guard: kic-settings.js (tab switching, test-connection button, preset auto-fill) never loaded because the guard matched a wrong hook name.
* kic-diagnostic.php: fixed a fatal "$kic_diag_add is not callable" typo so the standalone diagnostic tool now runs to completion.

= 1.1.2 =
* Activation fatal-error capture: any fatal during activation is now printed on screen (even with display_errors off), stored as an option and written to wp-content/uploads/kic-activation-error.log, and shown in a dashboard notice — no more silent blank pages.
* Module include loop is now parse-error tolerant (ParseError caught per file) on PHP 7+.
* Standalone kic-diagnostic.php tool: upload to the WordPress root to check PHP/WP versions, extensions, zip support, folder writability, full plugin file integrity, duplicate plugin folders, BOM and the last stored activation error — without loading WordPress.

= 1.1.1 =
* Lowered requirements: WordPress 5.8+ and PHP 7.0+ (fixes "Current PHP version does not meet minimum requirements" blocking activation on PHP 7.2/7.3 hosts).
* Fault-tolerant activation: table creation, capabilities, settings and prompt seeds can no longer abort activation with a fatal error; issues surface as a one-time admin notice instead.
* Missing or corrupted module files are skipped with a diagnostic instead of crashing the whole plugin.
* Guarded bootstrap functions and the KIC_Plugin class against double-declaration when the folder is copied twice.

= 1.1.0 =
* Smart model routing (light tasks on a cheaper model).
* Automatic provider failover with a backup endpoint.
* Monthly budget cap with email alerts and hard stop.
* Drip publishing for bulk-generated drafts via WP-Cron.

= 1.0.0 =
* Initial release.
