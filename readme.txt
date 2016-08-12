=== Customize Snapshots ===
Contributors: westonruter, valendesigns, xwp, newscorpau
Requires at least: 4.5.3
Tested up to: 4.6
Stable tag: 0.5.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Tags: customizer, customize, snapshots

Allow Customizer states to be drafted, and previewed with a private URL.

== Description ==

Customize Snapshots save the state of a Customizer session so it can be shared or even published at a future date. A snapshot can be shared with a private URL to both authenticated and non authenticated users. This means anyone can preview a snapshot's settings on the front-end without loading the Customizer, and authenticated users can load the snapshot into the Customizer and publish or amend the settings at any time.

Requires PHP 5.3+. **Development of this plugin is done [on GitHub](https://github.com/xwp/wp-customize-snapshots). Pull requests welcome. Please see [issues](https://github.com/xwp/wp-customize-snapshots) reported there before going to the [plugin forum](https://wordpress.org/support/plugin/customize-snapshots).**

= Persistent Object Caching =

Plugins and themes may currently only use `is_customize_preview()` to
decide whether or not they can store a value in the object cache. For
example, see `Twenty_Eleven_Ephemera_Widget::widget()`. However, when
viewing a snapshot on the frontend, the `is_customize_preview()` method
will return `false`. Plugins and themes that store values in the object
cache must either skip storing in the object cache when `CustomizeSnapshots\is_previewing_settings()`
is `true`, or they should include the `CustomizeSnapshots\current_snapshot_uuid()` in the cache key.

Example of bypassing object cache when previewing settings inside the Customizer preview or on the frontend via snapshots:

<pre lang="php">
if ( function_exists( 'CustomizeSnapshots\is_previewing_settings' ) ) {
	$bypass_object_cache = CustomizeSnapshots\is_previewing_settings();
} else {
	$bypass_object_cache = is_customize_preview();
}
$contents = null;
if ( ! $bypass_object_cache ) {
	$contents = wp_cache_get( 'something', 'myplugin' );
}
if ( ! $contents ) {
	ob_start();
	myplugin_do_something();
	$contents = ob_get_clean();
	echo $contents;
}
if ( ! $bypass_object_cache ) {
	wp_cache_set( 'something', $contents, 'myplugin', HOUR_IN_SECONDS );
}
</pre>

== Screenshots ==

1. The “Save & Publish” button is broken up into separate “Save” and “Publish” buttons. The “Save” button creates a snapshot and turns into “Update” to save a new snapshot.
2. For non-administrator users (who lack the new `customize_publish` capability) the “Publish” button is replaced with a “Submit” button. This takes the snapshot and puts it into a pending status.
3. Saving snapshot causes the snapshot UUID to appear in the URL, allowing it to be bookmarked to easily come back to later. Upon publishing, the UUID will be removed from the URL so a new snapshot can be started made.
4. The Snapshots admin page lists out all of the snapshots in the system. When the excerpt view is turned on, a list of the settings modified in the snapshot can be seen.
5. Viewing a snapshot post in the admin shows all of the modified settings contained within it. A link is provided to open the snapshot in the Customizer to continue making changes.
6. Published snapshots are shown in the admin screen but lack the ability to open in the Customizer, as they are intended to be frozen revisions for when the Customizer was saved.
7. Changes to snapshots are captured in revisions.

== Changelog ==

= 0.5.0 - 2016-08-11 =

Added:

 * Allow snapshot posts to be published from the admin, and thus also scheduled for publication. Customizer settings get saved when a snapshot post transitions to the publish status. If any of the settings are unrecognized or invalid, none of the settings are saved and the status gets kicked back to pending with error messages explaining the problem(s). Published snapshots are considered "locked" and the UI for updating them is hidden, aside from trash. (<a href="https://github.com/xwp/wp-customize-snapshots/issues/15" class="issue-link js-issue-link" data-id="151235522"    title="Allow a snapshot to be scheduled">#15</a>, <a href="https://github.com/xwp/wp-customize-snapshots/pull/62" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/62" data-id="166573576"   >#62</a>, <a href="https://github.com/xwp/wp-customize-snapshots/pull/59" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/59" data-id="164943805"   >#59</a>)
 * Add UI for scheduling for scheduling a snapshot when inside the Customizer. When a future date is selected, the “Save”&nbsp;button becomes “Schedule”. (<a href="https://github.com/xwp/wp-customize-snapshots/pull/68" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/68" data-id="168118044"   >#68</a>)
 * Add link in Customizer to access edit post screen. (<a href="https://github.com/xwp/wp-customize-snapshots/pull/59" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/59" data-id="164943805"   >#59</a>)
 * Add initial read-only REST API endpoints for snapshots. (<a href="https://github.com/xwp/wp-customize-snapshots/pull/63" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/63" data-id="167171939"   >#63</a>, <a href="https://github.com/xwp/wp-customize-snapshots/pull/59" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/59" data-id="164943805"   >#59</a>)
 * Ensure that setting validations are handled in snapshot requests. If any settings are invalid, the snapshot save will be rejected, invoking the same behavior as when attempting to publish invalid settings. (<a href="https://github.com/xwp/wp-customize-snapshots/pull/59" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/59" data-id="164943805"   >#59</a>, <a href="https://github.com/xwp/wp-customize-snapshots/pull/54" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/54" data-id="163427299"   >#54</a>, <a href="https://github.com/xwp/wp-customize-snapshots/issues/31" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/31" data-id="158250613"   >#31</a>)
 * Add link in Customizer to view the snapshot applied to the frontend, adding the <code>customize_snapshot_uuid</code> query param to the current URL being previewed and optioning a new window. (<a href="https://github.com/xwp/wp-customize-snapshots/pull/57" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/57" data-id="164465438"   >#57</a>)
 * Add link in snapshot edit post screen to view snapshot applied to the frontend. (<a href="https://github.com/xwp/wp-customize-snapshots/issues/49" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/49" data-id="159811398"   >#49</a>, <a href="https://github.com/xwp/wp-customize-snapshots/pull/52" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/52" data-id="160765769"   >#52</a>)
 * Add link in Customizer to access the snapshot's edit post admin screen, allowing the snapshot's state to be inspected. (<a href="https://github.com/xwp/wp-customize-snapshots/issues/48" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/48" data-id="159811341"   >#48</a>, <a href="https://github.com/xwp/wp-customize-snapshots/pull/51" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/51" data-id="160758987"   >#51</a>)
 * Inject the <code>customize_snapshot_uuid</code> param into all links when viewing a snapshot on the frontend. This allows the frontend to be browsed as normally with the snapshot context retained. In this mode, the admin bar shows links for returning to the Customizer, for inspecting the snapshot in the admin, and for existing the snapshot preview. If a user happened to navigate to a URL without the snapshot UUID param, then the admin bar will prompt for restoring the snapshot session. (<a href="https://github.com/xwp/wp-customize-snapshots/issues/47" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/47" data-id="159811283"   >#47</a>, <a href="https://github.com/xwp/wp-customize-snapshots/pull/70" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/70" data-id="169773057"   >#70</a>)
 * Inject the current Customizer state into Ajax requests. When viewing a snapshot on the frontend this is done by inserting the <code>customize_snapshot_uuid</code> param into the request via <code>jQuery.ajaxPrefilter</code>. When in the Customizer preview, the Ajax requests are also intercepted by <code>jQuery.ajaxPrefilter</code>: if they are <code>GET</code> they get converted to <code>POST</code> with <code>X-HTTP-Method-Override</code> header added, and the <code>customized</code> data is amended to the request. Requests to the WP REST API, Admin Ajax, and custom endpoints should all have the Customizer state reflected in the responses. (<a href="https://github.com/xwp/wp-customize-snapshots/issues/65" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/65" data-id="167173028"   >#65</a>, <a href="https://github.com/xwp/wp-customize-snapshots/pull/70" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/70" data-id="169773057"   >#70</a>)
 * Also, inject the current Customizer state into form submissions. When viewing a snapshot on the frontend this is done by inserting a <code>customize_snapshot_uuid</code> hidden form input. When in the Customizer preview, forms with a <code>GET</code> method get intercepted and the form data gets serialized and added to the <code>action</code> and then navigated to like a normal link. Forms with <code>POST</code> will continue to no-op when submitted in the Customizer (and their submit buttons will have <code>not-allowed</code> cursor). This fixes a long-standing Trac ticket <a href="https://core.trac.wordpress.org/ticket/20714">#20714</a> “Theme customizer: Impossible to preview a search results page”. (<a href="https://github.com/xwp/wp-customize-snapshots/pull/72" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/72" data-id="170326584"   >#72</a>)

Fixed:

 * Changes to nav menus, nav menu items, and nav menu locations can now be saved to snapshots, previewed on the frontend, and published like other settings in a snapshot. (<a href="https://github.com/xwp/wp-customize-snapshots/issues/2" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/2" data-id="115434987"   >#2</a>, <a href="https://github.com/xwp/wp-customize-snapshots/pull/55" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/55" data-id="163498110"   >#55</a>, <a href="https://github.com/xwp/wp-customize-snapshots/pull/56" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/56" data-id="163769839"   >#56</a>)
 * Refactor codebase, including new <code>Post_Type</code> class. (<a href="https://github.com/xwp/wp-customize-snapshots/pull/59" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/59" data-id="164943805"   >#59</a>)
 * Use <code>customize_refresh_nonces</code> filter to export snapshot nonce. (<a href="https://github.com/xwp/wp-customize-snapshots/pull/59" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/59" data-id="164943805"   >#59</a>)
 * Eliminate <code>generate_snapshot_uuid</code> request by returning new UUID in saving response. (<a href="https://github.com/xwp/wp-customize-snapshots/pull/59" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/59" data-id="164943805"   >#59</a>)
 * Clear up distinction between previewing vs saving values in a snapshot. Remove the <code>can_preview</code> method: only users who have the setting's capability can write to the snapshot, so everyone should be able to freely preview what has been stored there. (<a href="https://github.com/xwp/wp-customize-snapshots/issues/26" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/26" data-id="151753722"   >#26</a>, <a href="https://github.com/xwp/wp-customize-snapshots/pull/59" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/59" data-id="164943805"   >#59</a>)
 * Ensuring that snapshots UI isn't loaded if a theme switch is happening; prevent Save &amp; Activate button from going missing. (<a href="https://github.com/xwp/wp-customize-snapshots/issues/28" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/28" data-id="152663671"   >#28</a>, <a href="https://github.com/xwp/wp-customize-snapshots/pull/59" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/59" data-id="164943805"   >#59</a>)
 * Ensure that <code>get_permalink()</code> returns the frontend URL for the site with the <code>customize_snapshot_uuid</code> param added.

Removed:

* The <code>scope</code> parameter has been removed, as has storing non-<code>dirty</code> settings in a snapshot. (<a href="https://github.com/xwp/wp-customize-snapshots/pull/59" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/59" data-id="164943805"   >#59</a>)

See full commit log: [`0.4.0...0.5.0`](https://github.com/xwp/wp-customize-posts/compare/0.4.0...0.5.0)

Issues in milestone: [`milestone:0.5.0`](https://github.com/xwp/wp-customize-snapshots/issues?q=milestone%3A0.5.0)

Props: Weston Ruter (<a href="https://github.com/westonruter" class="user-mention">@westonruter</a>), Utkarsh Patel (<a href="https://github.com/PatelUtkarsh" class="user-mention">@PatelUtkarsh</a>), Derek Herman (<a href="https://github.com/valendesigns" class="user-mention">@valendesigns</a>), Miina Sikk (<a href="https://github.com/miina" class="user-mention">@miina</a>)

= 0.4.0 - 2016-06-11 =

Added:

* Improved UX by removing save/update dialogs, changing the Snapshot button text to “Save” &amp; “Update” for a more streamlined experience by removing the “full” snapshot option. (Issues <a href="https://github.com/xwp/wp-customize-snapshots/issues/13" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/13" data-id="149683843"   >#13</a>, <a href="https://github.com/xwp/wp-customize-snapshots/issues/42" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/42" data-id="159523365"   >#42</a>, PR <a href="https://github.com/xwp/wp-customize-snapshots/pull/30" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/30" data-id="158083735"   >#30</a>)
* Snapshot UUID is dynamically added to the Customizer URL when a snapshot is first saved and it is stripped from the URL once the settings are published (and the snapshot is published), at which point the snapshot is “frozen”. (Issue <a href="https://github.com/xwp/wp-customize-snapshots/issues/37" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/37" data-id="159311075"   >#37</a>, PR <a href="https://github.com/xwp/wp-customize-snapshots/pull/40" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/40" data-id="159362784"   >#40</a>).
* Update button can now be shift-clicked to open the snapshot on the frontend in a new window.
* Eliminate the storage of non-dirty settings in a Snapshot, which deprecates the <code>scope</code> feature (Issue <a href="https://github.com/xwp/wp-customize-snapshots/issues/42" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/42" data-id="159523365"   >#42</a>)
* Support listing snapshots in the admin and inspecting their contents WP Admin UI, with shortcuts to open snapshot in Customizer, viewing the list of settings contained in a snapshot from the excerpt in post list view (Issue <a href="https://github.com/xwp/wp-customize-snapshots/issues/45" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/45" data-id="159762724"   >#45</a>, PRs <a href="https://github.com/xwp/wp-customize-snapshots/pull/38" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/38" data-id="159336615"   >#38</a>, <a href="https://github.com/xwp/wp-customize-snapshots/pull/46" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/46" data-id="159796414"   >#46</a>).
* Introduce pending snapshots for users who are not administrators (who lack the <code>customize_publish</code> capability) to be able to make snapshots and save them, and then submit them for review once ready (PR <a href="https://github.com/xwp/wp-customize-snapshots/pull/38" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/38" data-id="159336615"   >#38</a>).
* Added revisions for snapshots so that changes to a snapshot made before the time it is published can be tracked.
* New banner image and icon (Issue <a href="https://github.com/xwp/wp-customize-snapshots/issues/24" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/24" data-id="151752405"   >#24</a>, PR <a href="https://github.com/xwp/wp-customize-snapshots/pull/27" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/27" data-id="151762646"   >#27</a>).
* Bumped minimum WordPress version to 4.5.

Fixed:

* Store content in `post_content` instead of `post_content_filtered` (Issue <a href="https://github.com/xwp/wp-customize-snapshots/issues/25" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/25" data-id="151753100"   >#25</a>, PRs <a href="https://github.com/xwp/wp-customize-snapshots/pull/36" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/36" data-id="159058487"   >#36</a>, <a href="https://github.com/xwp/wp-customize-snapshots/pull/43" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/43" data-id="159702836"   >#43</a>). <em>This breaks backwards compatibility with existing snapshots.</em>
* Replace <code>customize-snapshots</code> JS dependency from <code>customize-widgets</code> to <code>customize-controls</code> (PR <a href="https://github.com/xwp/wp-customize-snapshots/pull/14" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/14" data-id="150516750"   >#14</a>).
* Ensure that widget actions and filters are added when previewing snapshots from the front-end (Issue <a href="https://github.com/xwp/wp-customize-snapshots/pull/34" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/34" data-id="158843216"   >#34</a>, PR <a href="https://github.com/xwp/wp-customize-snapshots/issues/33" class="issue-link js-issue-link" data-id="158772746"    title="Ensure that widget actions and filters get added for previewing snapshots for unauthenticated users">#33</a>).
* Use <code>wp_slash()</code> instead of <code>add_magic_quotes()</code> when loading the snapshot post vars (PR <a href="https://github.com/xwp/wp-customize-snapshots/pull/23" class="issue-link js-issue-link" data-url="https://github.com/xwp/wp-customize-snapshots/issues/23" data-id="151289131"   >#23</a>).
* Update <code>dev-lib</code>.

See full commit log: <a href="https://github.com/xwp/wp-customize-snapshots/compare/0.3.1...0.4.0" class="commit-link"><code>0.3.1...0.4.0</code></a><br>
Issues/PRs in release: <a href="https://github.com/xwp/wp-customize-snapshots/issues?q=milestone%3A0.4.0"><code>milestone:0.4.0</code></a><br>
Props: Weston Ruter (<a href="https://github.com/westonruter" class="user-mention">@westonruter</a>), Derek Herman (<a href="https://github.com/valendesigns" class="user-mention">@valendesigns</a>), Luke Carbis (<a href="https://github.com/lukecarbis" class="user-mention">@lukecarbis</a>)

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