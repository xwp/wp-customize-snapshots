=== Customize Snapshots ===
Contributors: westonruter, valendesigns, xwp, newscorpau
Requires at least: 4.3
Tested up to: trunk
Stable tag: 0.2.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Tags: customizer, customize, snapshots

Allow Customizer states to be drafted, and previewed with a private URL.

== Description ==

Customize Snapshots save the state of a Customizer session so it can be shared or even publish at a future date. A snapshot can be shared with a private URL to both authenticated and non authenticated users. This means anyone can preview a snapshot's settings on the front-end without loading the Customizer, and authenticated users can load the snapshot into the Customizer and publish or amend the settings at any time.

Snapshots are saved with a `scope` of `full` or `dirty`, which tells the preview how to playback the settings stored in the snapshot. A `full` snapshot will playback all the settings during preview, while the `dirty` snapshot will only playback the ones that were marked `dirty` when the snapshot was taken.

Requires PHP 5.3+.

**Development of this plugin is done [on GitHub](https://github.com/xwp/wp-customize-snapshots). Pull requests welcome. Please see [issues](https://github.com/xwp/wp-customize-snapshots) reported there before going to the [plugin forum](https://wordpress.org/support/plugin/customize-snapshots).**

== Changelog ==

= 0.2.2 =
* Add widgets found in dirty sidebars to the values array so they are initialized on the front-end.
* Add new `customize_snapshot_dirty_widget_values` filter to add widgets to the dirty values array.

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