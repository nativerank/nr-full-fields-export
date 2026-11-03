# NR Full Fields Export (repo: nr-full-fields-export) — Project Summary

## What this is

A WordPress plugin that exports all page data (H1, title tag, meta description,
site structure/parent-child relationships, and custom fields) to a CSV, with a
single "Run Export" button in wp-admin — no terminal/WP-CLI step required.

It's a streamlined fork of an existing plugin, `nr-heavy-seo-export`, which
lives right next to it at `wp-content/plugins/nr-heavy-seo-export/`. That
plugin's "Run Export" button was effectively non-functional from a browser —
it only worked when PHP was already running inside a WP-CLI process, so
clicking it in wp-admin just produced an error. This plugin fixes that while
keeping behavior otherwise identical.

There's also a third, related plugin: `wp-content/plugins/nativerank-seo-wp/`.
That one is the real "source of truth" for the `attr:`/`cf:` custom-field
conventions used here — see `src/inc/admin/importer.php`, `cli.php`, and
`exporter.php` in that plugin. It handles CSV-driven page import/update across
NativeRank sites and is where the `attr:`/`cf:` prefixes originate.

## Mechanics

**Admin UI:** wp-admin → "NR Full Export" menu (requires `manage_options`).
Two buttons, each posting to `admin-post.php` (not back to the page itself),
so there's a clean POST → process → redirect cycle instead of "refresh to
retry":
- `action=nr_full_run_export` — runs the export in-process (PHP function
  call, no shell-out), writes the CSV, redirects back with `?nr_full_export=done`.
- `action=nr_full_purge_export` — deletes all files in the exports folder,
  redirects back with the deleted count.

Both handlers check `current_user_can('manage_options')` and a nonce
(`check_admin_referer`) before doing anything.

**Data source:** every WordPress page via `get_pages()`. Per page:
- **Fixed columns** (always present): `id`, `label`, `h1` (meta key `NR - H1`),
  `title_tag` (`_yoast_wpseo_title`), `meta_description`
  (`_yoast_wpseo_metadesc`), `parent_page`, `slug`, `content`, `template`,
  `page_type` (`nr_page_type`), `featured_image`, `body_images` (an ACF
  repeater, collated into a single `|`-delimited string of image IDs via
  `get_field('body_images', ...)`).
- **Dynamic custom-field columns** — intentionally a narrow allow-list, not a
  blanket scan of `wp_postmeta` (a blanket scan was tried and reverted — it
  pulled in noise like ACF repeater row internals, e.g.
  `body_images_0_images`, and unrelated meta from another plugin prefixed
  `mm.`). The allow-list exactly mirrors `nativerank-seo-wp`'s own exporter:
  - Any meta key matching `attr:%` (direct SQL `LIKE` on `wp_postmeta`). Title
    and value lookup both use the full `attr:xxx` key as-is.
  - Any entry in the option `nativerank_seo_1055__cf_fields`. This option is
    populated by `nativerank-seo-wp`'s CSV importer whenever it sees a column
    named `cf:xxx` — it stores `cf:xxx => xxx` (prefixed key → clean key),
    because the actual postmeta is saved under the **clean** key (`xxx`), with
    the `cf:` prefix stripped before saving. The export title uses the
    **prefixed** form (`cf:xxx`) — same as the original — specifically so it
    doesn't visually collide with some other, unrelated field that happens to
    share the same clean meta key.
  - **This means a custom field added directly in the page editor (not via
    `attr:`/`cf:` CSV import) will NOT show up in the export.** That was a
    deliberate reversion after testing on a fuller site — the dynamic/"grab
    everything" approach caught editor-added fields but was too noisy. If this
    becomes a problem again, the dynamic-discovery version (with filters for
    underscore-prefixed keys, ACF repeater row patterns `/_\d+_/`, and `mm.`
    noise) is in this file's git history — see commit history / ask to
    resurrect it.
- Output rows are sorted **numerically ascending by page ID** (column A)
  before the CSV is written.

**CSV file:** written to `wp-content/uploads/nr-full-exports/`, named
`seo-export-{m_d_Y_h-i-s_a}.csv` — uses dashes, not colons, in the timestamp
(colons are invalid in Windows filenames; the original plugin's format broke
on this local Windows dev environment).

**WP-CLI:** `wp nr-full-export-all` still works and shares the exact same
underlying `run_nr_full_export()` function as the admin button.

