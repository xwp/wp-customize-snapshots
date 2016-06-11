=== Customize Snapshots ===
Contributors: westonruter, valendesigns, xwp, newscorpau
Requires at least: 4.5
Tested up to: trunk
Stable tag: 0.4.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Tags: customizer, customize, snapshots

Allow Customizer states to be drafted, and previewed with a private URL.

== Description ==

Customize Snapshots save the state of a Customizer session so it can be shared or even publish at a future date. A snapshot can be shared with a private URL to both authenticated and non authenticated users. This means anyone can preview a snapshot's settings on the front-end without loading the Customizer, and authenticated users can load the snapshot into the Customizer and publish or amend the settings at any time.

Requires PHP 5.3+.

**Development of this plugin is done [on GitHub](https://github.com/xwp/wp-customize-snapshots). Pull requests welcome. Please see [issues](https://github.com/xwp/wp-customize-snapshots) reported there before going to the [plugin forum](https://wordpress.org/support/plugin/customize-snapshots).**

== Changelog ==

= 0.4.0 =
* Update the UX by removing most modal dialogs and the need to set the snapshot scope.
* Eliminate the storage of non-dirty settings in a Snapshot, which eliminates the `scope` feature.
* Ensure that widget actions and filters get added when previewing snapshots on the front-end.
* Use `wp_slash()` instead of `add_magic_quotes()` when loading the snapshot post vars.
* Update `dev-lib`.

= 0.3.1 =
* Fix additional WordPress VIP issues.
* Update `dev-lib`.
* Update Coveralls.

= 0.3.0 =
* Initialize Snapshots before Widget Posts so that `$wp_customize` will be set on the front-end.
* Fix WordPress VIP PHPCS issues.
* Update `dev-lib`.
* Remove unused button markup in dialog.

= 0.2.1 =
* Fix AYS confirmation if the snapshot state is saved.
* Register dynamic settings to ensure that snapshot settings are recognized in the post values.
* Slash input for `wp_insert_post()` to prevent loss of slashes.

= 0.2 =
* Added the `customize_publish` capability.
* Separate "Save" & "Publish" buttons.

= 0.1.1 =
* Fix widget preview.

= 0.1 =
* Initial release.