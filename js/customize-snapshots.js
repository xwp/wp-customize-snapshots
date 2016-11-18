/* global jQuery, wp */
/* eslint consistent-this: ["error", "snapshot"] */

(function( api, $ ) {
	'use strict';

	var escKeyCode = 27;

	api.Snapshots = api.Class.extend( {

		data: {
			action: '',
			uuid: '',
			editLink: '',
			title: '',
			publishDate: '',
			postStatus: '',
			currentUserCanPublish: true,
			initialServerDate: '',
			initialServerTimestamp: 0,
			initialClientTimestamp: 0,
			i18n: {},
			dirty: false
		},

		uuidParam: 'customize_changeset_uuid',

		initialize: function initialize( snapshotsConfig ) {
			var snapshot = this, snapshotExists;

			snapshot.schedule = {};

			if ( _.isObject( snapshotsConfig ) ) {
				_.extend( snapshot.data, snapshotsConfig );
			}

			window._wpCustomizeControlsL10n.save = snapshot.data.i18n.publish;
			window._wpCustomizeControlsL10n.saved = snapshot.data.i18n.published;

			// Set the initial client timestamp.
			snapshot.data.initialClientTimestamp = snapshot.dateValueOf();

			api.bind( 'ready', function() {
				snapshotExists = '{}' !== api.previewer.query().customized;
				api.state.create( 'snapshot-exists', snapshotExists );
				api.state.create( 'snapshot-saved', true );
				api.state.create( 'snapshot-submitted', true );

				if ( ! snapshot.data.uuid ) {
					snapshot.data.uuid = api.settings.changeset.uuid;
				}

				api.bind( 'change', function() {
					api.state( 'snapshot-saved' ).set( false );
					api.state( 'snapshot-submitted' ).set( false );
				} );

				snapshot.frontendPreviewUrl = new api.Value( api.previewer.previewUrl.get() );
				snapshot.frontendPreviewUrl.link( api.previewer.previewUrl );

				snapshot.addButtons();
				snapshot.editSnapshotUI();

				$( '#snapshot-save' ).on( 'click', function( event ) {
					var scheduleDate,
						requestData = {
							status: 'draft'
						};

					event.preventDefault();

					if ( snapshot.snapshotTitle && snapshot.snapshotTitle.val() ) {
						requestData.title = snapshot.snapshotTitle.val();
					}

					if ( ! _.isEmpty( snapshot.editContainer ) && snapshot.isFutureDate() ) {
						scheduleDate = snapshot.getDateFromInputs();
						requestData.status = 'future';
						requestData.date = snapshot.formatDate( scheduleDate );
						snapshot.sendUpdateSnapshotRequest( requestData );
					} else {
						snapshot.sendUpdateSnapshotRequest( requestData );
					}
				} );

				$( '#snapshot-submit' ).on( 'click', function( event ) {
					var requestData = {
						status: 'pending'
					};
					event.preventDefault();
					if ( snapshot.snapshotTitle && snapshot.snapshotTitle.val() ) {
						requestData.title = snapshot.snapshotTitle.val();
					}
					snapshot.sendUpdateSnapshotRequest( requestData );
				} );

				if ( api.state( 'snapshot-exists' ).get() ) {
					api.state( 'saved' ).set( false );
					snapshot.resetSavedStateQuietly();
				}

				api.trigger( 'snapshots-ready', snapshot );
			} );

			api.bind( 'save', function( request ) {

				// Make sure that saved state is false so that Published button behaves as expected.
				api.state( 'saved' ).set( false );

				request.fail( function( response ) {
					var id = 'snapshot-dialog-error',
						hashedID = '#' + id,
						snapshotDialogPublishError = wp.template( id ),
						spinner = $( '#customize-header-actions' ).find( '.spinner' );

					if ( response.responseText ) {

						// Insert the dialog error template.
						if ( 0 === $( hashedID ).length ) {
							$( 'body' ).append( snapshotDialogPublishError( {
								title: snapshot.data.i18n.publish,
								message: api.state( 'snapshot-exists' ).get() ? snapshot.data.i18n.permsMsg.update : snapshot.data.i18n.permsMsg.save
							} ) );
						}

						spinner.removeClass( 'is-active' );

						// Open the dialog.
						$( hashedID ).dialog( {
							autoOpen: true,
							modal: true
						} );
					}
				} );
				return request;
			} );
		},

		/**
		 * Get the preview URL with the snapshot UUID attached.
		 *
		 * @returns {string} URL.
		 */
		getSnapshotFrontendPreviewUrl: function getSnapshotFrontendPreviewUrl() {
			var snapshot = this, a = document.createElement( 'a' );
			a.href = snapshot.frontendPreviewUrl.get();
			if ( a.search ) {
				a.search += '&';
			}
			a.search += snapshot.uuidParam + '=' + snapshot.data.uuid;
			return a.href;
		},

		/**
		 * Create the snapshot buttons.
		 *
		 * @return {void}
		 */
		addButtons: function addButtons() {
			var snapshot = this,
				header = $( '#customize-header-actions' ),
				publishButton = header.find( '#save' ),
				submitButton, templateData = {}, setPreviewLinkHref;

			snapshot.dirtySnapshotPostSetting = new api.Value();
			snapshot.dirtyScheduleDate = new api.Value();

			// Save/update button.
			if ( api.state( 'snapshot-exists' ).get() ) {
				if ( 'future' === snapshot.data.postStatus ) {
					templateData.buttonText = snapshot.data.i18n.scheduleButton;
				} else {
					templateData.buttonText = snapshot.data.i18n.updateButton;
				}
			} else {
				templateData.buttonText = snapshot.data.i18n.saveButton;
			}

			snapshot.createSelectButton();
			snapshot.snapshotButton = $( '#snapshot-dropdown-button' );
			snapshot.snapshotButton.attr( 'disabled', true ).find( 'select' ).prop( 'disabled', true );

			if ( ! snapshot.data.currentUserCanPublish ) {
				snapshot.snapshotButton.attr( 'title', api.state( 'snapshot-exists' ).get() ? snapshot.data.i18n.permsMsg.update : snapshot.data.i18n.permsMsg.save );
			}

			// Preview link.
			snapshot.previewLink = $( $.trim( wp.template( 'snapshot-preview-link' )() ) );
			snapshot.previewLink.toggle( api.state( 'snapshot-saved' ).get() );
			snapshot.previewLink.attr( 'target', snapshot.data.uuid );
			setPreviewLinkHref = _.debounce( function() {
				if ( api.state( 'snapshot-exists' ).get() ) {
					snapshot.previewLink.attr( 'href', snapshot.getSnapshotFrontendPreviewUrl() );
				} else {
					snapshot.previewLink.attr( 'href', snapshot.frontendPreviewUrl.get() );
				}
			} );
			snapshot.frontendPreviewUrl.bind( setPreviewLinkHref );
			setPreviewLinkHref();
			api.state.bind( 'change', setPreviewLinkHref );
			api.bind( 'saved', setPreviewLinkHref );
			snapshot.snapshotButton.after( snapshot.previewLink );
			api.state( 'snapshot-saved' ).bind( function( saved ) {
				snapshot.previewLink.toggle( saved );
			} );

			// Edit button.
			snapshot.snapshotExpandButton = $( $.trim( wp.template( 'snapshot-expand-button' )( {} ) ) );
			snapshot.snapshotExpandButton.insertAfter( snapshot.snapshotButton );

			if ( ! snapshot.data.editLink ) {
				snapshot.snapshotExpandButton.hide();
			}

			api.state( 'change', function() {
				snapshot.snapshotExpandButton.toggle( api.state( 'snapshot-saved' ).get() && api.state( 'snapshot-exists' ).get() );
			} );

			api.state( 'snapshot-exists' ).bind( function( exist ) {
				snapshot.snapshotExpandButton.toggle( exist );
			} );

			api.state( 'snapshot-saved' ).bind( function( saved ) {
				snapshot.snapshotButton.prop( 'disabled', saved );
			} );

			api.state( 'saved' ).bind( function( saved ) {
				if ( saved ) {
					snapshot.snapshotButton.prop( 'disabled', true );
				}
			} );
			api.bind( 'change', function() {
				snapshot.snapshotButton.prop( 'disabled', false );
			} );

			api.state( 'snapshot-exists' ).bind( function( exists ) {
				var buttonText, permsMsg;
				if ( exists ) {
					buttonText = snapshot.data.i18n.updateButton;
					permsMsg = snapshot.data.i18n.permsMsg.update;
				} else {
					buttonText = snapshot.data.i18n.saveButton;
					permsMsg = snapshot.data.i18n.permsMsg.save;
				}

				snapshot.snapshotButton.text( buttonText );
				if ( ! snapshot.data.currentUserCanPublish ) {
					snapshot.snapshotButton.attr( 'title', permsMsg );
				}
			} );

			snapshot.dirtySnapshotPostSetting.bind( function( dirty ) {
				if ( dirty ) {
					snapshot.snapshotButton.prop( 'disabled', false );
				} else {
					snapshot.snapshotButton.prop( 'disabled', ! snapshot.data.dirty );
				}
				snapshot.updateButtonText();
			} );
			snapshot.dirtyScheduleDate.bind( function( dirty ) {
				var date;
				if ( dirty ) {
					date = snapshot.getDateFromInputs();
					if ( ! date || ! snapshot.data.currentUserCanPublish ) {
						return;
					}
					snapshot.snapshotButton.text( snapshot.data.i18n.scheduleButton );
				} else {
					snapshot.updateButtonText();
				}
			} );

			// Submit for review button.
			if ( ! snapshot.data.currentUserCanPublish ) {
				publishButton.hide();
				submitButton = wp.template( 'snapshot-submit' );
				submitButton = $( $.trim( submitButton( {
					buttonText: snapshot.data.i18n.submit
				} ) ) );
				submitButton.prop( 'disabled', ! api.state( 'snapshot-exists' ).get() );
				submitButton.insertBefore( snapshot.snapshotButton );
				api.state( 'snapshot-submitted' ).bind( function( submitted ) {
					submitButton.prop( 'disabled', submitted );
				} );
			}

			header.addClass( 'button-added' );
		},

		/**
		 * Update button text.
		 *
		 * @returns {void}
		 */
		updateButtonText: function updateButtonText() {
			var snapshot = this, date = snapshot.getDateFromInputs();
			if ( snapshot.isFutureDate() && date && snapshot.data.currentUserCanPublish ) {
				snapshot.snapshotButton.text( snapshot.data.i18n.scheduleButton );
			} else {
				snapshot.snapshotButton.text( api.state( 'snapshot-exists' ).get() ? snapshot.data.i18n.updateButton : snapshot.data.i18n.saveButton );
			}
		},

		/**
		 * Renders snapshot schedule and handles it's events.
		 *
		 * @returns {void}
		 */
		editSnapshotUI: function editSnapshotUI() {
			var snapshot = this, sliceBegin = 0,
				sliceEnd = -2, updateUI;

			snapshot.snapshotEditContainerDisplayed = new api.Value();

			updateUI = function() {
				snapshot.populateSetting();
			};

			// Inject the UI.
			if ( _.isEmpty( snapshot.editContainer ) ) {
				if ( '0000-00-00 00:00:00' === snapshot.data.publishDate ) {
					snapshot.data.publishDate = snapshot.getCurrentTime();
				}

				// Normalize date with secs set as zeros removed.
				snapshot.data.publishDate = snapshot.data.publishDate.slice( sliceBegin, sliceEnd ) + '00';

				// Extend the snapshots data object and add the parsed datetime strings.
				snapshot.data = _.extend( snapshot.data, snapshot.parseDateTime( snapshot.data.publishDate ) );

				// Add the template to the DOM.
				snapshot.editContainer = $( $.trim( wp.template( 'snapshot-edit-container' )( snapshot.data ) ) );
				snapshot.editContainer.hide().appendTo( $( '#customize-header-actions' ) );

				if ( snapshot.data.currentUserCanPublish ) {

					// Store the date inputs.
					snapshot.schedule.inputs = snapshot.editContainer.find( '.date-input' );

					snapshot.schedule.inputs.on( 'input', updateUI );

					snapshot.schedule.inputs.on( 'blur', function() {
						snapshot.populateInputs();
						snapshot.populateSetting();
					} );

					snapshot.updateCountdown();

					snapshot.editContainer.find( '.reset-time a' ).on( 'click', function( event ) {
						event.preventDefault();
						snapshot.updateSnapshotEditControls();
					} );
				}

				snapshot.snapshotTitle = snapshot.editContainer.find( '#snapshot-title' );
				snapshot.snapshotTitle.on( 'input', updateUI );
			}

			// Set up toggling of the schedule container.
			snapshot.snapshotEditContainerDisplayed.bind( function( isDisplayed ) {
				if ( isDisplayed ) {
					snapshot.editContainer.stop().slideDown( 'fast' ).attr( 'aria-expanded', 'true' );
					snapshot.snapshotExpandButton.attr( 'aria-pressed', 'true' );
					snapshot.snapshotExpandButton.prop( 'title', snapshot.data.i18n.collapseSnapshotScheduling );
				} else {
					snapshot.editContainer.stop().slideUp( 'fast' ).attr( 'aria-expanded', 'false' );
					snapshot.snapshotExpandButton.attr( 'aria-pressed', 'false' );
					snapshot.snapshotExpandButton.prop( 'title', snapshot.data.i18n.expandSnapshotScheduling );
				}
			} );

			// Toggle schedule container when clicking the button.
			snapshot.snapshotExpandButton.on( 'click', function( event ) {
				event.preventDefault();
				snapshot.snapshotEditContainerDisplayed.set( ! snapshot.snapshotEditContainerDisplayed.get() );
			} );

			// Collapse the schedule container when Esc is pressed while the button is focused.
			snapshot.snapshotExpandButton.on( 'keydown', function( event ) {
				if ( escKeyCode === event.which && snapshot.snapshotEditContainerDisplayed.get() ) {
					event.stopPropagation();
					event.preventDefault();
					snapshot.snapshotEditContainerDisplayed.set( false );
				}
			} );

			// Collapse the schedule container when Esc is pressed inside of the schedule container.
			snapshot.editContainer.on( 'keydown', function( event ) {
				if ( escKeyCode === event.which && snapshot.snapshotEditContainerDisplayed.get() ) {
					event.stopPropagation();
					event.preventDefault();
					snapshot.snapshotEditContainerDisplayed.set( false );
					snapshot.snapshotExpandButton.focus();
				}
			} );

			// Collapse the schedule container interacting outside the schedule container.
			$( 'body' ).on( 'mousedown', function( event ) {
				if ( snapshot.snapshotEditContainerDisplayed.get() && ! $.contains( snapshot.editContainer[0], event.target ) && ! snapshot.snapshotExpandButton.is( event.target ) ) {
					snapshot.snapshotEditContainerDisplayed.set( false );
				}
			} );

			snapshot.snapshotEditContainerDisplayed.set( false );

			api.state( 'snapshot-saved' ).bind( function( saved ) {
				if ( saved ) {
					snapshot.updateSnapshotEditControls();
				}
			} );

			api.bind( 'change', function() {
				snapshot.data.dirty = true;
				snapshot.editContainer.find( 'a.snapshot-edit-link' ).hide();
			} );

			api.state( 'saved' ).bind( function( saved ) {
				if ( saved && ! _.isEmpty( snapshot.editContainer ) ) {
					snapshot.data.dirty = false;
					snapshot.data.publishDate = snapshot.getCurrentTime();
					snapshot.snapshotEditContainerDisplayed.set( false );
					snapshot.updateSnapshotEditControls();
				}
			} );

			api.state( 'snapshot-exists' ).bind( function( exists ) {
				if ( exists && ! _.isEmpty( snapshot.editContainer ) ) {
					snapshot.updateSnapshotEditControls();
				} else {
					snapshot.snapshotEditContainerDisplayed.set( false );
				}
			} );
		},

		/**
		 * Updates snapshot schedule with `snapshot.data`.
		 *
		 * @return {void}
		 */
		updateSnapshotEditControls: function updateSnapshotEditControls() {
			var snapshot = this,
				parsed,
				sliceBegin = 0,
				sliceEnd = -2;

			if ( _.isEmpty( snapshot.editContainer ) ) {
				return;
			}

			if ( snapshot.data.currentUserCanPublish ) {
				if ( '0000-00-00 00:00:00' === snapshot.data.publishDate ) {
					snapshot.data.publishDate = snapshot.getCurrentTime();
				}

				// Normalize date with seconds removed.
				snapshot.data.publishDate = snapshot.data.publishDate.slice( sliceBegin, sliceEnd ) + '00';
				parsed = snapshot.parseDateTime( snapshot.data.publishDate );

				// Update date controls.
				snapshot.schedule.inputs.each( function() {
					var input = $( this ),
						fieldName = input.data( 'date-input' );
					$( this ).val( parsed[fieldName] );
				} );
			}

			snapshot.editContainer.find( 'a.snapshot-edit-link' )
				.attr( 'href', snapshot.data.editLink )
				.show();
			if ( ! _.isEmpty( snapshot.data.title ) ) {
				snapshot.snapshotTitle.val( snapshot.data.title );
			}
			snapshot.populateSetting();
		},

		/**
		 * Update the scheduled countdown text.
		 *
		 * Hides countdown if post_status is not already future.
		 * Toggles the countdown if there is no remaining time.
		 *
		 * @returns {boolean} True if date inputs are valid.
		 */
		updateCountdown: function updateCountdown() {
			var snapshot = this, countdown = snapshot.editContainer.find( '.snapshot-scheduled-countdown' ),
				countdownTemplate = wp.template( 'snapshot-scheduled-countdown' ),
				dateTimeFromInput = snapshot.getDateFromInputs(),
				millisecondsDivider = 1000,
				remainingTime;

			if ( ! dateTimeFromInput ) {
				return false;
			}

			remainingTime = dateTimeFromInput.valueOf();
			remainingTime -= snapshot.dateValueOf( snapshot.getCurrentTime() );
			remainingTime = Math.ceil( remainingTime / millisecondsDivider );

			if ( 0 < remainingTime ) {
				countdown.text( countdownTemplate( {
					remainingTime: remainingTime
				} ) );
				countdown.show();
			} else {
				countdown.hide();
			}

			return true;
		},

		/**
		 * Silently update the saved state to be true without triggering the
		 * changed event so that the AYS beforeunload dialog won't appear
		 * if no settings have been changed after saving a snapshot. Note
		 * that it would be better if jQuery's callbacks allowed them to
		 * disabled and then re-enabled later, for example:
		 *   wp.customize.state.topics.change.disable();
		 *   wp.customize.state( 'saved' ).set( true );
		 *   wp.customize.state.topics.change.enable();
		 * But unfortunately there is no such enable method.
		 *
		 * @return {void}
		 */
		resetSavedStateQuietly: function resetSavedStateQuietly() {
			api.state( 'saved' )._value = true;
		},

		/**
		 * Make the AJAX request to update/save a snapshot.
		 *
		 * @param {object} options Options.
		 * @param {string} options.status The post status for the snapshot.
		 * @return {void}
		 */
		sendUpdateSnapshotRequest: function sendUpdateSnapshotRequest( options ) {
			var snapshot = this,
				spinner = $( '#customize-header-actions' ).find( '.spinner' ),
				request, data;

			data = _.extend(
				{
					status: 'draft'
				},
				options
			);

			request = wp.customize.previewer.save( data );

			spinner.addClass( 'is-active' );

			request.always( function( response ) {
				spinner.removeClass( 'is-active' );
				if ( response.edit_link ) {
					snapshot.data.editLink = response.edit_link;
				}
				if ( response.publish_date ) {
					snapshot.data.publishDate = response.publish_date;
				}
				if ( response.title ) {
					snapshot.data.title = response.title;
				}
				snapshot.updateSnapshotEditControls();
				snapshot.data.dirty = false;
			} );

			request.done( function( response ) {
				var url = api.previewer.previewUrl(),
					customizeUrl = window.location.href;

				// Change the save button text to update.
				api.state( 'snapshot-exists' ).set( true );

				api.state( 'snapshot-saved' ).set( true );

				if ( 'pending' === data.status ) {
					api.state( 'snapshot-submitted' ).set( true );
				}
				snapshot.resetSavedStateQuietly();

				// Trigger an event for plugins to use.
				api.trigger( 'customize-snapshots-update', {
					previewUrl: url,
					customizeUrl: customizeUrl,
					uuid: snapshot.data.uuid,
					response: response
				} );
			} );

			request.fail( function( response ) {
				var id = 'snapshot-dialog-error',
					hashedID = '#' + id,
					snapshotDialogShareError = wp.template( id ),
					messages = snapshot.data.i18n.errorMsg,
					invalidityCount = 0,
					dialogElement;

				if ( response.setting_validities ) {
					invalidityCount = _.size( response.setting_validities, function( validity ) {
						return true !== validity;
					} );
				}

				/*
				 * Short-circuit if there are setting validation errors, since the error messages
				 * will be displayed with the controls themselves. Eventually, once we have
				 * a global notification area in the Customizer, we can eliminate this
				 * short-circuit and instead display the messages in there.
				 * See https://core.trac.wordpress.org/ticket/35210
				 */
				if ( invalidityCount > 0 ) {
					return;
				}

				if ( response.errors ) {
					messages += ' ' + _.pluck( response.errors, 'message' ).join( ' ' );
				}

				// Insert the snapshot dialog error template.
				dialogElement = $( hashedID );
				if ( ! dialogElement.length ) {
					dialogElement = $( snapshotDialogShareError( {
						title: snapshot.data.i18n.errorTitle,
						message: messages
					} ) );
					$( 'body' ).append( dialogElement );
				}

				// Open the dialog.
				$( hashedID ).dialog( {
					autoOpen: true,
					modal: true
				} );
			} );
		},

		/**
		 * Get date from inputs.
		 *
		 * @returns {Date|null} Date created from inputs or null if invalid date.
		 */
		getDateFromInputs: function getDateFromInputs() {
			var snapshot = this,
				template = snapshot.editContainer,
				monthOffset = 1,
				date;

			date = new Date(
				parseInt( template.find( '[data-date-input="year"]' ).val(), 10 ),
				parseInt( template.find( '[data-date-input="month"]' ).val(), 10 ) - monthOffset,
				parseInt( template.find( '[data-date-input="day"]' ).val(), 10 ),
				parseInt( template.find( '[data-date-input="hour"]' ).val(), 10 ),
				parseInt( template.find( '[data-date-input="minute"]' ).val(), 10 )
			);

			if ( isNaN( date.valueOf() ) ) {
				return null;
			}

			date.setSeconds( 0 );

			return date;
		},

		/**
		 * Parse datetime string.
		 *
		 * @param {string} datetime Date/Time string.
		 * @returns {object|null} Returns object containing date components or null if parse error.
		 */
		parseDateTime: function parseDateTime( datetime ) {
			var matches = datetime.match( /^(\d\d\d\d)-(\d\d)-(\d\d) (\d\d):(\d\d):(\d\d)$/ );

			if ( ! matches ) {
				return null;
			}

			matches.shift();

			return {
				year: matches.shift(),
				month: matches.shift(),
				day: matches.shift(),
				hour: matches.shift(),
				minute: matches.shift(),
				second: matches.shift()
			};
		},

		/**
		 * Format a Date Object. Returns 'Y-m-d H:i:s' format.
		 *
		 * @props http://stackoverflow.com/questions/10073699/pad-a-number-with-leading-zeros-in-javascript#comment33639551_10073699
		 *
		 * @param {Date} date A Date object.
		 * @returns {string} A formatted date String.
		 */
		formatDate: function formatDate( date ) {
			var formattedDate,
				yearLength = 4,
				nonYearLength = 2,
				monthOffset = 1;

			formattedDate = ( '0000' + date.getFullYear() ).substr( -yearLength, yearLength );
			formattedDate += '-' + ( '00' + ( date.getMonth() + monthOffset ) ).substr( -nonYearLength, nonYearLength );
			formattedDate += '-' + ( '00' + date.getDate() ).substr( -nonYearLength, nonYearLength );
			formattedDate += ' ' + ( '00' + date.getHours() ).substr( -nonYearLength, nonYearLength );
			formattedDate += ':' + ( '00' + date.getMinutes() ).substr( -nonYearLength, nonYearLength );
			formattedDate += ':' + ( '00' + date.getSeconds() ).substr( -nonYearLength, nonYearLength );

			return formattedDate;
		},

		/**
		 * Populate inputs from the setting value, if none of them are currently focused.
		 *
		 * @returns {boolean} Whether the inputs were populated.
		 */
		populateInputs: function populateInputs() {
			var snapshot = this, parsed;

			if ( snapshot.schedule.inputs.is( ':focus' ) || '0000-00-00 00:00:00' === snapshot.data.publishDate ) {
				return false;
			}

			parsed = snapshot.parseDateTime( snapshot.data.publishDate );
			if ( ! parsed ) {
				return false;
			}

			snapshot.schedule.inputs.each( function() {
				var input = $( this ),
					fieldName = input.data( 'date-input' );

				if ( ! $( this ).is( 'select' ) && '' === $( this ).val() ) {
					$( this ).val( parsed[fieldName] );
				}
			} );
			return true;
		},

		/**
		 * Populate setting value from the inputs.
		 *
		 * @returns {void}
		 */
		populateSetting: function populateSetting() {
			var snapshot = this,
				date = snapshot.getDateFromInputs(),
				scheduled;

			if ( ! date || ! snapshot.data.currentUserCanPublish ) {
				snapshot.dirtySnapshotPostSetting.set( snapshot.data.title !== snapshot.snapshotTitle.val() );
				return;
			}

			date.setSeconds( 0 );
			scheduled = snapshot.formatDate( date ) !== snapshot.data.publishDate;

			if ( snapshot.data.title !== snapshot.snapshotTitle.val() || scheduled ) {
				snapshot.dirtySnapshotPostSetting.set( true );
			} else {
				snapshot.dirtySnapshotPostSetting.set( false );
			}

			if ( scheduled && snapshot.isFutureDate() ) {
				snapshot.dirtyScheduleDate.set( true );
			} else {
				snapshot.dirtyScheduleDate.set( false );
			}
			snapshot.updateCountdown();
			snapshot.editContainer.find( '.reset-time' ).toggle( scheduled );
		},

		/**
		 * Check if the schedule date is in the future.
		 *
		 * @returns {boolean} True if future date.
		 */
		isFutureDate: function isFutureDate() {
			var snapshot = this,
				date = snapshot.getDateFromInputs(),
				millisecondsDivider = 1000,
				remainingTime;

			if ( ! date ) {
				return false;
			}

			remainingTime = snapshot.dateValueOf( date );
			remainingTime -= snapshot.dateValueOf( snapshot.getCurrentTime() );
			remainingTime = Math.ceil( remainingTime / millisecondsDivider );

			return 0 < remainingTime;
		},

		/**
		 * Get current date/time in the site's timezone.
		 *
		 * Same functionality as the `current_time( 'mysql', false )` function in PHP.
		 *
		 * @returns {string} Current datetime string.
		 */
		getCurrentTime: function getCurrentTime() {
			var snapshot = this,
				currentDate = new Date( snapshot.data.initialServerDate ),
				currentTimestamp = snapshot.dateValueOf(),
				timestampDifferential;

			timestampDifferential = currentTimestamp - snapshot.data.initialClientTimestamp;
			timestampDifferential += snapshot.data.initialClientTimestamp - snapshot.data.initialServerTimestamp;
			currentDate.setTime( currentDate.getTime() + timestampDifferential );

			return snapshot.formatDate( currentDate );
		},

		/**
		 * Get the primitive value of a Date object.
		 *
		 * @param {string|Date} dateString The post status for the snapshot.
		 * @returns {object|string} The primitive value or date object.
		 */
		dateValueOf: function dateValueOf( dateString ) {
			var date;

			if ( 'string' === typeof dateString ) {
				date = new Date( dateString );
			} else if ( dateString instanceof Date ) {
				date = dateString;
			} else {
				date = new Date();
			}

			return date.valueOf();
		},

		/**
		 * Creates select drop down button.
		 *
		 * @return {void}
		 */
		createSelectButton: function createSelectButton() {
			var select, buttonTitle, update, snapshotButton;

			snapshotButton = $( wp.template( 'snapshot-save' )() );
			$( '#save' ).after( snapshotButton );

			select = $( '#snapshot-select-dropdown' );
			buttonTitle = $( '#snapshot-select-button-title' );

			update = (function updateButton() {
				buttonTitle.text( select.find( 'option:selected' ).text() );
				return updateButton;
			})();

			select.on( 'change', update );
		}
	} );

})( wp.customize, jQuery );
