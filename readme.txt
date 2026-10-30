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
* **Likely**: the name is in the plugin's code. Leftovers reads each plugin's PHP once and keeps the names it uses, even after the plugin is deleted, also over FTP. A bundled list covers about 500 of the most installed plugins on WordPress.org, for plugins deleted before Leftovers was installed.
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

No. The list of popular plugins is built when a release is made and ships inside the plugin.

= Is the export folder safe on nginx? =

It has `.htaccess` and `web.config` deny rules, which nginx ignores. The file names are random batch IDs, and the trash is purged after 30 days. On nginx, add `location ^~ /wp-content/uploads/axle-leftovers/ { deny all; }`.

== Screenshots ==

1. By plugin: what a deleted plugin left, with the evidence, sizes, the autoload cost and the reference check.
2. Moving an Unknown table to the trash needs its name typed.
3. The trash: one batch, each item's export, and Restore.
4. The ledger: every plugin seen, how it was removed, and the names its code uses.
5. The notice on the Plugins screen after a delete.

== External services ==

This plugin makes no external calls. The bundled list of plugin signatures in `data/signatures.json` is built at release time from public WordPress.org plugin zips by `bin/build-signatures.php`.

== Changelog ==

= 1.0.0 =
* First release.
