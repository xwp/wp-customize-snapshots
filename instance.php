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
 * @return bool Whether previewing settings.
 */
function is_previewing_settings() {
	$manager = get_plugin_instance()->customize_snapshot_manager;
	return ( isset( $manager->customize_manager ) && $manager->customize_manager->is_preview() ) || did_action( 'customize_preview_init' );
}

/**
 * Convenience function to get the current snapshot UUID.
 *
 * @see Customize_Snapshot_Manager::$current_snapshot_uuid
 *
 * @return string|null The current snapshot UUID or null if no snapshot.
 * @global \WP_Customize_Manager $wp_customize
 */
function current_snapshot_uuid() {
	global $wp_customize;
	if ( empty( $wp_customize ) ) {
		return null;
	} else {
		return $wp_customize->changeset_uuid();
	}
}

/**
 * Returns whether it is back compat or not.
 *
 * @return bool is compat.
 */
function is_back_compat() {
	$wp_version = get_bloginfo( 'version' );

	// Fix in case version contains extra string for example 4.7-src in that case php version_compare fails.
	$pos = strpos( $wp_version, '-' );
	if ( false !== $pos ) {
		$wp_version = substr( $wp_version, 0, $pos );
	}
	return version_compare( $wp_version, '4.9', '<' );
}
