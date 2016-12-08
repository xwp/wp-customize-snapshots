<?php
/**
 * Instantiates Customize Snapshots
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

global $customize_snapshots_plugin;

require_once __DIR__ . '/php/class-plugin-base.php';
require_once __DIR__ . '/php/class-plugin.php';

$customize_snapshots_plugin = new Plugin();

/**
 * Customize Snapshots Plugin Instance
 *
 * @return Plugin
 */
function get_plugin_instance() {
	global $customize_snapshots_plugin;
	return $customize_snapshots_plugin;
}

/**
 * Convenience function for whether settings are being previewed.
 *
 * @see Customize_Snapshot_Manager::is_previewing_settings()
 * @see Customize_Snapshot_Manager::preview_snapshot_settings()
 *
 * @return bool Whether previewing settings.
 */
function is_previewing_settings() {
	$manager = get_plugin_instance()->customize_snapshot_manager;
	if ( get_plugin_instance()->compat ) {
		return $manager->is_previewing_settings();
	} else {
		return isset( $manager->customize_manager ) && $manager->customize_manager->is_preview();
	}
}

/**
 * Convenience function to get the current snapshot UUID.
 *
 * @see Customize_Snapshot_Manager::$current_snapshot_uuid
 *
 * @return string|null The current snapshot UUID or null if no snapshot.
 */
function current_snapshot_uuid() {
	$customize_snapshot_uuid = get_plugin_instance()->customize_snapshot_manager->current_snapshot_uuid;
	if ( empty( $customize_snapshot_uuid ) ) {
		return null;
	} else {
		return $customize_snapshot_uuid;
	}
}

/**
 * Returns whether it is back compat or not.
 *
 * @return bool is compat.
 */
function is_back_compat() {
	// Fix in case version contains 'src' for example 4.7-src in that case php version compare fails to compare correctly.
	$wp_version = get_bloginfo( 'version' );
	if ( $pos = strpos( $wp_version, 'src' ) ) {
		$wp_version = substr( $wp_version, 0, $pos - 1 );
	}
	return version_compare( $wp_version, '4.7', '<' );
}
