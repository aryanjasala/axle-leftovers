# Reference

## Item types

| Type | Name | Move to trash | Restore |
| --- | --- | --- | --- |
| `table` | Full table name, with the prefix | SQL export (up to 200 MB), then `RENAME TABLE` to `{prefix}axlt_<id>` | Renamed back; refused when the name is taken |
| `option` | Option name; transients as `_transient_<name>` | Row exported (with a transient's timeout row), then deleted | Re-inserted with the same value and autoload |
| `cron` | Hook | Every event (time, arguments, schedule, interval) exported, then unscheduled | Rescheduled; a past next run becomes now plus the interval |
| `postmeta`, `usermeta`, `termmeta`, `commentmeta` | Meta key | Rows copied to a JSON-lines export in batches of 1,000, then deleted in batches | Re-inserted in batches with their original IDs; refused when rows with the key exist again |
| `folder` | Top-level uploads folder | File list exported, folder renamed into `uploads/axle-leftovers/trash/<id>/` | Moved back |
| `role` | Role | Definition exported, role removed; refused while users have it | Re-added |
| `cap` | Capability on roles | Roles and grants exported, capability removed from each | Re-added |
| `sitemeta` | Network option (multisite) | Like options | Like options |
| `template` | Theme folder name; every user template and template part tagged with the theme in `wp_theme` | Posts, their revisions, meta and term relationships exported to JSON lines, then deleted in batches | Re-inserted with their IDs, meta IDs and relationships; refused when any post ID exists again |
| `globalstyles` | Theme folder name; the global styles entry of the theme | Like `template` | Like `template` |
| `customcss` | Theme folder name; the Additional CSS post of the theme | Like `template` | Like `template` |
| `posttype` | Post type; every post of it in every status | Posts, their revisions, meta, comments, comment meta and term relationships exported to JSON lines, then deleted in batches | Re-inserted with their IDs, meta IDs and comment IDs; refused when any post ID exists again |
| `taxonomy` | Taxonomy; every term of it | Term taxonomy rows, their terms (a term row shared with another taxonomy stays), term meta and the relationships that point at them, exported then deleted in batches | Re-inserted with their IDs; refused when a term taxonomy ID is taken or a term ID holds another term; a link to a post that is gone is not restored |

Theme settings are the option `theme_mods_<folder>`. They are core's own while the theme is installed, and a leftover once it is not. Theme content is named by the theme folder and counts the posts behind it. A name that active code mentions does not block theme content, because the folder name appears in code for other reasons.

### Post types and taxonomies

Registration happens on `init`, and some types register only in wp-admin, in REST, in cron or on some pages, so one look at the registered types proves nothing. The observer notes, at the end of each request, which post types and taxonomies were registered and which kind of request it was (front, admin, rest, cron, ajax, cli), in one small autoloaded option `axle_leftovers_observed` that is written at most once an hour per type. A type is listed as unregistered when all of this is true:

- Nothing registers it on the request that runs the scan.
- Nothing registered it on any watched request in the last N days (7 by default, `observe_days` in Settings, or the `axle_leftovers_observe_days` filter). The evidence says "not registered in the last 7 days", never "never".
- Every watched kind of request (front and admin by default, `axle_leftovers_observe_kinds`) has been seen for the whole N days.

Until then a type is not listed. The screen says how many are being watched and when each can be listed (`wp axle-leftovers observe` shows the same). Core's post types and taxonomies, theme templates, global styles and custom CSS (their own item types), and any type an installed plugin or theme may own are never listed. The owner comes from the same evidence as other items: the type seen being registered by a plugin's or theme's code (Certain), or `register_post_type()` and `register_taxonomy()` names in a code signature (Likely).

Posts and terms need their name typed to move, whatever the evidence says, and the scan result is checked again against the live site right before the move. The reference check looks for a `register_post_type( 'name'` or `register_taxonomy( 'name'` call in active code, because a name such as `event` is quoted in half the plugins. A web request moves at most 100,000 rows per item (`axle_leftovers_content_max_rows`); use WP-CLI for more. Attachments whose parent is a post of the type are not moved: media stays.

Each item shows counts per status, the date range, how many meta rows, comments and term relationships come with the posts, and a few example titles (`axle_leftovers_sample_size`, `axle_leftovers_content_sample`).

Names only ever come from the scan results, are checked against the live site, and reach SQL through `%i` placeholders. Folders are confined to the uploads folder and links are never followed.

## Attribution

| Confidence | Source | Evidence shown |
| --- | --- | --- |
| Certain | A theme seen being deleted: its settings and content ("Left by the theme Old Theme, which was deleted"). First-seen ledger: snapshots around activation, runtime hooks (`added_option`, `dbdelta_create_queries`, `query`, `schedule_event`, role changes, `added_{type}_meta`) with a backtrace for new names only | "Created when Acme was activated on 2025-04-02", "Seen being created by Acme on ..." |
| Likely | A theme folder that is not installed and was never seen being deleted ("Named after the theme x, which is not installed"). The plugin's or theme's code signature in the ledger, or the bundled dataset | "Named in Acme's code", "Named in Acme's uninstall.php", "Starts with acme_, a prefix in Acme's code", "Dataset match: ..." |
| Guess | The name starts like a known slug (`contact_form_7_`, initials such as `cf_`) | "Prefix guess: starts with ..." |
| Unknown | Nothing | |

An item that any installed plugin (active or not), must-use plugin or theme may own is in use and never listed. Themes count as installed when they sit in the themes folder, even with a broken `style.css`, and the active theme and its parent always count.

### Themes

Leftovers reads a theme's PHP at every switch (the theme left and the theme switched to), before a delete, and in the scan, with the same `Signature` scanner it uses for plugins. Themes sit in the ledger as `theme:<folder>`, with the code signature kept after the files are gone. Options, transients, cron events and meta keys the theme's code named are attributed like a plugin's, and the runtime ledger records new ones created by theme code as Certain. A theme removed outside WordPress is recorded when the folder is found missing.

Widgets in `wp_inactive_widgets` or an `orphaned_widgets_*` sidebar are counted and shown as a note. They are never listed as items and never moved. A cron hook with a callback registered right now is in use too. Guess and Unknown items need their names typed to move.

The reference check searches the PHP of active plugins, the active theme and its parent, must-use plugins and drop-ins for the name as a quoted literal (and `}name` for tables). A hit blocks the trash and names the file.

### The bundled list

`data/signatures.json` holds the names and prefixes of about 500 of the most installed WordPress.org plugins, scanned with the same `Signature` class the plugin runs on installed plugins. Rebuild it at release time:

```sh
php bin/build-signatures.php --count=500 --cache=/tmp/axle-leftovers-zips
```

It downloads each plugin's latest zip once into the cache folder and keeps the file under 1 MB (dropping uninstall names, then meta keys, then the least installed plugins when it has to). Nothing is fetched on a site.

### The full list (opt-in)

About 10,000 plugins, built on GitHub and downloaded on request. The `Signatures` workflow (`.github/workflows/signatures.yml`, monthly and on demand) runs `bin/build-signatures.php --publish-dir=...` and commits the result to the orphan branch `signatures`: `signatures.json.gz`, `signatures.json.gz.sha256` and `state.json` (slug to version). A run reads the last `state.json` and signatures and downloads only plugins whose version changed or that are new. Each zip is scanned and deleted at once. A plugin that fails to download keeps its old data and is retried next run. `--max-minutes` stops starting downloads after a while and the next run carries on.

On a site, Settings > Signature list (or `wp axle-leftovers dataset download`) fetches the file from `raw.githubusercontent.com`, rejects it unless it is under the size limit, matches the checksum file, unpacks, and has the shape `{ version: 1, built, plugins: { slug: { name, t, o, c, m, r, u, p, n } } }`, then stores it in `uploads/axle-leftovers/dataset/`. It is merged with the bundled list: the downloaded entry wins for a slug in both, the bundled list fills the rest. Delete it (Settings > Delete downloaded list, `wp axle-leftovers dataset delete`, or the `axle-leftovers/delete-dataset` ability) to go back to the bundled list. A downloaded list stays in use when the setting is turned off, with no network calls. Both the setting and the download are off by default.

### The online lookup (opt-in)

For an Unknown item (or a Guess), Look it up sends one private search with the item's name to Veloria (veloria.dev, formerly wpdirectory.net), which searches the PHP of every plugin on WordPress.org. Exactly one matching plugin gives a Likely owner; two up to 20 give a Guess for the most installed plugin, with all candidates shown; none, or more than 20, gives no owner. Every result is cached for 30 days, lookups are 30 seconds apart (Veloria allows 10 requests a minute), and only names the scan listed can be looked up. It runs from the screen, WP-CLI or the ability, never on a page load.

## Hooks

### Filters

| Filter | Arguments | Default |
| --- | --- | --- |
| `axle_leftovers_capability` | `string $capability, bool $network` | `manage_options`, `manage_network` for network items |
| `axle_leftovers_core_items` | `array $items` | type => core names never listed |
| `axle_leftovers_protected_items` | `array $items` | the plugin's own tables, options, cron hooks and folder |
| `axle_leftovers_item_types` | `array $types` | type => label; remove a type to skip it |
| `axle_leftovers_protected_themes` | `string[] $stylesheets` | installed theme folders, the active theme and its parent; add folders to treat more themes as installed |
| `axle_leftovers_item_capability` | `string $cap, string $type` | `edit_theme_options` for `template` and `globalstyles`, `edit_css` for `customcss`, `promote_users` for `role` and `cap`, `edit_users` for `usermeta`, `delete_others_posts` for `posttype`, `manage_categories` for `taxonomy`, otherwise the plugin's capability; checked on top of it |
| `axle_leftovers_post_batch_size` | `int $size` | 500 (10 to 5000) posts or terms per query for theme, post type and taxonomy content |
| `axle_leftovers_observe_days` | `int $days` | 7 (1 to 365), from Settings: how long a post type or taxonomy must go unregistered |
| `axle_leftovers_observe_kinds` | `string[] $kinds` | `front`, `admin`; kinds of request that must have been watched the whole period (also `rest`, `cron`, `ajax`, `cli`) |
| `axle_leftovers_request_kind` | `string $kind` | the kind Leftovers files the current request under |
| `axle_leftovers_content_max_rows` | `int $rows` | 100000 (1000 and up, 0 for no limit): most rows one item may hold to move in a web request |
| `axle_leftovers_sample_size` | `int $size` | 5 (1 to 20) example titles per item |
| `axle_leftovers_content_sample` | `array $sample, string $type, string $name` | titles, dates (GMT) and statuses shown for a post type or taxonomy |
| `axle_leftovers_content_health_threshold` | `int $rows` | 1: posts and terms of unregistered types that make the Site Health test recommended |
| `axle_leftovers_owner` | `array $result, string $type, string $name` | owner, owner_name, confidence, evidence, in_use, in_use_by |
| `axle_leftovers_confidence_levels` | `array $levels` | certain, likely, guess, unknown labels |
| `axle_leftovers_signature` | `array $signature, string $slug, string $version` | names the plugin's or theme's code uses (`$slug` is `theme:<folder>` for a theme) |
| `axle_leftovers_dataset` | `array $plugins` | the bundled list merged with the downloaded full list |
| `axle_leftovers_lookup_enabled` | `bool $enabled` | true; the setting must be on too, so a filter can only turn the lookup off |
| `axle_leftovers_lookup_endpoint` | `string $endpoint` | `https://veloria.dev/api/v1/search/` (HTTPS only) |
| `axle_leftovers_lookup_term` | `string $term, string $type, string $name` | the escaped item name; the only thing a lookup sends |
| `axle_leftovers_lookup_min_interval` | `int $seconds` | 30 (1 to 3600) |
| `axle_leftovers_lookup_cache_days` | `int $days` | 30 (1 to 365) |
| `axle_leftovers_lookup_max_candidates` | `int $max` | 5 (1 to 20) |
| `axle_leftovers_lookup_max_matches` | `int $max` | 20 (1 to 200); a name in more plugins has no owner |
| `axle_leftovers_lookup_polls` | `int $polls` | 4 (1 to 10), each counts toward the service limit |
| `axle_leftovers_lookup_poll_interval` | `int $seconds` | 2 (0 to 10) |
| `axle_leftovers_dataset_download_enabled` | `bool $enabled` | true; the setting must be on too |
| `axle_leftovers_dataset_url` | `string $url` | `https://raw.githubusercontent.com/aryanjasala/axle-leftovers/signatures/signatures.json.gz` (HTTPS only; the checksum is this plus `.sha256`) |
| `axle_leftovers_dataset_max_bytes` | `int $bytes` | 15 MB (100 KB to 100 MB) |
| `axle_leftovers_scan_batch_size` | `int $rows` | 20000 (100 to 1000000) |
| `axle_leftovers_meta_batch_size` | `int $rows` | 1000 (10 to 10000) |
| `axle_leftovers_export_max_bytes` | `int $bytes` | 200 MB, from Settings |
| `axle_leftovers_keep_exports` | `bool $keep` | false, from Settings |
| `axle_leftovers_retention_days` | `int $days` | 30 (1 to 3650), from Settings |
| `axle_leftovers_reference_check_paths` | `string[] $paths` | active plugins, theme, mu-plugins, drop-ins |
| `axle_leftovers_can_trash` | `bool\|WP_Error $allowed, array $item` | true; runs after the safety checks, which still apply |
| `axle_leftovers_require_typed_confirmation` | `bool $required, string $confidence` | true for guess and unknown (cannot be turned off for those); posts and terms always need it, whatever this returns |
| `axle_leftovers_uninstall_trash_policy` | `string $policy` | `purge`, or `restore` |
| `axle_leftovers_notice_message` | `string $message, string $slug, array $counts` | "Acme left 2 tables and 12 options." or "Old Theme left 1 option and 14 template changes." (`$slug` is `theme:<folder>` for a theme) |
| `axle_leftovers_track_meta_keys` | `bool $track, string $meta_type` | true, from Settings |
| `axle_leftovers_messages` | `array $messages` | every refusal and error; a template must keep its placeholders |

Example: never touch anything named `legacy_*`.

```php
add_filter( 'axle_leftovers_can_trash', function ( $allowed, $item ) {
	return 0 === strpos( $item['name'], 'legacy_' ) ? new WP_Error( 'keep', 'Legacy data stays.' ) : $allowed;
}, 10, 2 );
```

### Actions

| Action | Arguments |
| --- | --- |
| `axle_leftovers_scan_started` | `bool $rescan_signatures` |
| `axle_leftovers_scan_finished` | `array[] $items` |
| `axle_leftovers_plugin_recorded` | `array $entry, bool $is_new` |
| `axle_leftovers_plugin_removed` | `array $entry, string $how` (admin, cli, outside); also for themes, with `theme:<folder>` as the slug |
| `axle_leftovers_theme_switched` | `string $new, string $old` (theme folders) |
| `axle_leftovers_theme_removed` | `string $stylesheet, array\|null $entry` (a theme deleted from wp-admin or WP-CLI) |
| `axle_leftovers_settings_saved` | `array $new, array $old` |
| `axle_leftovers_observation_recorded` | `string $kind, array $state` (the observation record was written: a first sighting, or a refresh after an hour) |
| `axle_leftovers_item_first_seen` | `string $type, string $name, string $owner, string $evidence` |
| `axle_leftovers_exported` | `string $batch, string $file, array $item` |
| `axle_leftovers_before_trash` | `array[] $items, string $batch` |
| `axle_leftovers_trashed` | `string $batch, array[] $rows` |
| `axle_leftovers_trash_failed` | `array $item, WP_Error $error, string $batch` (the batch was rolled back) |
| `axle_leftovers_before_restore` | `array[] $rows, string $batch` |
| `axle_leftovers_restored` | `array[] $rows, string $batch` |
| `axle_leftovers_restore_failed` | `array $row, WP_Error $error` |
| `axle_leftovers_purged` | `array[] $rows` |
| `axle_leftovers_lookup_done` | `array $result, string $type, string $name` (also when nothing matched) |
| `axle_leftovers_dataset_downloaded` | `array $meta` (built, count, fetched_gmt, size, sha256) |
| `axle_leftovers_dataset_removed` | (the downloaded list was deleted) |

Example: log every trash in your own audit log.

```php
add_action( 'axle_leftovers_trashed', function ( $batch, $rows ) {
	my_audit_log( 'Leftovers batch ' . $batch, wp_list_pluck( $rows, 'name' ) );
}, 10, 2 );
```

### JavaScript (`wp.hooks`)

| Hook | Type | Arguments |
| --- | --- | --- |
| `axleLeftovers.confirmMessage` | filter | `message, { items }` |
| `axleLeftovers.trashed` | action | `{ batch, items }` |
| `axleLeftovers.restored` | action | `{ restored, failed }` |

```js
wp.hooks.addFilter( 'axleLeftovers.confirmMessage', 'my-plugin', ( message, { items } ) => `${ message }\n\nTake a backup first?` );
```

### For other plugins

```php
$who = \AryanX\Axle\Leftovers\owner_of( 'option', 'acme_settings' );
// array( 'owner' => 'acme', 'owner_name' => 'Acme', 'confidence' => 'likely', 'evidence' => array( ... ), 'in_use' => false, 'in_use_by' => '' )
```

## Abilities (WordPress 6.9+)

| Ability | Annotations | Input |
| --- | --- | --- |
| `axle-leftovers/list-leftovers` | readonly | `plugin`, `theme`, `kind` (plugin, theme, mu-plugin), `type`, `confidence` |
| `axle-leftovers/get-item` | readonly | `type`, `name`; for `posttype` and `taxonomy` the result has a `summary` with status counts, dates, meta, comments, relationships and example titles |
| `axle-leftovers/list-trash` | readonly | |
| `axle-leftovers/trash-items` | destructive | `items` (type, name), `confirm_names` |
| `axle-leftovers/restore-items` | idempotent, not destructive | `batch` or `items` (trash IDs) |
| `axle-leftovers/purge-trash` | destructive, idempotent | `older_than`, `batch` |
| `axle-leftovers/rescan` | not readonly | `rescan_signatures` |
| `axle-leftovers/lookup-item` | not readonly, idempotent | `type`, `name`, `refresh` |
| `axle-leftovers/get-observation` | readonly | |
| `axle-leftovers/get-settings` | readonly | |
| `axle-leftovers/update-settings` | not readonly, idempotent | any of the settings |
| `axle-leftovers/download-dataset` | not readonly, idempotent | |
| `axle-leftovers/delete-dataset` | destructive, idempotent | |

Permission: the `axle_leftovers_capability` capability, plus the capability of each item's type (see `axle_leftovers_item_capability`) and the network capability for network items. A user without them does not see those items and cannot trash or restore them. Items carry `owner_kind` (plugin, theme or mu-plugin).

## REST

`axle-leftovers/v1`: `GET /items` (`plugin`, `theme`, `kind`, `type`, `confidence`), `GET /trash`, `POST /trash` (`items`, `confirm_names`), `DELETE /trash` (`older_than`, `batch`), `POST /restore` (`batch`, `items`), `POST /scan`, `GET /content` (`type`, `name`: one post type or taxonomy item with its summary), `GET /observation`, `GET /notice`. Same capability.

## WP-CLI

| Command | What it does |
| --- | --- |
| `wp axle-leftovers list [--plugin=] [--theme=] [--kind=] [--type=] [--confidence=] [--format=]` | Listed leftovers (`--type=posttype` or `--type=taxonomy` for content); `--plugin=` (empty) for unknown items, `--theme=<folder>` for one deleted theme, `--kind=plugin\|theme` |
| `wp axle-leftovers show <type> <name> [--format=]` | One item with its evidence |
| `wp axle-leftovers observe [<type> <name>] [--format=]` | Which kinds of request were watched, since when, and when the first unregistered type can be listed; with a type and a name, where that one stands (registered, recent, watching, unregistered) |
| `wp axle-leftovers scan [--rescan-signatures]` | Scan now |
| `wp axle-leftovers export <type> <name>... [--output=]` | Export without trashing |
| `wp axle-leftovers trash <type> <name>... [--plugin=<slug>] [--theme=<folder>] [--yes]` | Move to the trash; typed names confirm Guess and Unknown items; `--theme=` moves everything a deleted theme left |
| `wp axle-leftovers restore <batch-id\|item-id>` | Restore a batch or one item |
| `wp axle-leftovers trash list [--format=]` | The trash |
| `wp axle-leftovers trash purge [--older-than=<days>] [--yes]` | Delete for good |
| `wp axle-leftovers ledger list [--format=]` / `ledger show <slug>` | Plugins seen |
| `wp axle-leftovers lookup <type> <name> [--refresh] [--format=]` | Online lookup of an Unknown item (needs the setting) |
| `wp axle-leftovers settings [list\|get <key>\|set <key> <value>]` | The settings |
| `wp axle-leftovers dataset download\|status\|delete` | The full signature list (download needs the setting) |

## Site Health

- Recommended: options left by deleted plugins add more than 100 KB of autoloaded data to every request.
- Recommended: posts or terms belong to post types and taxonomies nothing registers any more.

## Data and privacy

- Table `{prefix}axle_leftovers_ledger`: plugins and themes (as `theme:<folder>`) seen, their version, removal, sources and code signature.
- Table `{prefix}axle_leftovers_seen`: item type, name, owner, first seen, evidence. One row per distinct name.
- Table `{prefix}axle_leftovers_trash`: one row per trashed item: batch, type, name, owner, confidence, payload, locations, size, export, user, dates, status.
- Option `axle_leftovers_observed`: when each kind of request was first and last seen, and when each post type and taxonomy was last seen registered (autoloaded, a few KB).
- Options `axle_leftovers_settings`, `axle_leftovers_scan` (cached results, not autoloaded), `axle_leftovers_lookups` (cached online lookups, not autoloaded), `axle_leftovers_dataset_meta` (date and count of the downloaded list, not autoloaded), `axle_leftovers_db_version`.
- User meta `axle_leftovers_steps_dismissed`: set when a user hides the Get started card. It holds no personal data and is removed on uninstall.
- Folder `uploads/axle-leftovers/` with `exports/`, `trash/` and `dataset/`, with deny rules.
- Cron `axle_leftovers_daily` (purge and rescan) and `axle_leftovers_scan_step` (background scan steps).

Deleting Leftovers while the trash holds items deletes them for good, so the Plugins screen shows a warning under its row while the trash is not empty.

The privacy exporter lists the items a user trashed, and trashed post type batches that hold posts they wrote or comments they left (matched by user ID, or by a one-way hash of the comment email stored in the trash row). The eraser purges trashed batches that hold the user's meta, posts or comments, and removes the user from trash records. Uninstall purges the trash (or restores it, with the filter), then removes the tables, options, cron events, transients and folder.

## Limits in 1.0

- Theme content restore refuses when a post ID exists again, and does not merge with edits made to the same theme since.
- Attachments whose parent is a post of a removed post type are not moved, and keep a parent ID that points at nothing.
- A post type that registers only on a request nobody makes (a rare admin screen) can be listed by mistake after the watch period; the live check and the typed name are the safety net, and the export makes it undoable.
- A term link to a post that is gone, or to a term of an unregistered taxonomy that is gone, is not restored.
- Moving a very large item in a web request is refused above the row limit; WP-CLI has none, and runs the same batches.
- Columns plugins added to core tables are not listed.
- A capability granted to single users (not through a role) is not removed.
- The dataset lists every name a plugin's code mentions, including names it reads from other plugins (importers), so a dataset match is Likely, not Certain.
- Folder moves need the trash folder on the same file system as uploads.
