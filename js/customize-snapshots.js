/* global jQuery, _customizeSnapshots */
/* eslint-disable no-extra-parens */

( function( api, $ ) {
	'use strict';

	var component;

	if ( ! api.Snapshots ) {
		api.Snapshots = {};
	}

	component = api.Snapshots;

	component.data = {
		action: '',
		uuid: '',
		editLink: '',
		snapshotPublishDate: '',
		snapshotStatus: '',
		currentUserCanPublish: '',
		initialServerDate: '',
		initialServerTimestamp: 0,
		initialClientTimestamp: ( new Date() ).valueOf(),
		i18n: {},
		isSnapshotHasUnsavedChanges: false
	};

	if ( 'undefined' !== typeof _customizeSnapshots ) {
		_.extend( component.data, _customizeSnapshots );
	}

	/**
	 * Inject the functionality.
	 *
	 * @return {void}
	 */
	component.init = function() {
		window._wpCustomizeControlsL10n.save = component.data.i18n.publish;
		window._wpCustomizeControlsL10n.saved = component.data.i18n.published;

		api.bind( 'ready', function() {
			api.state.create( 'snapshot-exists', component.data.snapshotExists );
			api.state.create( 'snapshot-saved', true );
			api.state.create( 'snapshot-submitted', true );
			api.bind( 'change', function() {
				api.state( 'snapshot-saved' ).set( false );
				api.state( 'snapshot-submitted' ).set( false );
			} );
			component.frontendPreviewUrl = new api.Value( api.previewer.previewUrl.get() );
			component.frontendPreviewUrl.link( api.previewer.previewUrl );

			component.extendPreviewerQuery();
			component.addButtons();
			component.addSlideDown();

			$( '#snapshot-save' ).on( 'click', function( event ) {
				var scheduleDate;
				event.preventDefault();
				if ( $( this ).html() === component.data.i18n.scheduleButton && ! _.isEmpty( component.snapshotScheduleBox ) && component.getDateFromInputs() && component.isScheduleDateFuture() ) {
					scheduleDate = component.getDateFromInputs();
					component.sendUpdateSnapshotRequest( {
						status: 'future',
						publish_date: component.formatDate( scheduleDate )
					} );
				} else {
					component.sendUpdateSnapshotRequest( { status: 'draft' } );
				}
			} );
			$( '#snapshot-submit' ).on( 'click', function( event ) {
				event.preventDefault();
				component.sendUpdateSnapshotRequest( { status: 'pending' } );
			} );

			if ( api.state( 'snapshot-exists' ).get() ) {
				api.state( 'saved' ).set( false );
				component.resetSavedStateQuietly();
			}

			api.trigger( 'snapshots-ready', component );
		} );

		api.bind( 'save', function( request ) {

			// Make sure that saved state is false so that Published button behaves as expected.
			api.state( 'saved' ).set( false );

			request.fail( function( response ) {
				var id = 'snapshot-dialog-error',
					snapshotDialogPublishError = wp.template( id );

				if ( response.responseText ) {

					// Insert the dialog error template.
					if ( 0 === $( '#' + id ).length ) {
						$( 'body' ).append( snapshotDialogPublishError( {
							title: component.data.i18n.publish,
							message: api.state( 'snapshot-exists' ).get() ? component.data.i18n.permsMsg.update : component.data.i18n.permsMsg.save
						} ) );
					}

					$( '#customize-header-actions .spinner' ).removeClass( 'is-active' );

					// Open the dialog.
					$( '#' + id ).dialog( {
						autoOpen: true,
						modal: true
					} );
				}
			} );
			return request;
		} );

		api.bind( 'saved', function( response ) {
			var url = window.location.href,
				updatedUrl,
				urlParts;

			// Update the UUID.
			if ( response.new_customize_snapshot_uuid ) {
				component.data.uuid = response.new_customize_snapshot_uuid;
				component.previewLink.attr( 'target', component.data.uuid );
			}
			if ( response.edit_link ) {
				component.data.editLink = response.edit_link;
			}

			api.state( 'snapshot-exists' ).set( false );

			// Replace the history state with an updated Customizer URL that does not include the Snapshot UUID.
			urlParts = url.split( '?' );
			if ( history.replaceState && urlParts[1] ) {
				updatedUrl = urlParts[0] + '?' + _.filter( urlParts[1].split( '&' ), function( queryPair ) {
					return ! /^(customize_snapshot_uuid)=/.test( queryPair );
				} ).join( '&' );
				updatedUrl = updatedUrl.replace( /\?$/, '' );
				if ( updatedUrl !== url ) {
					history.replaceState( {}, document.title, updatedUrl );
				}
			}
		} );
	};

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

	component.dateComponentInputs = {};

	component.snapshotScheduleBox = {};

	component.addSlideDown = function slideDown() {
		var snapshotScheduleBoxTemplate = wp.template( 'snapshot-schedule-accordion' ),
			snapshotScheduleBox = component.snapshotScheduleBox;
		component.snapshotSlideDownToggle.click( function( e ) {
			var customizeInfo = $( '#customize-info' );
			if ( _.isEmpty( snapshotScheduleBox ) ) {
				if ( '0000-00-00 00:00:00' === component.data.snapshotPublishDate ) {
					component.data.snapshotPublishDate = component.getCurrentTime();
				}
				component.data = _.extend( component.data, component.parseDateTime( component.data.snapshotPublishDate ) );
				snapshotScheduleBox = $( $.trim( snapshotScheduleBoxTemplate( component.data ) ) );
				snapshotScheduleBox.insertBefore( customizeInfo );
				component.dateInputs = snapshotScheduleBox.find( '.date-input' );
				component.scheduledCountdownContainer = snapshotScheduleBox.find( '.scheduled-countdown' );
				component.resetTimeButton = snapshotScheduleBox.find( '.reset-time' );
				component.resetTimeWrap = snapshotScheduleBox.find( '.wrap-reset-time' );
				component.scheduledCountdownTemplate = wp.template( 'snapshot-scheduled-countdown' );
				component.snapshotScheduleBox = snapshotScheduleBox;
				component.dateInputs.each( function() {
					var input = $( this ), componentName;
					componentName = input.data( 'component' );
					component.dateComponentInputs[componentName] = input;
				} );
				component.snapshotEditLink = snapshotScheduleBox.find( 'a' );
				component.dateInputs.on( 'input', function hydrateInputValues() {
					component.populateSetting();
				} );
				component.dateInputs.on( 'blur', function hydrateInputValues() {
					component.populateInputs();
					component.populateSetting();
				} );
				component.updateScheduledCountdown();
				component.resetTimeButton.on( 'click', function( e ) {
					component.updateSnapshotScheduleBox();
					component.resetTimeWrap.hide();
					e.preventDefault();
				} );
			} else {

				// Todo need to update in case of dynamic section.
				snapshotScheduleBox.slideToggle();
			}
			e.preventDefault();
		} );

		api.state( 'snapshot-saved' ).bind( function( saved ) {
			if ( saved ) {
				component.updateSnapshotScheduleBox();
			}
		} );

		api.bind( 'change', function() {
			component.data.isSnapshotHasUnsavedChanges = true;
		} );

		api.state( 'saved' ).bind( function( saved ) {
			if ( saved && ! _.isEmpty( component.snapshotScheduleBox ) ) {
				component.snapshotScheduleBox.hide();
			}
		} );

		api.state( 'snapshot-exists' ).bind( function( exists ) {
			if ( exists && ! _.isEmpty( component.snapshotScheduleBox ) ) {
				component.updateSnapshotScheduleBox();
			}
		} );

	};

	component.updateSnapshotScheduleBox = function updateSnapshotScheduleBox() {
		var parsed;
		if ( _.isEmpty( component.snapshotScheduleBox ) ) {
			return;
		}
		if ( '0000-00-00 00:00:00' === component.data.snapshotPublishDate ) {
			component.data.snapshotPublishDate = component.getCurrentTime();
		}

		// Update date controls.
		component.snapshotEditLink.attr( 'href', component.data.editLink );
		parsed = component.parseDateTime( component.data.snapshotPublishDate );
		_.each( component.dateComponentInputs, function populateInput( node, component ) {
			$( node ).val( parsed[component] );
		} );
	};

	/**
	 * Create the snapshot buttons.
	 *
	 * @return {void}
	 */
	component.addButtons = function() {
		var header = $( '#customize-header-actions' ),
			publishButton = header.find( '#save' ),
			snapshotSlideDownToggleTemplate = wp.template( 'snapshot-toggle-button' ),
			snapshotButton, submitButton, data, setPreviewLinkHref, snapshotSlideDownToggle, snapshotButtonText;

		// Save/update button.
		snapshotButton = wp.template( 'snapshot-save' );
		if ( api.state( 'snapshot-exists' ).get() ) {
			if ( 'future' === component.data.snapshotStatus ) {
				snapshotButtonText = component.data.i18n.scheduleButton;
			} else {
				snapshotButtonText = component.data.i18n.updateButton;
			}
		} else {
			snapshotButtonText = component.data.i18n.saveButton;
		}
		data = {
			buttonText: snapshotButtonText
		};
		snapshotButton = $( $.trim( snapshotButton( data ) ) );
		if ( ! component.data.currentUserCanPublish ) {
			snapshotButton.attr( 'title', api.state( 'snapshot-exists' ).get() ? component.data.i18n.permsMsg.update : component.data.i18n.permsMsg.save );
		}
		snapshotButton.prop( 'disabled', true );
		snapshotButton.insertAfter( publishButton );

		snapshotSlideDownToggle = $( $.trim( snapshotSlideDownToggleTemplate( {} ) ) );
		snapshotSlideDownToggle.insertAfter( snapshotButton );
		component.snapshotSlideDownToggle = snapshotSlideDownToggle;
		if ( ! component.data.editLink ) {
			snapshotSlideDownToggle.hide();
		}
		api.state.bind( 'change', function() {
			snapshotSlideDownToggle.toggle( api.state( 'snapshot-saved' ).get() && api.state( 'snapshot-exists' ).get() );
		} );

		api.state( 'snapshot-saved' ).bind( function( saved ) {
			snapshotButton.prop( 'disabled', saved );
		} );

		api.state( 'saved' ).bind( function( saved ) {
			if ( saved ) {
				snapshotButton.prop( 'disabled', true );
			}
		} );
		api.bind( 'change', function() {
			snapshotButton.prop( 'disabled', false );
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

			snapshotButton.text( buttonText );
			if ( ! component.data.currentUserCanPublish ) {
				snapshotButton.attr( 'title', permsMsg );
			}
		} );

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
		snapshotButton.after( component.previewLink );
		api.state( 'snapshot-saved' ).bind( function( saved ) {
			component.previewLink.toggle( saved );
		} );

		// Submit for review button.
		if ( ! component.data.currentUserCanPublish ) {
			publishButton.hide();
			submitButton = wp.template( 'snapshot-submit' );
			submitButton = $( $.trim( submitButton( {
				buttonText: component.data.i18n.submit
			} ) ) );
			submitButton.prop( 'disabled', true );
			submitButton.insertBefore( snapshotButton );
			api.state( 'snapshot-submitted' ).bind( function( submitted ) {
				submitButton.prop( 'disabled', submitted );
			} );
		}

		header.addClass( 'button-added' );
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
			request, data, args;

		args = _.extend(
			{
				status: 'draft'
			},
			options
		);

		data = _.extend( args, {
			nonce: api.settings.nonce.snapshot,
			customize_snapshot_uuid: component.data.uuid
		} );

		data = _.extend(
			{},
			api.previewer.query(),
			data
		);
		request = wp.ajax.post( 'customize_update_snapshot', data );

		spinner.addClass( 'is-active' );
		request.always( function( response ) {
			spinner.removeClass( 'is-active' );
			if ( response.edit_link ) {
				component.data.editLink = response.edit_link;
			}
			if ( response.snapshot_publish_date ) {
				component.data.snapshotPublishDate = response.snapshot_publish_date;
			}

			// @todo Remove privateness from _handleSettingValidities in Core.
			if ( api._handleSettingValidities && response.setting_validities ) {
				api._handleSettingValidities( {
					settingValidities: response.setting_validities,
					focusInvalidControl: true
				} );
			}
		} );

		request.done( function() {
			var url = api.previewer.previewUrl(),
				regex = new RegExp( '([?&])customize_snapshot_uuid=.*?(&|$)', 'i' ),
				separator = url.indexOf( '?' ) !== -1 ? '&' : '?',
				customizeUrl = window.location.href,
				customizeSeparator = customizeUrl.indexOf( '?' ) !== -1 ? '&' : '?';

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
			if ( 'pending' === args.status ) {
				api.state( 'snapshot-submitted' ).set( true );
			}
			component.resetSavedStateQuietly();

			// Trigger an event for plugins to use.
			api.trigger( 'customize-snapshots-update', {
				previewUrl: url,
				customizeUrl: customizeUrl,
				uuid: component.data.uuid
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
		var control = component, date;
		date = new Date(
			parseInt( control.dateComponentInputs.year.val(), 10 ),
			parseInt( control.dateComponentInputs.month.val(), 10 ) - 1,
			parseInt( control.dateComponentInputs.day.val(), 10 ),
			parseInt( control.dateComponentInputs.hour.val(), 10 ),
			parseInt( control.dateComponentInputs.minute.val(), 10 )
		);
		if ( isNaN( date.valueOf() ) ) {
			return null;
		}
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
	 * @param {Date} date A Date object.
	 * @returns {string} A formatted date String.
	 */
	component.formatDate = function formatDate( date ) {
		var formattedDate, yearLength = 4, nonYearLength = 2;

		// Props: http://stackoverflow.com/questions/10073699/pad-a-number-with-leading-zeros-in-javascript#comment33639551_10073699
		formattedDate = ( '0000' + date.getFullYear() ).substr( -yearLength, yearLength );
		formattedDate += '-' + ( '00' + ( date.getMonth() + 1 ) ).substr( -nonYearLength, nonYearLength );
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
		var parsed, setComponentInputValue;
		if ( component.dateInputs.is( ':focus' ) || '0000-00-00 00:00:00' === component.data.snapshotPublishDate ) {
			return false;
		}
		parsed = component.parseDateTime( component.data.snapshotPublishDate );
		if ( ! parsed ) {
			return false;
		}
		setComponentInputValue = function( value, inputName ) {
			var input = component.dateComponentInputs[inputName];
			if ( input && ! input.is( 'select' ) && '' === input.val() ) {
				input.val( value );
			}
		};
		_.each( parsed, setComponentInputValue );
		return true;
	};

	/**
	 * Populate setting value from the inputs.
	 *
	 * @returns {boolean} Whether the date inputs currently represent a valid date.
	 */
	component.populateSetting = function populateSetting() {
		var date, save, isScheduleDateUpdated;
		date = component.getDateFromInputs();
		if ( ! date ) {
			return false;
		} else {
			save = $( '#snapshot-save' );
			isScheduleDateUpdated = component.formatDate( date ) !== component.data.snapshotPublishDate;
			if ( component.isScheduleDateFuture() ) {

				// Change update button to schedule.
				if ( save.length ) {
					save.html( component.data.i18n.scheduleButton );
					if ( isScheduleDateUpdated || component.data.isSnapshotHasUnsavedChanges ) {
						save.prop( 'disabled', false );
					} else {
						save.prop( 'disabled', true );
					}
				}
			} else {
				if ( save.length ) {
					save.html( component.data.i18n.updateButton );
					save.prop( 'disabled', ! component.data.isSnapshotHasUnsavedChanges );
				}
			}
			component.updateScheduledCountdown();
			date.setSeconds( 0 );
			if ( isScheduleDateUpdated ) {
				component.resetTimeWrap.show();
			} else {
				component.resetTimeWrap.hide();
			}
		}
	};

	/**
	 * Check if snapshot schedule date is in future date
	 * @returns {boolean}
	 */
	component.isScheduleDateFuture = function isScheduleDateFuture() {
		var date, remainingTime;
		date = component.getDateFromInputs();
		if ( ! date ) {
			return false;
		}
		remainingTime = ( new Date( date ) ).valueOf();
		remainingTime -= ( new Date( component.getCurrentTime() ) ).valueOf();
		remainingTime = Math.ceil( remainingTime / 1000 );
		return remainingTime > 0;
	};

	/**
	 * Get current date/time in the site's timezone, as does the current_time( 'mysql', false ) function in PHP.
	 *
	 * @returns {string} Current datetime string.
	 */
	component.getCurrentTime = function getCurrentTime() {
		var currentDate, currentTimestamp, timestampDifferential;
		currentTimestamp = ( new Date() ).valueOf();
		currentDate = new Date( component.data.initialServerDate );
		timestampDifferential = currentTimestamp - component.data.initialClientTimestamp;
		timestampDifferential += component.data.initialClientTimestamp - component.data.initialServerTimestamp;
		currentDate.setTime( currentDate.getTime() + timestampDifferential );
		return component.formatDate( currentDate );
	};

	/**
	 * Update the scheduled countdown.
	 *
	 * Hides countdown if post_status is not already future.
	 * Toggles the countdown if there is no remaining time.
	 *
	 * @returns {void}
	 */
	component.updateScheduledCountdown = function updateScheduledCountdown() {
		var remainingTime;
		remainingTime = component.getDateFromInputs().valueOf();
		remainingTime -= ( new Date( component.getCurrentTime() ) ).valueOf();
		remainingTime = Math.ceil( remainingTime / 1000 );
		if ( remainingTime > 0 ) {
			component.scheduledCountdownContainer.text( component.scheduledCountdownTemplate( { remainingTime: remainingTime } ) );
			component.scheduledCountdownContainer.show();
		} else {
			component.scheduledCountdownContainer.hide();
		}
	};

	component.init();

} )( wp.customize, jQuery );
