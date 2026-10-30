=== Axle Leftovers ===
Contributors: aryanjasala
Tags: cleanup, database, orphan tables, uninstall, options
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find the tables, options, cron events and folders deleted plugins left behind, see who owns each and why, and clean up with undo.

== Description ==

Deleting a plugin runs its uninstall.php, if it has one. Many plugins have none, or skip their tables, cron events, roles, uploads folders and meta. Years later the site has tables nobody can name, autoloaded options from plugins nobody remembers, cron events firing into nothing, and roles in the user editor that do nothing.

Cleaners show these, but they guess the owner or charge for it, and every delete is permanent. So careful admins never clean.

Axle Leftovers shows the evidence for each owner, and never deletes on the first step. Everything goes to a trash first, with an export, and comes back exactly with one click.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

Part of Axle by AryanX, a family of plugins for running WordPress sites safely.

= Who owns this table? =

WordPress does not record which plugin created a table, option or cron event. Leftovers combines four sources and shows each one:

* **Certain**: Leftovers saw the plugin create it. Around every plugin activation it compares what exists before and after, and while it runs it records who creates new tables, options, cron events, roles, capabilities and meta keys.
* **Likely**: the name is in the plugin's code. Leftovers reads each plugin's PHP once and keeps the names it uses, even after the plugin is deleted, also over FTP. A bundled list covers about 500 of the most installed plugins on WordPress.org, for plugins deleted before Leftovers was installed. If you allow it, you can download a full list of about 10,000 plugins, rebuilt every month.
* **Guess**: the name starts like a plugin's slug. Never enough on its own: you have to type the name to trash it.
* **Unknown**: nothing points anywhere. You have to type the name too.

Data of an installed plugin, active or not, is never listed. Core tables, options, cron events, roles, capabilities and meta keys are never listed.

= Safe to clean =

* **Export first**: tables as SQL, everything else as JSON, in a protected folder you download from wp-admin.
* **Trash, not delete**: tables are renamed, rows and folders are moved, so a restore is exact. Cron events come back with their schedule, options with their autoload value.
* **One batch, one undo**: restore a whole batch or a single item from the Trash tab.
* **Reference check**: before anything moves, Leftovers searches active plugins, the theme, must-use plugins and drop-ins for the name. A hit blocks the trash and names the file.
* Roles that users still have are never removed. The users are listed instead.
* With Axle Read-Only Mode freezing the site, trash, restore and purge are refused.
* The trash is purged after 30 days, or when you empty it.

= What it finds =

Tables, options and transients, cron events, post, user, term and comment meta keys, top-level uploads folders, roles, and capabilities on roles. Each item shows rows, size and whether it is autoloaded, so you see what a deleted plugin still costs every page load.

On multisite, each site lists its own leftovers. Network options, user meta and the main site's tables are listed in Network Admin.

= WP-CLI =

* `wp axle-leftovers list [--plugin=] [--type=] [--confidence=] [--format=]`
* `wp axle-leftovers show <type> <name>`
* `wp axle-leftovers scan [--rescan-signatures]`
* `wp axle-leftovers export <type> <name>... [--output=]`
* `wp axle-leftovers trash <type> <name>... [--plugin=<slug>] [--yes]`
* `wp axle-leftovers restore <batch-id|item-id>`
* `wp axle-leftovers trash list` and `wp axle-leftovers trash purge [--older-than=<days>] [--yes]`
* `wp axle-leftovers ledger list` and `wp axle-leftovers ledger show <slug>`

= For developers =

