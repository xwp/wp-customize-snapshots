<?php
/**
 * Customize Snapshot Manager Compat.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Customize Snapshot Manager Compat Class
 *
 * Implements a snapshot manager for Customizer settings for < 4.9
 *
 * @package CustomizeSnapshots
 */
class Customize_Snapshot_Manager_Compat extends Customize_Snapshot_Manager {

	/**
	 * Enqueue styles & scripts for the Customizer.
	 *
	 * @action customize_controls_enqueue_scripts
	 * @global \WP_Customize_Manager $wp_customize
	 */
	public function enqueue_controls_scripts() {
		wp_enqueue_style( 'customize-snapshots' );
		wp_enqueue_script( 'customize-snapshots' );

		$post = null;

		$preview_url_query_vars = array();
		$post_id = $this->get_customize_manager()->changeset_post_id();
		if ( $post_id ) {
			$post = get_post( $post_id );
			$preview_url_query_vars = $this->post_type->get_customizer_state_query_vars( $post->ID );
			if ( $post instanceof \WP_Post ) {
				$this->override_post_date_default_data( $post );
				$edit_link = $this->get_edit_link( $post_id );
			}
		}

		// Script data array.
		$exports = apply_filters( 'customize_snapshots_export_data', array(
			'editLink' => isset( $edit_link ) ? $edit_link : '',
			'publishDate' => isset( $post->post_date ) ? $post->post_date : '',
			'title' => isset( $post->post_title ) ? $post->post_title : '',
			'postStatus' => isset( $post->post_status ) ? $post->post_status : '',
			'currentUserCanPublish' => current_user_can( 'customize_publish' ),
			'initialServerDate' => current_time( 'mysql', false ),
			'initialServerTimestamp' => floor( microtime( true ) * 1000 ),
			'previewingTheme' => isset( $preview_url_query_vars['theme'] ) ? $preview_url_query_vars['theme'] : '',
			'i18n' => array(
				'saveButton' => __( 'Save', 'customize-snapshots' ),
				'updateButton' => __( 'Update', 'customize-snapshots' ),
				'submit' => __( 'Submit', 'customize-snapshots' ),
				'submitted' => __( 'Submitted', 'customize-snapshots' ),
				'permsMsg' => array(
					'save' => __( 'You do not have permission to publish changes, but you can create a changeset by clicking the "Save" button.', 'customize-snapshots' ),
					'update' => __( 'You do not have permission to publish changes, but you can modify this changeset by clicking the "Update" button.', 'customize-snapshots' ),
				),
				'aysMsg' => __( 'Changes that you made may not be saved.', 'customize-snapshots' ),
				'errorMsg' => __( 'The changeset could not be saved.', 'customize-snapshots' ),
				'errorTitle' => __( 'Error', 'customize-snapshots' ),
				'collapseSnapshotScheduling' => __( 'Collapse changeset scheduling', 'customize-snapshots' ),
				'expandSnapshotScheduling' => __( 'Expand changeset scheduling', 'customize-snapshots' ),
			),
		) );

		wp_scripts()->add_inline_script(
			'customize-snapshots',
			sprintf( 'wp.customize.snapshots = new wp.customize.Snapshots( %s );', wp_json_encode( $exports ) ),
			'after'
		);
	}

