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
