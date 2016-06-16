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
 * @since 4.3.0
 *
 * @see WP_Customize_Section
 */
class Customize_Snapshot_Nav_Menu_Section extends \WP_Customize_Section {

	/**
	 * Control type.
	 *
	 * @since 4.3.0
	 * @access public
	 * @var string
	 */
	public $type = 'nav_menu';

	/**
	 * Get section parameters for JS.
	 *
	 * @since 4.3.0
	 * @access public
	 * @return array Exported parameters.
	 */
	public function json() {
		$exported = parent::json();
		if ( preg_match( '/\[([^\]]*)\]/', $this->id, $match ) ) {
			$exported['menu_id'] = intval( $match[1] );
		} else {
			$exported['menu_id'] = intval( preg_replace( '/^nav_menu\[(\d+)\]/', '$1', $this->id ) );
		}
		return $exported;
	}
}
