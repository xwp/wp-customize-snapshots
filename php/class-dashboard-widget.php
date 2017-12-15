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
			2 => __( 'No changesets found to preview for given date.', 'customize-snapshots' ),
			3 => __( 'Something went wrong while merging changesets.', 'customize-snapshots' ),
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
			__( 'Preview Future Site State', 'customize-snapshots' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Render widget.
	 */
	public function render_widget() {
		$scheduled_changeset_count = 0;
		$post_counts = wp_count_posts( Post_Type::SLUG );
		if ( ! empty( $post_counts->future ) ) {
			$scheduled_changeset_count = $post_counts->future;
		}

		if ( 0 === $scheduled_changeset_count ) {
			?>
			<p>
				<em>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s is URL to list of changesets */
							__( 'There are no <a href="%s">Customizer changesets</a> currently scheduled.', 'customize-snapshots' ),
							esc_url( admin_url( 'edit.php?post_status=future&post_type=customize_changeset' ) )
						)
					);
					?>
				</em>
			</p>
			<?php
			return;
		} elseif ( 1 === $scheduled_changeset_count ) {
			$changeset_posts = get_posts( array(
				'post_type' => Post_Type::SLUG,
				'post_status' => 'future',
				'posts_per_page' => 1,
			) );
			?>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %1$s is URL to inspect scheduled changeset, %2$s is URL to preview changeset */
						__( 'You only have one <a href="%1$s">scheduled changeset</a>. <a href="%2$s" class="button button-secondary">Preview</a>', 'customize-snapshots' ),
						get_edit_post_link( $changeset_posts[0]->ID ),
						get_permalink( $changeset_posts[0]->ID )
					)
				);
				?>
			</p>
			<?php
			return;
		} else {
			?>
			<p>
				<?php
				echo wp_kses_post( sprintf(
					/* translators: %s is link to scheduled changeset(s) */
					_n(
						'Select a future date to preview how the site will appear when the %1$s <a href="%2$s">scheduled changeset</a> is published.',
						'Select a future date to preview how the site will appear when one or more of the %1$s <a href="%2$s">scheduled changesets</a> are published.',
						$scheduled_changeset_count,
						'customize-snapshots'
					),
					number_format_i18n( $scheduled_changeset_count ),
					esc_url( admin_url( 'edit.php?post_status=future&post_type=customize_changeset' ) )
				) );
				?>
			</p>
			<?php
		}

		$date_time = current_time( 'mysql' );
		$date_time = new \DateTime( $date_time );
		if ( isset( $_POST['year'], $_POST['month'], $_POST['day'], $_POST['hour'], $_POST['minute'], $_REQUEST['_wpnonce'] ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'customize_site_state_future_snapshot_preview' ) ) {
			$user_date_time = $this->get_date();
			if ( false !== $user_date_time ) {
				$date_time = $user_date_time;
			}
		}

		$autofocus = '';
		if ( $this->error_code ) {
			echo '<div class="notice inline notice-error notice-alt"><p>' . esc_html( $this->error[ $this->error_code ] ) . '</p></div>';
			$autofocus = ' autofocus ';
		}
		?>
		<form method="post">
			<div class="preview-future-state date-inputs clear">
				<label>
					<span class="screen-reader-text"><?php esc_html_e( 'Month', 'customize-snapshots' ); ?></span>
					<?php $month = Customize_Snapshot_Manager_Compat::get_month_choices(); ?>
					<select <?php echo $autofocus; // WPCS: xss ok. ?> id="snapshot-date-month" class="date-input month" data-date-input="month" name="month">
						<?php
						foreach ( $month['month_choices'] as $month_choice ) :
							?>
							<option value="<?php echo esc_attr( $month_choice['value'] ); ?>" <?php selected( $date_time->format( 'm' ), $month_choice['value'] ); ?>> <?php echo esc_html( $month_choice['text'] ); ?></option>
							<?php
						endforeach;
						?>
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
				<?php
				wp_nonce_field( 'customize_site_state_future_snapshot_preview' );
				submit_button( 'Preview', 'primary', 'customize-future-snapshot-preview', false );
				?>
			</div>
		</form>
		<?php
	}

	/**
	 * Get date object from POST data.
	 */
	private function get_date() {
		$keys = array( 'year', 'month', 'day', 'hour', 'minute' );
		$should_fail = false;
		foreach ( $keys as $key ) {
			if ( ! is_numeric( $_POST[ $key ] ) ) {
				$should_fail = true;
				break;
			}
		}
		if ( ! $should_fail ) {
			$date_time = new \DateTime();
			$time_string = sprintf( '%d-%d-%d %d:%d', intval( $_POST['year'] ), intval( $_POST['month'] ), intval( $_POST['day'] ), intval( $_POST['hour'] ), intval( $_POST['minute'] ) );
			$date_time->setTimestamp( strtotime( $time_string ) );
			return $date_time;
		}
		return false;
	}

	/**
	 * Handle future snapshot preview request.
	 *
	 * @return int|void post_id.
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
		$date = $this->get_date();
		if ( false === $date ) {
			return;
		}
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
		$mergable_post_ids = $query->posts;
		if ( ! empty( $mergable_post_ids ) ) {
			if ( 1 === count( $mergable_post_ids ) ) {
				// In case of single post duplicate it's data.
				$_snapshot_post = get_post( array_shift( $mergable_post_ids ) );
				$merged_snapshot_post_id = $this->manager->post_type->save( array(
					'uuid' => wp_generate_uuid4(),
					'status' => 'auto-draft',
					'data' => $this->manager->post_type->get_post_content( $_snapshot_post ),
					'post_date' => current_time( 'mysql', false ),
					'post_date_gmt' => current_time( 'mysql', true ),
				) );
			} else {
				// Merge posts.
				$mergeable_posts         = array_map( 'get_post', $mergable_post_ids );
				$merged_snapshot_post_id = $this->manager->post_type->merge_snapshots( $mergeable_posts, 'auto-draft' );
			}

			if ( $merged_snapshot_post_id ) {
				add_post_meta( $merged_snapshot_post_id, 'is_future_preview', '1' );
				$link = get_permalink( $merged_snapshot_post_id );
				$success = wp_safe_redirect( $link );
				if ( $success ) {
					exit;
				} else {
					return $merged_snapshot_post_id;
				}
			} else {
				$this->error_code = 3;
			}
		} else {
			$this->error_code = 2;
		}
	}
}
