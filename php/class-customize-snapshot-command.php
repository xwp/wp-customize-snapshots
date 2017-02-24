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
		unset( $arg );
		$migrate_obj = new Migrate( get_plugin_instance() );
		if ( $migrate_obj->plugin->compat ) {
			\WP_CLI::error( __( 'You\'re using older WordPress version please upgrade 4.7 or above to migrate.', 'customize-snapshots' ) );
			return;
		}
		if ( $migrate_obj->is_migrated() ) {
			\WP_CLI::success( __( 'Already migrated.', 'customize-snapshots' ) );
			return;
		}
		$dry_mode = isset( $assoc_args['dry-run'] );
		if ( ! $dry_mode ) {
			wp_suspend_cache_addition( true );
			$post_count = $migrate_obj->changeset_migrate();
		} else {
			$ids = $migrate_obj->changeset_migrate( -1, true );
			\WP_CLI::success( __( 'Posts migrated:', 'customize-snapshots' ) . ' ' . implode( ',', $ids ) );
			$post_count = count( $ids );
		}
		/* translators: %s: post count.*/
		\WP_CLI::success( sprintf( __( 'Total posts migrated: %s', 'customize-snapshots' ), $post_count ) );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'snapshot', __NAMESPACE__ . '\\Customize_Snapshot_Command' );
}
