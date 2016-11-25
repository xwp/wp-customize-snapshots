/* global jQuery, wp */
/* eslint no-magic-numbers: [ "error", { "ignore": [0,1] } ], consistent-this: [ "error", "snapshot" ] */

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
			snapshot.updatePending = false;

			api.bind( 'ready', function() {
				snapshotExists = '{}' !== api.previewer.query().customized;
				api.state.create( 'snapshot-exists', snapshotExists );
				api.state.create( 'snapshot-saved', true );
				api.state.create( 'snapshot-submitted', true );
				api.state.create( 'snapshot-status', snapshot.data.postStatus );

				snapshot.data.uuid = snapshot.data.uuid || api.settings.changeset.uuid;
				snapshot.data.title = snapshot.data.title || snapshot.data.uuid;

				// Suppress the AYS dialog.
				api.bind( 'changeset-saved', function() {
					if ( 'auto-draft' !== api.state( 'changesetStatus' ).get() ) {
						api.state( 'saved' ).set( true );
					}
				});

				api.bind( 'change', function() {
					api.state( 'snapshot-saved' ).set( false );
					api.state( 'snapshot-submitted' ).set( false );
				} );

				snapshot.frontendPreviewUrl = new api.Value( api.previewer.previewUrl.get() );
				snapshot.frontendPreviewUrl.link( api.previewer.previewUrl );

				snapshot.addButtons();
				snapshot.editSnapshotUI();

				$( '#snapshot-submit' ).on( 'click', function( event ) {
					event.preventDefault();
					snapshot.updateSnapshot( 'pending' );
				} );

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
		 * Update snapshot.
		 *
		 * @param {string} status post status.
		 * @return {void}
		 */
		updateSnapshot: function updateSnapshot( status ) {
			var snapshot = this, scheduleDate,
			    requestData = {
				    status: status
			    };

			if ( snapshot.snapshotTitle && snapshot.snapshotTitle.val() ) {
				requestData.title = snapshot.snapshotTitle.val();
			}

			if ( 'future' === status ) {
				if ( ! _.isEmpty( snapshot.editContainer ) && snapshot.isFutureDate() ) {
					scheduleDate = snapshot.getDateFromInputs();
					requestData.date = snapshot.formatDate( scheduleDate );
					snapshot.sendUpdateSnapshotRequest( requestData );
				}
			} else {
				if ( ! snapshot.isFutureDate() ) {
					requestData.date = snapshot.getCurrentTime();
				}
				snapshot.sendUpdateSnapshotRequest( requestData );
			}
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

			snapshot.updatePending = true;
			snapshot.statusButton.state( 'disabled' ).set( true );
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
				snapshot.statusButton.state( 'disabled' ).set( false );
				snapshot.data.dirty = false;
				snapshot.updatePending = false;
			} );

			request.done( function( response ) {
				var url = api.previewer.previewUrl(),
				    customizeUrl = window.location.href;

				snapshot.saveDraftButton.addClass( 'hidden' );
				snapshot.statusButton.selector.removeClass( 'hidden' );
				snapshot.statusButton.state( 'disabled' ).set( false );

				api.state( 'snapshot-exists' ).set( true );
				api.state( 'snapshot-saved' ).set( true );
				api.state( 'snapshot-status' ).set( data.status );

				if ( 'pending' === data.status ) {
					api.state( 'snapshot-submitted' ).set( true );
				}

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

				// @todo is this required in 4.7?.
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
		 * Create the snapshot buttons.
		 *
		 * @return {void}
		 */
		addButtons: function addButtons() {
			var snapshot = this,
				header = $( '#customize-header-actions' ),
				publishButton = $( '#save' ),
				submitButton, setPreviewLinkHref;

			snapshot.dirtyEditSettings = new api.Values();

			snapshot.statusButton = snapshot.addSelectButton();
			snapshot.saveDraftButton = $( $.trim( wp.template( 'snapshot-save-draft-button' )() ) );

			// Select/save-draft button.
			if ( api.state( 'snapshot-exists' ).get() && 'auto-draft' !== snapshot.data.postStatus ) {
				snapshot.statusButton.selector.removeClass( 'hidden' );
				api.state( 'snapshot-status' ).set( snapshot.data.postStatus );
			} else {
				snapshot.saveDraftButton.removeClass( 'hidden' );
			}

			snapshot.saveDraftButton.prop( 'disabled', true ).on( 'click', function( event ) {
				event.preventDefault();
				snapshot.updateSnapshot( 'draft' );
			} );

			publishButton.after( snapshot.saveDraftButton );

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
			snapshot.statusButton.selector.after( snapshot.previewLink );
			api.state( 'snapshot-saved' ).bind( function( saved ) {
				snapshot.previewLink.toggle( saved );
			} );

			// Edit button.
			snapshot.snapshotExpandButton = $( $.trim( wp.template( 'snapshot-expand-button' )( {} ) ) );
			snapshot.snapshotExpandButton.insertAfter( snapshot.statusButton.selector );

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
				snapshot.saveDraftButton.prop( 'disabled', saved );
			} );

			// @todo remove it since its not longer visible?.
			api.state( 'saved' ).bind( function( saved ) {
				if ( saved ) {
					snapshot.saveDraftButton.prop( 'disabled', true );
				}
			} );

			api.bind( 'change', function() {
				snapshot.saveDraftButton.prop( 'disabled', false );
			} );

			// @todo not required?.
			api.state( 'snapshot-exists' ).bind( function( exists ) {
				var permsMsg;
				if ( ! snapshot.data.currentUserCanPublish ) {
					permsMsg = exists ? snapshot.data.i18n.permsMsg.update : snapshot.data.i18n.permsMsg.save;
					snapshot.statusButton.selector.attr( 'title', permsMsg );
					snapshot.saveDraftButton.attr( 'title', permsMsg );
				}
			} );

			// Submit for review button.
			if ( ! snapshot.data.currentUserCanPublish ) {
				submitButton = wp.template( 'snapshot-submit' );
				submitButton = $( $.trim( submitButton( {
					buttonText: snapshot.data.i18n.submit
				} ) ) );
				submitButton.prop( 'disabled', ! api.state( 'snapshot-exists' ).get() );
				submitButton.insertBefore( snapshot.statusButton.selector );
				api.state( 'snapshot-submitted' ).bind( function( submitted ) {
					submitButton.prop( 'disabled', submitted );
				} );
			}

			header.addClass( 'button-added' );
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
				snapshot.dateNotification = snapshot.editContainer.find( '.snapshot-future-date-notification' );

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
					snapshot.toggleDateNotification();
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
				var isDisplayed = snapshot.snapshotEditContainerDisplayed.get(),
				    isTargetEditContainer = snapshot.editContainer.is( event.target ) || 0 !== snapshot.editContainer.has( event.target ).length,
				    isTargetExpandButton = snapshot.snapshotExpandButton.is( event.target );

				if ( isDisplayed && ! isTargetEditContainer && ! isTargetExpandButton ) {
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

			api.state( 'snapshot-exists' ).bind( function( exists ) {
				if ( exists && ! _.isEmpty( snapshot.editContainer ) ) {
					snapshot.updateSnapshotEditControls();
				} else {
					snapshot.snapshotEditContainerDisplayed.set( false );
				}
			} );

			snapshot.autoSaveEditBox();
		},

		/**
		 * Auto save edit box when the dates are changed.
		 *
		 * @return {void}.
		 */
		autoSaveEditBox: function autoSaveEditor() {
			var snapshot = this, updateSnapshot, delay = 1000;

		    updateSnapshot = _.debounce( function( status ) {
			    snapshot.updateSnapshot( status );
		    }, delay );

			snapshot.dirtyEditSettings.bind( 'date', function( dirty ) {
				if ( ! snapshot.updatePending ) {
					if ( dirty ) {
						updateSnapshot( 'future' );
					}
					if ( ! snapshot.isFutureDate() ) {
						updateSnapshot( 'draft' );
					}
				}
			} );
		},

		/**
		 * Toggles date notification.
		 *
		 * @return {void}.
		 */
		toggleDateNotification: function showDateNotification() {
			var snapshot = this;
			if ( ! _.isEmpty( snapshot.dateNotification ) ) {
				snapshot.dateNotification.toggle( ! snapshot.isFutureDate() );
			}
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
				scheduled, isDirtyTitle, isDirtyDate;

			if ( ! date || ! snapshot.data.currentUserCanPublish ) {
				snapshot.dirtyEditSettings.trigger( 'title', snapshot.data.title !== snapshot.snapshotTitle.val() );
				return;
			}

			date.setSeconds( 0 );
			scheduled = snapshot.formatDate( date ) !== snapshot.data.publishDate;

			isDirtyTitle = snapshot.data.title !== snapshot.snapshotTitle.val();
			snapshot.dirtyEditSettings.trigger( 'title', isDirtyTitle );

			isDirtyDate = scheduled && snapshot.isFutureDate();
			snapshot.dirtyEditSettings.trigger( 'date', isDirtyDate );

			snapshot.updateCountdown();
			snapshot.editContainer.find( '.reset-time' ).toggle( scheduled );
			snapshot.toggleDateNotification();
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
		 * Add select drop down button.
		 *
		 * @return {object} status button.
		 */
		addSelectButton: function addSelectButton() {
			var snapshot = this, statusButton = {},
			    updateButtonText, status;

			statusButton.selector = $( $.trim( wp.template( 'snapshot-status-button' )() ) );
			statusButton.select = statusButton.selector.find( '#snapshot-status-select' );
			statusButton.title = statusButton.selector.find( '#snapshot-status-button-title' );

			updateButtonText = (function update() {
				status = api.state( 'snapshot-status' ).get();
				statusButton.select.val( status );
				statusButton.title.text( statusButton.select.find( 'option:selected' ).text() );
				return update;
			})();

			updateButtonText = _.debounce( updateButtonText );

			statusButton.select.on( 'change', function() {
				status = statusButton.select.val();
				api.state( 'snapshot-status' ).set( status );
				updateButtonText();
				if ( 'future' === status ) {
					snapshot.snapshotEditContainerDisplayed.set( true );
				} else {
					snapshot.updateSnapshot( status );
					snapshot.snapshotEditContainerDisplayed.set( false );
				}
			} );

			api.state( 'snapshot-status' ).bind( 'change', updateButtonText );

			statusButton.select.on( 'click', function() {
			    if ( 'future' === api.state( 'snapshot-status' ).get() ) {
				    snapshot.snapshotEditContainerDisplayed.set( true );
			    }
			} );

			if ( ! snapshot.data.currentUserCanPublish ) {
				statusButton.selector.attr(
					'title',
					api.state( 'snapshot-exists' ).get() ? snapshot.data.i18n.permsMsg.update : snapshot.data.i18n.permsMsg.save
				);
			}

			// @todo Change to api.Value if nothing else required.
			statusButton.state = new api.Values();
			statusButton.state.create( 'disabled' );

			statusButton.state( 'disabled' ).bind( 'change', function( state ) {
				statusButton.selector.attr( 'disabled', state ).find( 'select' ).prop( 'disabled', state );
			} );

			$( '#save' ).after( statusButton.selector );

			return statusButton;
		}
	} );

})( wp.customize, jQuery );