	/**
	 * Underscore (JS) templates for dialog windows.
	 */
	public function render_templates() {
		$this->add_edit_box_template();
		?>
		<script type="text/html" id="tmpl-snapshot-preview-link">
			<a href="#" target="frontend-preview" id="snapshot-preview-link" class="dashicons dashicons-welcome-view-site" title="<?php esc_attr_e( 'View on frontend', 'customize-snapshots' ); ?>">
				<span class="screen-reader-text"><?php esc_html_e( 'View on frontend', 'customize-snapshots' ); ?></span>
			</a>
		</script>

		<script type="text/html" id="tmpl-snapshot-expand-button">
			<a href="javascript:void(0)" id="snapshot-expand-button" role="button" aria-controls="snapshot-schedule" aria-pressed="false" class="dashicons dashicons-edit"></a>
		</script>

		<script type="text/html" id="tmpl-snapshot-save">
			<button id="snapshot-save" class="button button-secondary">
				{{ data.buttonText }}
			</button>
		</script>

		<script type="text/html" id="tmpl-snapshot-submit">
			<button id="snapshot-submit" class="button button-primary">
				{{ data.buttonText }}
			</button>
		</script>

		<script type="text/html" id="tmpl-snapshot-dialog-error">
			<div id="snapshot-dialog-error" title="{{ data.title }}">
				<p>{{ data.message }}</p>
			</div>
		</script>

		<script type="text/html" id="tmpl-snapshot-status-button">
			<?php
			$data = array(
				'choices' => array(
					'publish' => array(
						'option_text' => __( 'Publish', 'customize-snapshots' ),
						'alt_text' => __( 'Published', 'customize-snapshots' ),
					),
					'draft' => array(
						'option_text' => __( 'Save Draft', 'customize-snapshots' ),
						'alt_text' => __( 'Draft', 'customize-snapshots' ),
					),
					'future' => array(
						'option_text' => __( 'Schedule', 'customize-snapshots' ),
						'alt_text' => __( 'Scheduled', 'customize-snapshots' ),
					),
					'pending' => array(
						'option_text' => __( 'Save Pending', 'customize-snapshots' ),
						'alt_text' => __( 'Pending', 'customize-snapshots' ),
					),
				),
				'selected' => 'publish',
				'confirm_publish_text' => __( 'Confirm Publish', 'customize-snapshots' ),
			);
			?>

			<# _.defaults( data, <?php echo wp_json_encode( $data ); ?> ); #>

				<div id="snapshot-status-button-wrapper">
					<label class="screen-reader-text" for="snapshot-status-button"><?php esc_attr_e( 'Changeset Status', 'customize-snapshots' ); ?></label>
					<select id="snapshot-status-button">
						<# _.each( data.choices, function( buttonText, status ) { #>
							<option value="{{ status }}" data-alt-text="{{ buttonText.alt_text }}"
								<# if ( data.selected == status ) { #>
									selected="selected"
								<# } #>
								<# if ( 'publish' == status ) { #>
									data-confirm-text="{{ data.confirm_publish_text }}"
									data-publish-text="{{ data.choices.publish.option_text }}"
								<# } #>
								>
									{{ buttonText.option_text }}
								</option>
							<# } ); #>
					</select>
					<button class="snapshot-status-button-overlay button button-primary" data-button-text="{{ data.choices[ data.selected ].option_text }}" data-alt-text="{{ data.choices[ data.selected ].alt_text }}">
						{{ data.choices[ data.selected ].option_text }}
					</button>
				</div>
		</script>
		<?php
	}

