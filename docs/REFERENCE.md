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

Names only ever come from the scan results, are checked against the live site, and reach SQL through `%i` placeholders. Folders are confined to the uploads folder and links are never followed.

## Attribution

| Confidence | Source | Evidence shown |
| --- | --- | --- |
| Certain | First-seen ledger: snapshots around activation, runtime hooks (`added_option`, `dbdelta_create_queries`, `query`, `schedule_event`, role changes, `added_{type}_meta`) with a backtrace for new names only | "Created when Acme was activated on 2025-04-02", "Seen being created by Acme on ..." |
| Likely | The plugin's code signature in the ledger, or the bundled dataset | "Named in Acme's code", "Named in Acme's uninstall.php", "Starts with acme_, a prefix in Acme's code", "Dataset match: ..." |
| Guess | The name starts like a known slug (`contact_form_7_`, initials such as `cf_`) | "Prefix guess: starts with ..." |
| Unknown | Nothing | |

An item that any installed plugin (active or not), must-use plugin or theme may own is in use and never listed. A cron hook with a callback registered right now is in use too. Guess and Unknown items need their names typed to move.

The reference check searches the PHP of active plugins, the active theme and its parent, must-use plugins and drop-ins for the name as a quoted literal (and `}name` for tables). A hit blocks the trash and names the file.

### The bundled list

`data/signatures.json` holds the names and prefixes of about 500 of the most installed WordPress.org plugins, scanned with the same `Signature` class the plugin runs on installed plugins. Rebuild it at release time:

```sh
php bin/build-signatures.php --count=500 --cache=/tmp/axle-leftovers-zips
```

It downloads each plugin's latest zip once into the cache folder and keeps the file under 1 MB (dropping uninstall names, then meta keys, then the least installed plugins when it has to). Nothing is fetched on a site.

### The full list (opt-in)

About 10,000 plugins, built on GitHub and downloaded on request. The `Signatures` workflow (`.github/workflows/signatures.yml`, monthly and on demand) runs `bin/build-signatures.php --publish-dir=...` and commits the result to the orphan branch `signatures`: `signatures.json.gz`, `signatures.json.gz.sha256` and `state.json` (slug to version). A run reads the last `state.json` and signatures and downloads only plugins whose version changed or that are new. Each zip is scanned and deleted at once. A plugin that fails to download keeps its old data and is retried next run. `--max-minutes` stops starting downloads after a while and the next run carries on.

On a site, Settings > Signature list (or `wp axle-leftovers dataset download`) fetches the file from `raw.githubusercontent.com`, rejects it unless it is under the size limit, matches the checksum file, unpacks, and has the shape `{ version: 1, built, plugins: { slug: { name, t, o, c, m, r, u, p, n } } }`, then stores it in `uploads/axle-leftovers/dataset/`. It is merged with the bundled list: the downloaded entry wins for a slug in both, the bundled list fills the rest. Remove it to go back to the bundled list. Both the setting and the download are off by default.

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
| `axle_leftovers_owner` | `array $result, string $type, string $name` | owner, owner_name, confidence, evidence, in_use, in_use_by |
| `axle_leftovers_confidence_levels` | `array $levels` | certain, likely, guess, unknown labels |
| `axle_leftovers_signature` | `array $signature, string $slug, string $version` | names the plugin's code uses |
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
| `axle_leftovers_require_typed_confirmation` | `bool $required, string $confidence` | true for guess and unknown (cannot be turned off for those) |
| `axle_leftovers_uninstall_trash_policy` | `string $policy` | `purge`, or `restore` |
| `axle_leftovers_notice_message` | `string $message, string $slug, array $counts` | "Acme left 2 tables and 12 options." |
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
| `axle_leftovers_plugin_removed` | `array $entry, string $how` (admin, cli, outside) |
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
| `axle_leftovers_dataset_removed` | |

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
| `axle-leftovers/list-leftovers` | readonly | `plugin`, `type`, `confidence` |
| `axle-leftovers/get-item` | readonly | `type`, `name` |
| `axle-leftovers/list-trash` | readonly | |
| `axle-leftovers/trash-items` | destructive | `items` (type, name), `confirm_names` |
| `axle-leftovers/restore-items` | idempotent, not destructive | `batch` or `items` (trash IDs) |
| `axle-leftovers/purge-trash` | destructive, idempotent | `older_than`, `batch` |
| `axle-leftovers/rescan` | not readonly | `rescan_signatures` |
| `axle-leftovers/lookup-item` | not readonly, idempotent | `type`, `name`, `refresh` |
| `axle-leftovers/download-dataset` | not readonly, idempotent | |