Every default, list, limit and message has a filter, and every scan, trash, restore and purge fires an action, so an activity log can record each one. `AryanX\Axle\Leftovers\owner_of( $type, $name )` tells other plugins who owns an item. Abilities for AI agents on WordPress 6.9 and later. The full list is in [the reference](https://github.com/aryanjasala/axle-leftovers/blob/main/docs/REFERENCE.md).

= Privacy =

Trashed rows and exports can hold personal data, such as form entries in a plugin's table or user settings in user meta. They stay in a folder the web server does not serve, are downloaded only by administrators, and are deleted with the trash after 30 days. The plugin adds suggested text to Settings > Privacy and takes part in Tools > Export Personal Data and Erase Personal Data: erasing a user deletes the trashed batches that hold their meta.

== Frequently Asked Questions ==

= Does it delete anything right away? =

No. Trashing renames tables and moves rows and folders. Only purging deletes, after the retention period or when you empty the trash.

= What if the owner is wrong? =

Each item shows its evidence, and Guess and Unknown items need the name typed before they move. If something breaks after a trash, restore the batch from the Trash tab or with `wp axle-leftovers restore <batch>`.

= What happens when I uninstall Leftovers? =

Its own tables, settings and folder are removed. Items still in its trash are purged first, because their data lives in that folder and those tables. Deactivating shows a warning with Restore all and Empty trash while the trash is not empty. The `axle_leftovers_uninstall_trash_policy` filter can switch uninstall to restoring instead.

= Does it send anything anywhere? =

Not unless you turn on one of two opt-in features in Settings, both off by default. The online lookup sends the name of one Unknown item to veloria.dev when you click Look it up. The full list download fetches a file from raw.githubusercontent.com and sends nothing from your site. See External services below.

= What does Look it up do? =

For an Unknown item it searches the code of every plugin on WordPress.org for the name, and lists the plugins that use it with their installs and a link. One plugin means Likely. A few plugins mean Guess, with the most installed first. A name found in more than 20 plugins is shared, so no owner is picked. Results are cached for 30 days. Items found this way still need the name typed to move to the trash when the result is a Guess.

= What is the full list? =

The top 10,000 plugins on WordPress.org by installs, scanned with the same scanner as installed plugins. A GitHub workflow rebuilds it every month and only re-reads plugins whose version changed. Download it from Settings, or with `wp axle-leftovers dataset download`. It is stored in uploads, checked against a published checksum, and merged with the bundled list, which stays the fallback.

= Is the export folder safe on nginx? =

It has `.htaccess` and `web.config` deny rules, which nginx ignores. The file names are random batch IDs, and the trash is purged after 30 days. On nginx, add `location ^~ /wp-content/uploads/axle-leftovers/ { deny all; }`.

== Screenshots ==

1. By plugin: what a deleted plugin left, with the evidence, sizes, the autoload cost and the reference check.
2. Moving an Unknown table to the trash needs its name typed.
3. The trash: one batch, each item's export, and Restore.
4. The ledger: every plugin seen, how it was removed, and the names its code uses.
5. The notice on the Plugins screen after a delete.

== External services ==

This plugin makes no external calls until you turn one of these on in Tools > Leftovers > Settings. Both are off by default. Neither runs on a page load or in the background. Both can be turned off in code with a filter.

= Online lookup: Veloria (veloria.dev) =

What it is: Veloria, a code search of every plugin on WordPress.org (veloria.dev, formerly wpdirectory.net).

What is sent: one search with the name of the Unknown item you chose (a table without its prefix, an option name, a cron hook and so on), as an escaped text pattern, marked as a private search. Your server's IP address and user agent go with it, as with any web request. Nothing else from your site is sent.

When: only when you click Look it up, run `wp axle-leftovers lookup`, or call the `axle-leftovers/lookup-item` ability. At most one lookup every 30 seconds, and every result is cached for 30 days. The service allows 10 requests a minute.

What the service keeps: the search and its results, and server logs with IP address, path and time for a limited period.

Terms: https://veloria.dev/terms
Privacy: https://veloria.dev/privacy

Filters: `axle_leftovers_lookup_endpoint` changes the address, `axle_leftovers_lookup_enabled` returns false to turn the lookup off.

= Full signature list: GitHub (raw.githubusercontent.com) =

What it is: a file of the names and prefixes used by the top 10,000 plugins on WordPress.org, built every month by a GitHub Actions workflow in this plugin's repository (github.com/aryanjasala/axle-leftovers) and published to its `signatures` branch.

What is sent: nothing from your site. Your server asks for `signatures.json.gz` and `signatures.json.gz.sha256` from raw.githubusercontent.com, so GitHub sees your server's IP address and user agent.

When: only when you click Download the full list, run `wp axle-leftovers dataset download`, or call the `axle-leftovers/download-dataset` ability. The file has a size limit, has to match its published checksum, and has to have the right shape, or it is discarded. It is stored in `wp-content/uploads/axle-leftovers/dataset` and merged with the bundled list. Remove it any time from Settings.

Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

Filters: `axle_leftovers_dataset_url` changes the address (the checksum file must sit next to it), `axle_leftovers_dataset_download_enabled` returns false to turn the download off.

The bundled list in `data/signatures.json` is built at release time from public WordPress.org plugin zips by `bin/build-signatures.php`, and needs no call.

== Changelog ==

= 1.0.0 =
* First release.