	/**
	 * Add edit box template.
	 */
	public function add_edit_box_template() {
		$data = $this->get_month_choices();
		?>
		<script type="text/html" id="tmpl-snapshot-edit-container">
			<div id="customize-snapshot">
				<div class="snapshot-schedule-title">
					<h3>
						<?php esc_html_e( 'Edit Changeset', 'customize-snapshots' ); ?>
					</h3>
					<?php $edit_snapshot_text = __( 'Edit Changeset', 'customize-snapshots' ); ?>
					<a href="{{ data.editLink }}" class="dashicons dashicons-external snapshot-edit-link" target="_blank" title="<?php echo esc_attr( $edit_snapshot_text ); ?>" aria-expanded="false"><span class="screen-reader-text"><?php echo esc_html( $edit_snapshot_text ); ?></span></a>
				</div>

				<ul class="snapshot-controls">
					<li class="snapshot-control snapshot-control-title">
						<label for="snapshot-title" class="customize-control-title">
							<?php esc_html_e( 'Title', 'customize-snapshots' ); ?>
						</label>
						<input id="snapshot-title" type="text" value="{{data.title}}">
					</li>
					<# if ( data.currentUserCanPublish ) { #>
						<li class="snapshot-control snapshot-control-date">
							<label for="snapshot-date-month" class="customize-control-title">
								<?php esc_html_e( 'Scheduling', 'customize-snapshots' ); ?>
								<span class="reset-time">(<a href="#" title="<?php esc_attr_e( 'Reset scheduled date to original or current date', 'customize-snapshots' ); ?>"><?php esc_html_e( 'Reset', 'customize-snapshots' ); ?></a>)</span>
							</label>
							<p class="snapshot-schedule-description">
								<?php esc_html_e( 'Schedule changes to publish (go live) at a future date.', 'customize-snapshots' ); ?>
							</p>
							<div class="snapshot-future-date-notification notice notice-error ">
								<?php esc_html_e( 'Please select a future date.', 'customize-snapshots' ); ?>
							</div>
							<div class="snapshot-schedule-control date-inputs clear">
								<label>
									<span class="screen-reader-text"><?php esc_html_e( 'Month', 'customize-snapshots' ); ?></span>
									<# _.defaults( data, <?php echo wp_json_encode( $data ); ?> ); #>
										<select id="snapshot-date-month" class="date-input month" data-date-input="month">
											<# _.each( data.month_choices, function( choice ) { #>
												<# if ( _.isObject( choice ) && ! _.isUndefined( choice.text ) && ! _.isUndefined( choice.value ) ) {
													text = choice.text;
													value = choice.value;
												} #>
												<option value="{{ value }}"
													<# if (choice.value == data.month) { #>
														selected="selected"
													<# } #>
													>
														{{ text }}
												</option>
											<# } ); #>
										</select>
								</label>
								<label>
									<span class="screen-reader-text"><?php esc_html_e( 'Day', 'customize-snapshots' ); ?></span>
									<input type="number" size="2" maxlength="2" autocomplete="off" class="date-input day" data-date-input="day" min="1" max="31" value="{{ data.day }}" />
								</label>
								<span class="time-special-char">,</span>
								<label>
									<span class="screen-reader-text"><?php esc_html_e( 'Year', 'customize-snapshots' ); ?></span>
									<input type="number" size="4" maxlength="4" autocomplete="off" class="date-input year" data-date-input="year" min="<?php echo esc_attr( date( 'Y' ) ); ?>" value="{{ data.year }}" max="9999" />
								</label>
								<span class="time-special-char">@</span>
								<label>
									<span class="screen-reader-text"><?php esc_html_e( 'Hour', 'customize-snapshots' ); ?></span>
									<input type="number" size="2" maxlength="2" autocomplete="off" class="date-input hour" data-date-input="hour" min="0" max="23" value="{{ data.hour }}" />
								</label>
								<span class="time-special-char">:</span>
								<label>
									<span class="screen-reader-text"><?php esc_html_e( 'Minute', 'customize-snapshots' ); ?></span>
									<input type="number" size="2" maxlength="2" autocomplete="off" class="date-input minute" data-date-input="minute" min="0" max="59" value="{{ data.minute }}" />
								</label>
							</div>
							<div class="timezone-info">
								<span class="snapshot-scheduled-countdown" role="timer"></span>
								<?php
								$tz_string = get_option( 'timezone_string' );
								if ( $tz_string ) {
									$tz = new \DateTimezone( $tz_string );
									$formatted_gmt_offset = $this->format_gmt_offset( $tz->getOffset( new \DateTime() ) / 3600 );
									$tz_name = str_replace( '_', ' ', $tz->getName() );

									/* translators: 1: timezone name, 2: gmt offset  */
									$timezone_description = sprintf( __( 'This site\'s dates are in the %1$s timezone (currently UTC%2$s).', 'customize-snapshots' ), $tz_name, $formatted_gmt_offset );
								} else {
									$formatted_gmt_offset = $this->format_gmt_offset( get_option( 'gmt_offset' ) );

									/* translators: %s: gmt offset  */
									$timezone_description = sprintf( __( 'Dates are in UTC%s.', 'customize-snapshots' ), $formatted_gmt_offset );
								}
								echo esc_html( $timezone_description );
								?>
							</div>
						</li>
						<# } #>
				</ul>
			</div>
		</script>

