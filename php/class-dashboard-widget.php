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
	 * Wrong date.
	 *
	 * @var int
	 */
	public $error_code = 0;

	/**
	 * Error codes and message mapping.
	 *
	 * @var array
	 */
	public $error = array();

	/**
	 * Dashboard_Widget constructor.
	 *
	 * @param Customize_Snapshot_Manager $manager manager object.
	 */
	public function __construct( $manager ) {
		$this->manager = $manager;
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
		add_action( 'load-index.php', array( $this, 'handle_future_snapshot_preview_request' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_dashboard_scripts' ) );

		$this->error = array(
			1 => __( 'Please select future date.', 'customize-snapshots' ),
			2 => __( 'No snapshot found to preview for given date.', 'customize-snapshots' ),
			3 => __( 'Something went wrong while merging snapshots.', 'customize-snapshots' ),
		);
	}

	/**
	 * Enqueue Dashboard styles.
	 *
	 * @param string $hook current page.
	 */
	public function enqueue_admin_dashboard_scripts( $hook ) {
		$handle = 'customize-snapshots-dashboard';
		if ( 'index.php' === $hook ) {
			wp_enqueue_style( $handle );
		}
	}

	/**
	 * Add widget.
	 */
	public function add_widget() {
		wp_add_dashboard_widget(
			'customize_site_state_future_snapshot_preview',
			__( 'Preview Future Site State','customize-snapshots' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Render widget.
	 */
	public function render_widget() {
		$date_time = current_time( 'mysql' );
		$date_time = new \DateTime( $date_time );
		if ( isset( $_POST['year'], $_POST['month'], $_POST['day'], $_POST['hour'], $_POST['minute'], $_REQUEST['_wpnonce'] ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'customize_site_state_future_snapshot_preview' ) ) {
			$date_time->setTimestamp( strtotime( "{$_POST['year']}-{$_POST['month']}-{$_POST['day']} {$_POST['hour']}:{$_POST['minute']}" ) );
		}
		if ( $this->error_code ) {
			echo '<p class="error-message">' . esc_html( $this->error[ $this->error_code ] ) . '</p>';
		} ?>
		<form method="post">
				<div class="preview-future-state date-inputs clear">
					<label>
						<span class="screen-reader-text"><?php esc_html_e( 'Month', 'customize-snapshots' ); ?></span>
						<?php $month = $this->manager->get_month_choices(); ?>
						<select id="snapshot-date-month" class="date-input month" data-date-input="month" name="month">
							<?php foreach ( $month['month_choices'] as $month_choice ) {
								echo '<option value="' . esc_attr( $month_choice['value'] ) . '" ' . selected( $date_time->format( 'm' ), $month_choice['value'], false ) . '>' . esc_html( $month_choice['text'] ) . '</option>';} ?>
						</select>
					</label>
					<label>
						<span class="screen-reader-text"><?php esc_html_e( 'Day', 'customize-snapshots' ); ?></span>
						<input type="number" size="2" maxlength="2" autocomplete="off" class="date-input day" data-date-input="day" min="1" max="31" value="<?php echo esc_attr( $date_time->format( 'd' ) ); ?>" name="day"/>
					</label>
					<span class="time-special-char">,</span>
					<label>
						<span class="screen-reader-text"><?php esc_html_e( 'Year', 'customize-snapshots' ); ?></span>
						<input type="number" size="4" maxlength="4" autocomplete="off" class="date-input year" data-date-input="year" min="<?php echo intval( $date_time->format( 'Y' ) ); ?>" value="<?php echo intval( $date_time->format( 'Y' ) ); ?>" max="9999" name="year" />
					</label>
					<span class="time-special-char">@</span>
					<label>
						<span class="screen-reader-text"><?php esc_html_e( 'Hour', 'customize-snapshots' ); ?></span>
						<input type="number" size="2" maxlength="2" autocomplete="off" class="date-input hour" data-date-input="hour" min="0" max="23" value="<?php echo intval( $date_time->format( 'G' ) ); ?>" name="hour" />
					</label>
					<span class="time-special-char">:</span>
					<label>
						<span class="screen-reader-text"><?php esc_html_e( 'Minute', 'customize-snapshots' ); ?></span>
						<input type="number" size="2" maxlength="2" autocomplete="off" class="date-input minute" data-date-input="minute" min="0" max="59" value="<?php echo intval( $date_time->format( 'i' ) ); ?>" name="minute" />
					</label>
					<?php wp_nonce_field( 'customize_site_state_future_snapshot_preview' );
					submit_button( 'Preview', 'primary', 'customize-future-snapshot-preview', false ); ?>
			</div>
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
		if ( ! isset( $_POST['year'], $_POST['month'], $_POST['day'], $_POST['hour'], $_POST['minute'] ) ) {
			$this->error_code = 1;
			return;
		}
		$date = new \DateTime();
		$date->setTimestamp( strtotime( "{$_POST['year']}-{$_POST['month']}-{$_POST['day']} {$_POST['hour']}:{$_POST['minute']}" ) );
		$current_date = new \DateTime( current_time( 'mysql' ) );
		if ( ! $date || $date <= $current_date ) {
			$this->error_code = 1;
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
		$mergable_posts = $query->posts;
		if ( ! empty( $mergable_posts ) ) {
			if ( 1 === count( $mergable_posts ) ) {
				// In case of single post duplicate it's data.
				$_snapshot_post = get_post( array_shift( $mergable_posts ) );
				$merged_snapshot_post_id = $this->manager->post_type->save( array(
					'uuid' => Customize_Snapshot_Manager::generate_uuid(),
					'status' => 'auto-draft',
					'data' => $this->manager->post_type->get_post_content( $_snapshot_post ),
					'post_date' => current_time( 'mysql', false ),
					'post_date_gmt' => current_time( 'mysql', true ),
				) );
			} else {
				// Merge posts.
				$merged_snapshot_post_id = $this->manager->post_type->handle_snapshot_merge_bulk_actions( '', 'merge_snapshot', $mergable_posts, true );
			}

			if ( $merged_snapshot_post_id ) {
				$link = get_permalink( $merged_snapshot_post_id );
				wp_safe_redirect( $link );
				exit;
			} else {
				$this->error_code = 3;
			}
		} else {
			$this->error_code = 2;
		}
	}
}
