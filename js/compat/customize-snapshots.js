/* global jQuery, _customizeSnapshots */

( function( api, $ ) {
	'use strict';

	var component, escKeyCode = 27;

	component = api.Snapshots || {};

	/**
	 * Amend the preview query so we can update the snapshot during `customize_save`.
	 *
	 * @return {void}
	 */
	component.extendPreviewerQuery = function() {
		var originalQuery = api.previewer.query;

		api.previewer.query = function() {
			var retval = originalQuery.apply( this, arguments );
			if ( api.state( 'snapshot-exists' ).get() ) {
				retval.customize_snapshot_uuid = component.data.uuid;
				if ( component.snapshotTitle && component.snapshotTitle.val() ) {
					retval.title = component.snapshotTitle.val();
				}
			}
			return retval;
		};
	};

	/**
	 * Get the preview URL with the snapshot UUID attached.
	 *
	 * @returns {string} URL.
	 */
	component.getSnapshotFrontendPreviewUrl = function getSnapshotFrontendPreviewUrl() {
		var a = document.createElement( 'a' );
		a.href = component.frontendPreviewUrl.get();
		if ( a.search ) {
			a.search += '&';
		}
		a.search += 'customize_snapshot_uuid=' + component.data.uuid;
		return a.href;
	};

	/**
	 * Create the snapshot buttons.
	 *
	 * @return {void}
	 */
	component.addButtons = function() {
		var header = $( '#customize-header-actions' ),
			publishButton = header.find( '#save' ),
			submitButton, templateData = {}, setPreviewLinkHref;

		component.dirtySnapshotPostSetting = new api.Value();
		component.dirtyScheduleDate = new api.Value();

		// Save/update button.
		if ( api.state( 'snapshot-exists' ).get() ) {
			if ( 'future' === component.data.postStatus ) {
				templateData.buttonText = component.data.i18n.scheduleButton;
			} else {
				templateData.buttonText = component.data.i18n.updateButton;
			}
		} else {
			templateData.buttonText = component.data.i18n.saveButton;
		}
		component.snapshotButton = $( $.trim( wp.template( 'snapshot-save' )( templateData ) ) );
		if ( ! component.data.currentUserCanPublish ) {
			component.snapshotButton.attr( 'title', api.state( 'snapshot-exists' ).get() ? component.data.i18n.permsMsg.update : component.data.i18n.permsMsg.save );
		}
		component.snapshotButton.prop( 'disabled', true );
		component.snapshotButton.insertAfter( publishButton );

		// Preview link.
		component.previewLink = $( $.trim( wp.template( 'snapshot-preview-link' )() ) );
		component.previewLink.toggle( api.state( 'snapshot-saved' ).get() );
		component.previewLink.attr( 'target', component.data.uuid );
		setPreviewLinkHref = _.debounce( function() {
			if ( api.state( 'snapshot-exists' ).get() ) {
				component.previewLink.attr( 'href', component.getSnapshotFrontendPreviewUrl() );
			} else {
				component.previewLink.attr( 'href', component.frontendPreviewUrl.get() );
			}
		} );
		component.frontendPreviewUrl.bind( setPreviewLinkHref );
		setPreviewLinkHref();
		api.state.bind( 'change', setPreviewLinkHref );
		api.bind( 'saved', setPreviewLinkHref );
		component.snapshotButton.after( component.previewLink );
		api.state( 'snapshot-saved' ).bind( function( saved ) {
			component.previewLink.toggle( saved );
		} );

		// Edit button.
		component.snapshotExpandButton = $( $.trim( wp.template( 'snapshot-expand-button' )( {} ) ) );
		component.snapshotExpandButton.insertAfter( component.snapshotButton );

		if ( ! component.data.editLink ) {
			component.snapshotExpandButton.hide();
		}

		api.state( 'change', function() {
			component.snapshotExpandButton.toggle( api.state( 'snapshot-saved' ).get() && api.state( 'snapshot-exists' ).get() );
		} );

		api.state( 'snapshot-exists' ).bind( function( exist ) {
			component.snapshotExpandButton.toggle( exist );
		} );

		api.state( 'snapshot-saved' ).bind( function( saved ) {
			component.snapshotButton.prop( 'disabled', saved );
		} );

		api.state( 'saved' ).bind( function( saved ) {
			if ( saved ) {
				component.snapshotButton.prop( 'disabled', true );
			}
		} );
		api.bind( 'change', function() {
			component.snapshotButton.prop( 'disabled', false );
		} );

		api.state( 'snapshot-exists' ).bind( function( exists ) {
			var buttonText, permsMsg;
			if ( exists ) {
				buttonText = component.data.i18n.updateButton;
				permsMsg = component.data.i18n.permsMsg.update;
			} else {
				buttonText = component.data.i18n.saveButton;
				permsMsg = component.data.i18n.permsMsg.save;
			}

			component.snapshotButton.text( buttonText );
			if ( ! component.data.currentUserCanPublish ) {
				component.snapshotButton.attr( 'title', permsMsg );
			}
		} );

		component.dirtySnapshotPostSetting.bind( function( dirty ) {
			if ( dirty ) {
				component.snapshotButton.prop( 'disabled', false );
			} else {
				component.snapshotButton.prop( 'disabled', ! component.data.dirty );
			}
			component.updateButtonText();
		} );
		component.dirtyScheduleDate.bind( function( dirty ) {
			var date;
			if ( dirty ) {
				date = component.getDateFromInputs();
				if ( ! date || ! component.data.currentUserCanPublish ) {
					return;
				}
				component.snapshotButton.text( component.data.i18n.scheduleButton );
			} else {
				component.updateButtonText();
			}
		} );

		// Submit for review button.
		if ( ! component.data.currentUserCanPublish ) {
			publishButton.hide();
			submitButton = wp.template( 'snapshot-submit' );
			submitButton = $( $.trim( submitButton( {
				buttonText: component.data.i18n.submit
			} ) ) );
			submitButton.prop( 'disabled', ! api.state( 'snapshot-exists' ).get() );
			submitButton.insertBefore( component.snapshotButton );
			api.state( 'snapshot-submitted' ).bind( function( submitted ) {
				submitButton.prop( 'disabled', submitted );
			} );
		}

		header.addClass( 'button-added' );
	};

	/**
	 * Update button text.
	 *
	 * @returns {void}
	 */
	component.updateButtonText = function updateButtonText() {
		var date = component.getDateFromInputs();
		if ( component.isFutureDate() && date && component.data.currentUserCanPublish ) {
			component.snapshotButton.text( component.data.i18n.scheduleButton );
		} else {
			component.snapshotButton.text( api.state( 'snapshot-exists' ).get() ? component.data.i18n.updateButton : component.data.i18n.saveButton );
		}
	};

	/**
	 * Renders snapshot schedule and handles it's events.
	 *
	 * @returns {void}
	 */
	component.editSnapshotUI = function editSnapshotUI() {
		var sliceBegin = 0,
			sliceEnd = -2, updateUI;

		component.snapshotEditContainerDisplayed = new api.Value();

		updateUI = function() {
			component.populateSetting();
		};

		// Inject the UI.
		if ( _.isEmpty( component.editContainer ) ) {
			if ( '0000-00-00 00:00:00' === component.data.publishDate ) {
				component.data.publishDate = component.getCurrentTime();
			}

			// Normalize date with secs set as zeros removed.
			component.data.publishDate = component.data.publishDate.slice( sliceBegin, sliceEnd ) + '00';

			// Extend the components data object and add the parsed datetime strings.
			component.data = _.extend( component.data, component.parseDateTime( component.data.publishDate ) );

			// Add the template to the DOM.
			component.editContainer = $( $.trim( wp.template( 'snapshot-edit-container' )( component.data ) ) );
			component.editContainer.hide().appendTo( $( '#customize-header-actions' ) );

			if ( component.data.currentUserCanPublish ) {

				// Store the date inputs.
				component.schedule.inputs = component.editContainer.find( '.date-input' );

				component.schedule.inputs.on( 'input', updateUI );

				component.schedule.inputs.on( 'blur', function() {
					component.populateInputs();
					component.populateSetting();
				} );

				component.updateCountdown();

				component.editContainer.find( '.reset-time a' ).on( 'click', function( event ) {
					event.preventDefault();
					component.updateSnapshotEditControls();
				} );
			}

			component.snapshotTitle = component.editContainer.find( '#snapshot-title' );
			component.snapshotTitle.on( 'input', updateUI );
		}

		// Set up toggling of the schedule container.
		component.snapshotEditContainerDisplayed.bind( function( isDisplayed ) {
			if ( isDisplayed ) {
				component.editContainer.stop().slideDown( 'fast' ).attr( 'aria-expanded', 'true' );
				component.snapshotExpandButton.attr( 'aria-pressed', 'true' );
				component.snapshotExpandButton.prop( 'title', component.data.i18n.collapseSnapshotScheduling );
			} else {
				component.editContainer.stop().slideUp( 'fast' ).attr( 'aria-expanded', 'false' );
				component.snapshotExpandButton.attr( 'aria-pressed', 'false' );
				component.snapshotExpandButton.prop( 'title', component.data.i18n.expandSnapshotScheduling );
			}
		} );

		// Toggle schedule container when clicking the button.
		component.snapshotExpandButton.on( 'click', function( event ) {
			event.preventDefault();
			component.snapshotEditContainerDisplayed.set( ! component.snapshotEditContainerDisplayed.get() );
		} );

		// Collapse the schedule container when Esc is pressed while the button is focused.
		component.snapshotExpandButton.on( 'keydown', function( event ) {
			if ( escKeyCode === event.which && component.snapshotEditContainerDisplayed.get() ) {
				event.stopPropagation();
				event.preventDefault();
				component.snapshotEditContainerDisplayed.set( false );
			}
		});

		// Collapse the schedule container when Esc is pressed inside of the schedule container.
		component.editContainer.on( 'keydown', function( event ) {
			if ( escKeyCode === event.which && component.snapshotEditContainerDisplayed.get() ) {
				event.stopPropagation();
				event.preventDefault();
				component.snapshotEditContainerDisplayed.set( false );
				component.snapshotExpandButton.focus();
			}
		});

		// Collapse the schedule container interacting outside the schedule container.
		$( 'body' ).on( 'mousedown', function( event ) {
			if ( component.snapshotEditContainerDisplayed.get() && ! $.contains( component.editContainer[0], event.target ) && ! component.snapshotExpandButton.is( event.target ) ) {
				component.snapshotEditContainerDisplayed.set( false );
			}
		});

		component.snapshotEditContainerDisplayed.set( false );

		api.state( 'snapshot-saved' ).bind( function( saved ) {
			if ( saved ) {
				component.updateSnapshotEditControls();
			}
		} );

		api.bind( 'change', function() {
			component.data.dirty = true;
			component.editContainer.find( 'a.snapshot-edit-link' ).hide();
		} );

		api.state( 'saved' ).bind( function( saved ) {
			if ( saved && ! _.isEmpty( component.editContainer ) ) {
				component.data.dirty = false;
				component.data.publishDate = component.getCurrentTime();
				component.snapshotEditContainerDisplayed.set( false );
				component.updateSnapshotEditControls();
			}
		} );

		api.state( 'snapshot-exists' ).bind( function( exists ) {
			if ( exists && ! _.isEmpty( component.editContainer ) ) {
				component.updateSnapshotEditControls();
			} else {
				component.snapshotEditContainerDisplayed.set( false );
			}
		} );
	};

	/**
	 * Updates snapshot schedule with `component.data`.
	 *
	 * @return {void}
	 */
	component.updateSnapshotEditControls = function updateSnapshotEditControls() {
		var parsed,
			sliceBegin = 0,
			sliceEnd = -2;

		if ( _.isEmpty( component.editContainer ) ) {
			return;
		}

		if ( component.data.currentUserCanPublish ) {
			if ( '0000-00-00 00:00:00' === component.data.publishDate ) {
				component.data.publishDate = component.getCurrentTime();
			}

			// Normalize date with seconds removed.
			component.data.publishDate = component.data.publishDate.slice( sliceBegin, sliceEnd ) + '00';
			parsed = component.parseDateTime( component.data.publishDate );

			// Update date controls.
			component.schedule.inputs.each( function() {
				var input = $( this ),
					fieldName = input.data( 'date-input' );

				$( this ).val( parsed[fieldName] );
			} );
		}

		component.editContainer.find( 'a.snapshot-edit-link' )
			.attr( 'href', component.data.editLink )
			.show();
		if ( ! _.isEmpty( component.data.title ) ) {
			component.snapshotTitle.val( component.data.title );
		}
		component.populateSetting();
	};

	/**
	 * Update the scheduled countdown text.
	 *
	 * Hides countdown if post_status is not already future.
	 * Toggles the countdown if there is no remaining time.
	 *
	 * @returns {boolean} True if date inputs are valid.
	 */
	component.updateCountdown = function updateCountdown() {
		var countdown = component.editContainer.find( '.snapshot-scheduled-countdown' ),
			countdownTemplate = wp.template( 'snapshot-scheduled-countdown' ),
			dateTimeFromInput = component.getDateFromInputs(),
			millisecondsDivider = 1000,
			remainingTime;

		if ( ! dateTimeFromInput ) {
			return false;
		}

		remainingTime = dateTimeFromInput.valueOf();
		remainingTime -= component.dateValueOf( component.getCurrentTime() );
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
	};

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
	component.resetSavedStateQuietly = function() {
		api.state( 'saved' )._value = true;
	};

	/**
	 * Make the AJAX request to update/save a snapshot.
	 *
	 * @param {object} options Options.
	 * @param {string} options.status The post status for the snapshot.
	 * @return {void}
	 */
	component.sendUpdateSnapshotRequest = function( options ) {
		var spinner = $( '#customize-header-actions .spinner' ),
			request, data;

		data = _.extend(
			{
				status: 'draft'
			},
			api.previewer.query(),
			options,
			{
				nonce: api.settings.nonce.snapshot,
				customize_snapshot_uuid: component.data.uuid
			}
		);
		request = wp.ajax.post( 'customize_update_snapshot', data );

		spinner.addClass( 'is-active' );
		request.always( function( response ) {
			spinner.removeClass( 'is-active' );
			if ( response.edit_link ) {
				component.data.editLink = response.edit_link;
			}
			if ( response.snapshot_publish_date ) {
				component.data.publishDate = response.snapshot_publish_date;
			}
			if ( response.title ) {
				component.data.title = response.title;
			}
			component.updateSnapshotEditControls();
			component.data.dirty = false;

			// @todo Remove privateness from _handleSettingValidities in Core.
			if ( api._handleSettingValidities && response.setting_validities ) {
				api._handleSettingValidities( {
					settingValidities: response.setting_validities,
					focusInvalidControl: true
				} );
			}
		} );

		request.done( function( response ) {
			var url = api.previewer.previewUrl(),
				regex = new RegExp( '([?&])customize_snapshot_uuid=.*?(&|$)', 'i' ),
				notFound = -1,
				separator = url.indexOf( '?' ) !== notFound ? '&' : '?',
				customizeUrl = window.location.href,
				customizeSeparator = customizeUrl.indexOf( '?' ) !== notFound ? '&' : '?';

			if ( url.match( regex ) ) {
				url = url.replace( regex, '$1customize_snapshot_uuid=' + encodeURIComponent( component.data.uuid ) + '$2' );
			} else {
				url = url + separator + 'customize_snapshot_uuid=' + encodeURIComponent( component.data.uuid );
			}

			// Change the save button text to update.
			api.state( 'snapshot-exists' ).set( true );

			// Replace the history state with an updated Customizer URL that includes the Snapshot UUID.
			if ( history.replaceState && ! customizeUrl.match( regex ) ) {
				customizeUrl += customizeSeparator + 'customize_snapshot_uuid=' + encodeURIComponent( component.data.uuid );
				history.replaceState( {}, document.title, customizeUrl );
			}

			api.state( 'snapshot-saved' ).set( true );
			if ( 'pending' === data.status ) {
				api.state( 'snapshot-submitted' ).set( true );
			}
			component.resetSavedStateQuietly();

			// Trigger an event for plugins to use.
			api.trigger( 'customize-snapshots-update', {
				previewUrl: url,
				customizeUrl: customizeUrl,
				uuid: component.data.uuid,
				response: response
			} );
		} );

		request.fail( function( response ) {
			var id = 'snapshot-dialog-error',
				snapshotDialogShareError = wp.template( id ),
				messages = component.data.i18n.errorMsg,
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
			dialogElement = $( '#' + id );
			if ( ! dialogElement.length ) {
				dialogElement = $( snapshotDialogShareError( {
					title: component.data.i18n.errorTitle,
					message: messages
				} ) );
				$( 'body' ).append( dialogElement );
			}

			// Open the dialog.
			dialogElement.dialog( {
				autoOpen: true,
				modal: true
			} );
		} );
	};

	/**
	 * Get date from inputs.
	 *
	 * @returns {Date|null} Date created from inputs or null if invalid date.
	 */
	component.getDateFromInputs = function getDateFromInputs() {
		var template = component.editContainer,
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
	};

	/**
	 * Parse datetime string.
	 *
	 * @param {string} datetime Date/Time string.
	 * @returns {object|null} Returns object containing date components or null if parse error.
	 */
	component.parseDateTime = function parseDateTime( datetime ) {
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
	};

	/**
	 * Format a Date Object. Returns 'Y-m-d H:i:s' format.
	 *
	 * @props http://stackoverflow.com/questions/10073699/pad-a-number-with-leading-zeros-in-javascript#comment33639551_10073699
	 *
	 * @param {Date} date A Date object.
	 * @returns {string} A formatted date String.
	 */
	component.formatDate = function formatDate( date ) {
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
	};

	/**
	 * Populate inputs from the setting value, if none of them are currently focused.
	 *
	 * @returns {boolean} Whether the inputs were populated.
	 */
	component.populateInputs = function populateInputs() {
		var parsed;

		if ( component.schedule.inputs.is( ':focus' ) || '0000-00-00 00:00:00' === component.data.publishDate ) {
			return false;
		}

		parsed = component.parseDateTime( component.data.publishDate );
		if ( ! parsed ) {
			return false;
		}

		component.schedule.inputs.each( function() {
			var input = $( this ),
				fieldName = input.data( 'date-input' );

			if ( ! $( this ).is( 'select' ) && '' === $( this ).val() ) {
				$( this ).val( parsed[fieldName] );
			}
		} );
		return true;
	};

	/**
	 * Populate setting value from the inputs.
	 *
	 * @returns {void}
	 */
	component.populateSetting = function populateSetting() {
		var date = component.getDateFromInputs(),
			scheduled;

		if ( ! date || ! component.data.currentUserCanPublish ) {
			component.dirtySnapshotPostSetting.set( component.data.title !== component.snapshotTitle.val() );
			return;
		}

		date.setSeconds( 0 );
		scheduled = component.formatDate( date ) !== component.data.publishDate;

		if ( component.data.title !== component.snapshotTitle.val() || scheduled ) {
			component.dirtySnapshotPostSetting.set( true );
		} else {
			component.dirtySnapshotPostSetting.set( false );
		}

		if ( scheduled && component.isFutureDate() ) {
			component.dirtyScheduleDate.set( true );
		} else {
			component.dirtyScheduleDate.set( false );
		}
		component.updateCountdown();
		component.editContainer.find( '.reset-time' ).toggle( scheduled );
	};

	/**
	 * Check if the schedule date is in the future.
	 *
	 * @returns {boolean} True if future date.
	 */
	component.isFutureDate = function isFutureDate() {
		var date = component.getDateFromInputs(),
			millisecondsDivider = 1000,
			remainingTime;

		if ( ! date ) {
			return false;
		}

		remainingTime = component.dateValueOf( date );
		remainingTime -= component.dateValueOf( component.getCurrentTime() );
		remainingTime = Math.ceil( remainingTime / millisecondsDivider );

		return 0 < remainingTime;
	};

	/**
	 * Get current date/time in the site's timezone.
	 *
	 * Same functionality as the `current_time( 'mysql', false )` function in PHP.
	 *
	 * @returns {string} Current datetime string.
	 */
	component.getCurrentTime = function getCurrentTime() {
		var currentDate = new Date( component.data.initialServerDate ),
			currentTimestamp = component.dateValueOf(),
			timestampDifferential;

		timestampDifferential = currentTimestamp - component.data.initialClientTimestamp;
		timestampDifferential += component.data.initialClientTimestamp - component.data.initialServerTimestamp;
		currentDate.setTime( currentDate.getTime() + timestampDifferential );

		return component.formatDate( currentDate );
	};

	/**
	 * Get the primitive value of a Date object.
	 *
	 * @param {string|Date} dateString The post status for the snapshot.
	 * @returns {object|string} The primitive value or date object.
	 */
	component.dateValueOf = function( dateString ) {
		var date;

		if ( 'string' === typeof dateString ) {
			date = new Date( dateString );
		} else if ( dateString instanceof Date ) {
			date = dateString;
		} else {
			date = new Date();
		}

		return date.valueOf();
	};

	api.Snapshots = component;

} )( wp.customize, jQuery );