		<script id="tmpl-snapshot-scheduled-countdown" type="text/html">
			<# if ( data.remainingTime < 2 * 60 ) { #>
			<?php esc_html_e( 'This is scheduled for publishing in about a minute.', 'customize-snapshots' ); ?>

			<# } else if ( data.remainingTime < 60 * 60 ) { #>
			<?php
			/* translators: %s is a placeholder for the Underscore template var */
			echo sprintf( esc_html__( 'This changeset is scheduled for publishing in about %s minutes.', 'customize-snapshots' ), '{{ Math.ceil( data.remainingTime / 60 ) }}' );
			?>

			<# } else if ( data.remainingTime < 24 * 60 * 60 ) { #>
			<?php
			/* translators: %s is a placeholder for the Underscore template var */
			echo sprintf( esc_html__( 'This changeset is scheduled for publishing in about %s hours.', 'customize-snapshots' ), '{{ Math.round( data.remainingTime / 60 / 60 * 10 ) / 10 }}' );
			?>

			<# } else { #>
				<?php
				/* translators: %s is a placeholder for the Underscore template var */
				echo sprintf( esc_html__( 'This changeset is scheduled for publishing in about %s days.', 'customize-snapshots' ), '{{ Math.round( data.remainingTime / 60 / 60 / 24 * 10 ) / 10 }}' );
				?>

				<# } #>
		</script>
		<?php
	}

	/**
	 * Format GMT Offset.
	 *
	 * @see wp_timezone_choice()
	 * @param float $offset Offset in hours.
	 * @return string Formatted offset.
	 */
	public function format_gmt_offset( $offset ) {
		if ( 0 <= $offset ) {
			$formatted_offset = '+' . (string) $offset;
		} else {
			$formatted_offset = (string) $offset;
		}
		$formatted_offset = str_replace(
			array( '.25', '.5', '.75' ),
			array( ':15', ':30', ':45' ),
			$formatted_offset
		);
		return $formatted_offset;
	}

	/**
	 * Generate options for the month Select.
	 *
	 * Based on touch_time().
	 *
	 * @see touch_time()
	 *
	 * @return array
	 */
	public function get_month_choices() {
		global $wp_locale;
		$months = array();
		for ( $i = 1; $i < 13; $i = $i + 1 ) {
			$month_number = zeroise( $i, 2 );
			$month_text = $wp_locale->get_month_abbrev( $wp_locale->get_month( $i ) );

			/* translators: 1: month number (01, 02, etc.), 2: month abbreviation */
			$months[ $i ]['text'] = sprintf( __( '%1$s-%2$s', 'customize-snapshots' ), $month_number, $month_text );
			$months[ $i ]['value'] = $month_number;
		}
		return array(
			'month_choices' => $months,
		);
	}

	/**
	 * Override default date values to a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return \WP_Post Object if the post data did not apply.
	 */
	public function override_post_date_default_data( \WP_Post &$post ) {
		if ( ! is_array( $post ) ) {
			// Make sure that empty dates are not used in case of setting invalidity.
			$empty_date = '0000-00-00 00:00:00';
			if ( $empty_date === $post->post_date ) {
				$post->post_date = current_time( 'mysql', false );
			}
			if ( $empty_date === $post->post_date_gmt ) {
				$post->post_date_gmt = current_time( 'mysql', true );
			}
			if ( $empty_date === $post->post_modified ) {
				$post->post_modified = current_time( 'mysql', false );
			}
			if ( $empty_date === $post->post_modified_gmt ) {
				$post->post_modified_gmt = current_time( 'mysql', true );
			}
		}
		return $post;
	}
}