Permission: the `axle_leftovers_capability` capability.

## REST

`axle-leftovers/v1`: `GET /items`, `GET /trash`, `POST /trash` (`items`, `confirm_names`), `DELETE /trash` (`older_than`, `batch`), `POST /restore` (`batch`, `items`), `POST /scan`, `GET /notice`. Same capability.

## WP-CLI

| Command | What it does |
| --- | --- |
| `wp axle-leftovers list [--plugin=] [--type=] [--confidence=] [--format=]` | Listed leftovers; `--plugin=` (empty) for unknown items |
| `wp axle-leftovers show <type> <name> [--format=]` | One item with its evidence |
| `wp axle-leftovers scan [--rescan-signatures]` | Scan now |
| `wp axle-leftovers export <type> <name>... [--output=]` | Export without trashing |
| `wp axle-leftovers trash <type> <name>... [--plugin=<slug>] [--yes]` | Move to the trash; typed names confirm Guess and Unknown items |
| `wp axle-leftovers restore <batch-id\|item-id>` | Restore a batch or one item |
| `wp axle-leftovers trash list [--format=]` | The trash |
| `wp axle-leftovers trash purge [--older-than=<days>] [--yes]` | Delete for good |
| `wp axle-leftovers ledger list [--format=]` / `ledger show <slug>` | Plugins seen |
| `wp axle-leftovers lookup <type> <name> [--refresh] [--format=]` | Online lookup of an Unknown item (needs the setting) |
| `wp axle-leftovers dataset download\|status\|remove` | The full signature list (download needs the setting) |

## Site Health

- Recommended: options left by deleted plugins add more than 100 KB of autoloaded data to every request.

## Data and privacy

- Table `{prefix}axle_leftovers_ledger`: plugins seen, their version, removal, sources and code signature.
- Table `{prefix}axle_leftovers_seen`: item type, name, owner, first seen, evidence. One row per distinct name.
- Table `{prefix}axle_leftovers_trash`: one row per trashed item: batch, type, name, owner, confidence, payload, locations, size, export, user, dates, status.
- Options `axle_leftovers_settings`, `axle_leftovers_scan` (cached results, not autoloaded), `axle_leftovers_lookups` (cached online lookups, not autoloaded), `axle_leftovers_dataset_meta` (date and count of the downloaded list, not autoloaded), `axle_leftovers_db_version`.
- Folder `uploads/axle-leftovers/` with `exports/`, `trash/` and `dataset/`, with deny rules.
- Cron `axle_leftovers_daily` (purge and rescan) and `axle_leftovers_scan_step` (background scan steps).

The privacy exporter lists the items a user trashed; the eraser purges trashed batches that hold the user's meta and removes the user from trash records. Uninstall purges the trash (or restores it, with the filter), then removes the tables, options, cron events, transients and folder.

## Limits in 1.0

- Theme leftovers (same machinery, planned for 1.1).
- Columns plugins added to core tables are not listed.
- A capability granted to single users (not through a role) is not removed.
- The dataset lists every name a plugin's code mentions, including names it reads from other plugins (importers), so a dataset match is Likely, not Certain.
- Folder moves need the trash folder on the same file system as uploads.
