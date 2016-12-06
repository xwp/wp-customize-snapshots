<?php
/**
 * Customize Snapshot.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Customize Snapshot Class
 *
 * Implements snapshots for Customizer settings
 * This is dummy class main functionality is merged in core class \WP_Customize_Manager in 4.7
 *
 * @see https://core.trac.wordpress.org/changeset/38810
 * @package CustomizeSnapshots
 */
class Customize_Snapshot {

	/**
	 * Customize_Snapshot_Manager instance.
	 *
	 * @access protected
	 * @var Customize_Snapshot_Manager|Customize_Snapshot_Manager_Back_Compat
	 */
	protected $snapshot_manager;

	/**
	 * Initial loader.
	 *
	 * @access public
	 *
	 * @throws Exception If the UUID is invalid.
	 *
	 * @param Customize_Snapshot_Manager $snapshot_manager Customize snapshot bootstrap instance.
	 */
	public function __construct( $snapshot_manager ) {
		$this->snapshot_manager = $snapshot_manager;
	}

	/**
	 * Get the snapshot post associated with the provided UUID, or null if it does not exist.
	 *
	 * @return \WP_Post|null Post or null.
	 */
	public function post() {
		$post_id = $this->snapshot_manager->customize_manager->changeset_post_id();
		if ( $post_id ) {
			return get_post( $post_id );
		}
		return null;
	}

	/**
	 * Get the snapshot uuid.
	 *
	 * @return string
	 */
	public function uuid() {
		return $this->snapshot_manager->customize_manager->changeset_uuid();
	}

	/**
	 * Get edit post link.
	 *
	 * @param int|\WP_Post $post_id Post.
	 *
	 * @return null|string Post edit link.
	 */
	public function get_edit_link( $post_id ) {
		$has_filter = has_filter( 'get_edit_post_link', '__return_empty_string' );
		if ( $has_filter ) {
			remove_filter( 'get_edit_post_link', '__return_empty_string' );
		}
		$link = get_edit_post_link( $post_id, 'raw' );
		if ( $has_filter ) {
			add_filter( 'get_edit_post_link', '__return_empty_string' );
		}
		return $link;
	}

}
