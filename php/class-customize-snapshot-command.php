<?php
/**
 * Customize Snapshot.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Customize snapshot commands.
 *
 * @package CustomizeSnapshots
 */
class Customize_Snapshot_Command {

	/**
	 * Migrates snapshot posts into changesets.
	 *
	 * ## OPTIONS
	 * [--dry-run]
	 * : Check number of posts will effect via this process.
	 *
	 * ## EXAMPLES
	 *
	 *      wp customize_snapshot migrate --dry-run
	 *      wp customize_snapshot migrate
	 *
	 * @when after_wp_load
	 *
	 * @param array $arg        command args.
	 * @param array $assoc_args assoc args.
	 */
	public function migrate( $arg, $assoc_args ) {
		$migrate_obj = new Migrate();
		if ( $migrate_obj->compat ) {
			\WP_CLI::error( 'You\'re using older WordPress version please upgrade 4.7 or above to migrate.' );
			return;
		}
		if ( $migrate_obj->is_migrated() ) {
			\WP_CLI::success( 'Already migrated.' );
			return;
		}
		$dry_mode = isset( $assoc_args['dry-run'] );
		if ( ! $dry_mode ) {
			$post_count = $migrate_obj->changeset_migrate();
			\WP_CLI::success( $post_count . ' posts migrated.' );
		} else {
			$ids = $migrate_obj->changeset_migrate( - 1, true );
			\WP_CLI::success( count( $ids ) . ' posts migrated: ' . implode( ',', $ids ) );
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'snapshot', __NAMESPACE__ . '\\Customize_Snapshot_Command' );
}