**Global/class names** (`NR_FULL_EXPORTER`, `NR_FULL_EXPORT_FOLDER`,
`NR_FULL_EXPORT_URL`, the `nr-full-export-all` WP-CLI command, the
`nr-full-exports` uploads subfolder, etc.) were all deliberately renamed from
the original plugin's so this one can be active side-by-side with
`nr-heavy-seo-export` without PHP fatal errors from duplicate
class/constant/command declarations.

## Auto-updates

Uses the bundled `YahnisElsts/plugin-update-checker` library
(`lib/plugin-update-checker/`), wired up in
`nr-full-fields-export.php`:

```php
\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/NativeRank/nr-full-fields-export/',
    __FILE__,
    'nr-full-fields-export'
);
```

Note: the plugin folder, main file, update-checker URL, and update-checker
slug all use the `nr-full-fields-export` name (matching the
GitHub repo, which is what the update checker and WordPress's plugin-update
machinery key off of). Internal PHP identifiers — the `NR_FULL_EXPORTER`
class, `NR_FULL_EXPORT_FOLDER`/`NR_FULL_EXPORT_URL` constants, `nr_full_*`
function and action names, the `nr-full-export-all` WP-CLI command, and the
`nr-full-exports` uploads subfolder — were deliberately **left as `nr_full_*`**
(shorter, and already proven not to collide with `nr-heavy-seo-export`'s
names). Don't "fix" this mismatch — it's intentional.

This checks **GitHub Releases** directly — no self-hosted update server
(unlike `nr-heavy-seo-export`/`nr-financing-calculator`, which use
NativeRank's own `wp-plugins.nativerank.com` wp-update-server). Decision
history:
- A self-hosted-server approach (SFTP-deploy-on-release, mirroring
  `nr-financing-calculator`'s `.github/workflows/release.yml`) was tried and
  reverted: the `WP_PLUGINS_SERVER_IP`/`WP_PLUGINS_SSH_PRIVATE_KEY`/
  `WP_PLUGINS_REMOTE_PATH` secrets those other repos rely on turned out to
  be scoped in a way that didn't reach this repo (confirmed by an actual
  failed deploy run — "Input required and not supplied: server"), and fixing
  that requires an org owner, which wasn't available. Rather than block on
  that, or duplicate those secrets at the repo level pointed at
  infrastructure with no independent destination server to deploy to, the
  plugin reverted to checking GitHub Releases directly.
- Considered a private repo, but that would require every WordPress install
  to have a GitHub access token configured (in `wp-config.php`) just to check
  for updates — and since the plugin has no secrets to protect, and the
  token would either need baking into the distributed plugin file (readable
  by anyone with file access on any site running it) or manual per-site
  wp-config edits (ongoing busywork across every current and future site),
  neither trade-off was worth it. **Decision: public repo**, so no token is
  needed anywhere.

## Current state / what's left to do

The GitHub repo is named `NativeRank/nr-full-fields-export` (public), and the
plugin folder/main file were renamed to match (it went through an
intermediate name, `nr-site-structure-and-fields-export`, before settling
here — shortened because that name was too long). The original working
title before any of this was `nr-full-export`.

Rename and push are done (commit `07b5542`, pushed to `origin/main`;
`origin` now also points at the renamed `nativerank/nr-full-fields-export`
GitHub repo). A `v1.0` GitHub Release has been published. Remaining steps:

1. **Verify end-to-end** on a real WordPress install: confirm wp-admin →
   Dashboard → Updates (or the Plugins list) actually shows an "update
   available" notice once a newer release is published. This has not been
   tested live yet. Also confirm the plugin is still active after the
   on-disk folder rename (WordPress tracks active plugins by
   `folder/file.php` path, so a rename can deactivate it — reactivate from
   wp-admin → Plugins if needed).
2. For every future change: bump the `Version:` header, commit, push,
   publish a new GitHub Release with a matching tag (e.g. `v1.1`).

**Manual distribution zip:** a copy is also kept at
`D:\Tools\plugins\nr-full-fields-export.zip` (built via `git archive`, so it
excludes `.git/`/`.idea/` and mirrors exactly what's pushed to GitHub) for
manual installs outside the update-checker/Releases flow. Regenerate it after
any change that should be manually distributable.

## Key file locations

- This plugin: `wp-content/plugins/nr-full-fields-export/nr-full-fields-export.php`
- Original (terminal-only) plugin: `wp-content/plugins/nr-heavy-seo-export/nr-heavy-seo-export.php`
- Source of truth for `attr:`/`cf:` conventions: `wp-content/plugins/nativerank-seo-wp/src/inc/admin/{exporter.php,importer.php,cli.php}`
- Local WP install root: `C:\Users\Web\Documents\WebstormProjects\rmpb_full_stage`
