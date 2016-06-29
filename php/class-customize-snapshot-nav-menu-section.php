<?php
/**
 * Customize Snapshot Nav Menu Section Class
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Customize Snapshot Menu Section Class
 *
 * Custom section only needed in JS.
 *
 * @see \WP_Customize_Section
 */
class Customize_Snapshot_Nav_Menu_Section extends \WP_Customize_Section {

	/**
	 * Control type.
	 *
	 * @access public
	 * @var string
	 */
	public $type = 'nav_menu';

	/**
	 * Get section parameters for JS.
	 *
	 * @access public
	 * @return array Exported parameters.
	 */
	public function json() {
		$exported = parent::json();
		$exported['menu_id'] = intval( preg_replace( '/^nav_menu\[(-?\d+)\]/', '$1', $this->id ) );

		return $exported;
	}
}
