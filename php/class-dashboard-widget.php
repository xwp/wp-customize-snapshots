<?php
/**
 * Customize Snapshot Dashboard Event handler.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Class Dashboard_Widget
 *
 * @package CustomizeSnapshots
 */
class Dashboard_Widget {

	/**
	 * Snapshot manager.
	 *
	 * @var Customize_Snapshot_Manager
	 */
	public $manager;

	/**
	 * Dashboard_Widget constructor.
	 *
	 * @param Customize_Snapshot_Manager $manager manager object.
	 */
	public function __construct( $manager ) {
		$this->manager = $manager;
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
		add_action( 'load-index.php', array( $this, 'handle_future_snapshot_preview_request' ) );
	}

	/**
	 * Add widget.
	 */
	public function add_widget() {
		wp_add_dashboard_widget(
			'customize_site_state_future_snapshot_preview',
			__( 'Preview Future Scheduled Snapshots','customize-snapshots' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Render widget.
	 */
	public function render_widget() {
		// @Todo Change date picker with default WordPress date picker.
		?>
		<form method="post">
			<label for="preview-schedule-snapshot-date"><?php esc_html_e( 'Pick a future date', 'customize-snapshots' ); ?>: </label>
			<input id="preview-schedule-snapshot-date" type="date" name="preview-schedule-snapshot-date"/>
			<?php wp_nonce_field( 'customize_site_state_future_snapshot_preview' );
			submit_button( 'Go!', 'primary', 'customize-future-snapshot-preview', false ); ?>
		</form>
		<?php
	}

	/**
	 * Handle future snapshot preview request.
	 */
	public function handle_future_snapshot_preview_request() {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['customize-future-snapshot-preview'] ) ) {
			return;
		}
		check_admin_referer( 'customize_site_state_future_snapshot_preview' );
		if ( ! isset( $_POST['preview-schedule-snapshot-date'] ) || empty( $_POST['preview-schedule-snapshot-date'] ) ) {
			return;
		}
		$date = new \DateTime( $_POST['preview-schedule-snapshot-date'] );
		$current_date = new \DateTime();
		if ( ! $date || $date <= $current_date ) {
			return;
		}
		$query = new \WP_Query( array(
			'post_type' => Post_Type::SLUG,
			'post_status' => 'future',
			'date_query' => array(
				'before' => $date->format( 'Y-m-d H:i:s' ),
				'inclusive' => true,
			),
			'fields' => 'ids',
			'posts_per_page' => -1,
			'no_found_rows' => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );
		$data = $query->posts;
		if ( ! empty( $data ) ) {
			$merged_snapshot_post_id = $this->manager->post_type->handle_snapshot_merge_bulk_actions( '', 'merge_snapshot', $data, true );
			$link = get_permalink( $merged_snapshot_post_id );
			wp_safe_redirect( $link );
			exit;
		}
	}
}
