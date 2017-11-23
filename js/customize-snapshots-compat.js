/* global jQuery, wp, JSON */
/* eslint no-magic-numbers: [ "error", { "ignore": [0,1,-1] } ], consistent-this: [ "error", "snapshot" ] */

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
			previewingTheme: '',
			i18n: {},
			dirty: false
		},

		uuidParam: 'customize_changeset_uuid',

		initialize: function initialize( snapshotsConfig ) {
			var snapshot = this;

			snapshot.schedule = {};

			if ( _.isObject( snapshotsConfig ) ) {
				_.extend( snapshot.data, snapshotsConfig );
			}

			// Set the initial client timestamp.
			snapshot.data.initialClientTimestamp = snapshot.dateValueOf();

			api.bind( 'ready', function() {
				api.state.create( 'snapshot-exists', false );
				api.state.create( 'snapshot-saved', true );
				api.state.create( 'snapshot-submitted', true );

				snapshot.data.uuid = snapshot.data.uuid || api.settings.changeset.uuid;
				snapshot.data.title = snapshot.data.title || snapshot.data.uuid;

				snapshot.editBoxAutoSaveTriggered = false;

				if ( api.state.has( 'changesetStatus' ) && api.state( 'changesetStatus' ).get() ) {
					api.state( 'snapshot-exists' ).set( true );
				}

				snapshot.extendPreviewerQuery();

				snapshot.editControlSettings = new api.Values();
				snapshot.editControlSettings.create( 'title', snapshot.data.title );
				snapshot.editControlSettings.create( 'date', snapshot.data.publishDate );

				api.bind( 'change', function() {
					api.state( 'snapshot-submitted' ).set( false );
				} );

				snapshot.frontendPreviewUrl = new api.Value( api.previewer.previewUrl.get() );
				snapshot.frontendPreviewUrl.link( api.previewer.previewUrl );
				snapshot.isNotSavedPreviewingTheme = false;

				snapshot.addButtons();
				snapshot.editSnapshotUI();
				snapshot.prefilterAjax();

				api.trigger( 'snapshots-ready', snapshot );
			} );

			api.bind( 'save', function( request ) {

				request.fail( function( response ) {
					var id = '#snapshot-dialog-error',
						snapshotDialogPublishError = wp.template( 'snapshot-dialog-error' );

					if ( response.responseText ) {

						// Insert the dialog error template.
						if ( 0 === $( id ).length ) {
							$( 'body' ).append( snapshotDialogPublishError( {
								title: snapshot.data.i18n.publish,
								message: api.state( 'snapshot-exists' ).get() ? snapshot.data.i18n.permsMsg.update : snapshot.data.i18n.permsMsg.save
							} ) );
						}

						snapshot.spinner.removeClass( 'is-active' );

						$( id ).dialog( {
							autoOpen: true,
							modal: true
						} );
					}
				} );

				return request;
			} );
		},

		/**
		 * Get state query vars.
		 *
		 * @return {{}} Query vars for scroll, device, url, and autofocus.
		 */
		getStateQueryVars: function() {
			var snapshot = this, queryVars;

			queryVars = {
				'autofocus[control]': null,
				'autofocus[section]': null,
				'autofocus[panel]': null
			};

			queryVars.scroll = parseInt( api.previewer.scroll, 10 ) || 0;
			queryVars.device = api.previewedDevice.get();
			queryVars.url = api.previewer.previewUrl.get();

			if ( ! api.state( 'activated' ).get() || snapshot.isNotSavedPreviewingTheme ) {
				queryVars.previewing_theme = true;
			}

			_.find( [ 'control', 'section', 'panel' ], function( constructType ) {
				var found = false;
				api[ constructType ].each( function( construct ) { // @todo Core needs to support more Backbone methods on wp.customize.Values().
					if ( ! found && construct.expanded && construct.expanded.get() ) {
						queryVars[ 'autofocus[' + constructType + ']' ] = construct.id;
						found = true;
					}
				} );
				return found;
			} );

			return queryVars;
		},

		/**
		 * Update snapshot.
		 *
		 * @param {string} status post status.
		 * @returns {jQuery.promise} Request or promise.
		 */
		updateSnapshot: function updateSnapshot( status ) {
			var snapshot = this, inputDate,
				deferred = new $.Deferred(),
				request,
				requestData = {
					status: status
				};

			if ( snapshot.statusButton && snapshot.statusButton.needConfirm ) {
				snapshot.statusButton.disbleButton.set( false );
				snapshot.statusButton.updateButtonText( 'confirm-text' );
				snapshot.statusButton.needConfirm = false;
				return deferred.promise();
			}

			if ( snapshot.snapshotTitle && snapshot.snapshotTitle.val() && 'publish' !== status ) {
				requestData.title = snapshot.editControlSettings( 'title' ).get();
			}

			if ( ! _.isEmpty( snapshot.editContainer ) && snapshot.isFutureDate() && 'publish' !== status ) {
				inputDate = snapshot.getDateFromInputs();
				requestData.date = snapshot.formatDate( inputDate );
			}

			if ( 'future' === status ) {
				if ( requestData.date ) {
					request = snapshot.sendUpdateSnapshotRequest( requestData );
				}
			} else {
				request = snapshot.sendUpdateSnapshotRequest( requestData );
			}

			return request ? request : deferred.promise();
		},

		/**
		 * Make the AJAX request to update/save a snapshot.
		 *
		 * @param {object} options Options.
		 * @param {string} options.status The post status for the snapshot.
		 * @return {object} request.
		 */
		sendUpdateSnapshotRequest: function sendUpdateSnapshotRequest( options ) {
			var snapshot = this,
				request, data, isPublishStatus;

			data = _.extend(
				{
					status: 'draft'
				},
				options
			);

			api.state( 'snapshot-saved' ).set( false );
			snapshot.statusButton.disable( true );
			snapshot.spinner.addClass( 'is-active' );

			request = api.previewer.save( data );

			isPublishStatus = 'publish' === data.status;

			request.always( function( response ) {
				snapshot.spinner.removeClass( 'is-active' );
				if ( response.edit_link ) {
					snapshot.data.editLink = response.edit_link;
				}
				if ( response.publish_date ) {
					snapshot.data.publishDate = response.publish_date;
				}
				if ( response.title ) {
					snapshot.data.title = response.title;
				}

				snapshot.data.dirty = false;
			} );

			request.done( function( response ) {
				var url = api.previewer.previewUrl(),
					customizeUrl = window.location.href,
					savedDelay = 400;

				/***
				 * Delay because api.Posts.updateSettingsQuietly updates the settings after save, which triggers
				 * api change causing the publish button to get enabled again.
				 */
				_.delay( function() {
					api.state( 'snapshot-saved' ).set( true );
					if ( 'pending' === data.status ) {
						api.state( 'snapshot-submitted' ).set( true );
					}
				}, savedDelay );

				api.state( 'snapshot-exists' ).set( ! isPublishStatus );

				snapshot.statusButton.disableSelect.set( isPublishStatus );
				snapshot.statusButton.disbleButton.set( true );
				snapshot.snapshotExpandButton.toggle( ! isPublishStatus );
				snapshot.previewLink.toggle( ! isPublishStatus );

				if ( isPublishStatus ) {
					snapshot.removeParamFromClose( 'customize_changeset_uuid' );
				}

				snapshot.statusButton.updateButtonText( 'alt-text' );

				// Trigger an event for plugins to use.
				api.trigger( 'customize-snapshots-update', {
					previewUrl: url,
					customizeUrl: customizeUrl,
					uuid: snapshot.data.uuid,
					response: response
				} );
			} );

			request.fail( function( response ) {
				var id = '#snapshot-dialog-error',
					snapshotDialogShareError = wp.template( 'snapshot-dialog-error' ),
					messages = snapshot.data.i18n.errorMsg,
					invalidityCount = 0,
					dialogElement;

				snapshot.statusButton.disableSelect.set( false );
				snapshot.statusButton.disbleButton.set( false );

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
				dialogElement = $( id );
				if ( ! dialogElement.length ) {
					dialogElement = $( snapshotDialogShareError( {
						title: snapshot.data.i18n.errorTitle,
						message: messages
					} ) );
					$( 'body' ).append( dialogElement );
				}

				// Open the dialog.
				$( id ).dialog( {
					autoOpen: true,
					modal: true
				} );
			} );

			return request;
		},

		/**
		 * Create the snapshot buttons.
		 *
		 * @return {void}
		 */
		addButtons: function addButtons() {
			var snapshot = this, disableButton = true, disableSelectButton = true,
				setPreviewLinkHref, currentTheme, savedPreviewingTheme, themeNotActiveOrSaved;

			snapshot.spinner = $( '#customize-header-actions' ).find( '.spinner' );
			snapshot.publishButton = $( '#save' );

			snapshot.publishButton.addClass( 'hidden' );
			snapshot.statusButton = snapshot.addStatusButton();

			if ( api.state( 'changesetStatus' ).get() ) {
				disableSelectButton = false;
				if ( 'auto-draft' === api.state( 'changesetStatus' ).get() ) {
					disableButton = false;
				} else {
					snapshot.statusButton.updateButtonText( 'alt-text' );
				}
			}

			currentTheme = api.settings.theme.stylesheet; // Or previewing theme.
			savedPreviewingTheme = snapshot.data.previewingTheme;
			themeNotActiveOrSaved = ! api.state( 'activated' ).get() && ! savedPreviewingTheme;
			snapshot.isNotSavedPreviewingTheme = savedPreviewingTheme && savedPreviewingTheme !== currentTheme;

			if ( themeNotActiveOrSaved || snapshot.isNotSavedPreviewingTheme ) {
				disableButton = false;
				disableSelectButton = false;
			}

			snapshot.statusButton.disbleButton.set( disableButton );
			snapshot.statusButton.disableSelect.set( disableSelectButton );

			// Preview link.
			snapshot.previewLink = $( $.trim( wp.template( 'snapshot-preview-link' )() ) );
			snapshot.previewLink.toggle( api.state( 'snapshot-saved' ).get() );
			snapshot.previewLink.attr( 'target', snapshot.data.uuid );
			setPreviewLinkHref = _.debounce( function() {
				var queryVars;
				if ( api.state( 'snapshot-exists' ).get() ) {
					snapshot.previewLink.attr( 'href', snapshot.getSnapshotFrontendPreviewUrl() );
				} else {
					snapshot.previewLink.attr( 'href', snapshot.frontendPreviewUrl.get() );
				}

				// Add the customize_theme param to the frontend URL if the theme is not active.
				if ( ! api.state( 'activated' ).get() ) {
					queryVars = snapshot.parseQueryString( snapshot.previewLink.prop( 'search' ).substr( 1 ) );
					queryVars.customize_theme = api.settings.theme.stylesheet;
					snapshot.previewLink.prop( 'search', $.param( queryVars ) );
				}
			} );
			snapshot.frontendPreviewUrl.bind( setPreviewLinkHref );
			setPreviewLinkHref();
			api.state.bind( 'change', setPreviewLinkHref );
			api.bind( 'saved', setPreviewLinkHref );
			snapshot.statusButton.container.after( snapshot.previewLink );
			api.state( 'snapshot-saved' ).bind( function( saved ) {
				snapshot.previewLink.toggle( saved );
			} );

			// Edit button.
			snapshot.snapshotExpandButton = $( $.trim( wp.template( 'snapshot-expand-button' )( {} ) ) );
			snapshot.statusButton.container.after( snapshot.snapshotExpandButton );

			if ( ! snapshot.data.editLink ) {
				snapshot.snapshotExpandButton.hide();
				snapshot.previewLink.hide();
			}

			api.state( 'change', function() {
				snapshot.snapshotExpandButton.toggle( api.state( 'snapshot-saved' ).get() && api.state( 'snapshot-exists' ).get() );
			} );

			api.state( 'snapshot-exists' ).bind( function( exist ) {
				snapshot.snapshotExpandButton.toggle( exist );
				snapshot.previewLink.toggle( exist );
			} );

			api.bind( 'change', function() {
				if ( api.state( 'snapshot-saved' ).get() ) {
					snapshot.statusButton.disable( false );
					if ( snapshot.statusButton.button.data( 'confirm-text' ) !== snapshot.statusButton.buttonText.get() ) {
						snapshot.statusButton.updateButtonText( 'button-text' );
					}
					if ( snapshot.submitButton ) {
						snapshot.submitButton.prop( 'disabled', false );
					}
					if ( snapshot.saveButton ) {
						snapshot.saveButton.prop( 'disabled', false );
					}
					api.state( 'snapshot-saved' ).set( false );
				}
			} );

			if ( ! snapshot.data.currentUserCanPublish ) {
				snapshot.addSubmitButton();
				snapshot.addSaveButton();
			}
		},

		/**
		 * Adds Submit Button when user does not have 'customize_publish' permission.
		 *
		 * @return {void}
		 */
		addSubmitButton: function() {
			var snapshot = this, disableSubmitButton;

			disableSubmitButton = 'pending' === snapshot.data.postStatus || ! api.state( 'snapshot-exists' ).get();

			if ( snapshot.statusButton ) {
				snapshot.statusButton.container.hide();
			} else {
				snapshot.publishButton.hide();
			}

			snapshot.submitButton = $( $.trim( wp.template( 'snapshot-submit' )( {
				buttonText: snapshot.data.i18n.submit
			} ) ) );

			snapshot.submitButton.prop( 'disabled', disableSubmitButton );
			snapshot.submitButton.insertBefore( snapshot.publishButton );
			api.state( 'snapshot-submitted' ).bind( function( submitted ) {
				snapshot.submitButton.prop( 'disabled', submitted );
			} );

			snapshot.submitButton.on( 'click', function( event ) {
				event.preventDefault();
				snapshot.submitButton.prop( 'disabled', true );
				if ( snapshot.saveButton ) {
					snapshot.saveButton.prop( 'disabled', true );
				}
				snapshot.updateSnapshot( 'pending' ).fail( function() {
					snapshot.submitButton.prop( 'disabled', false );
				} );
			} );

			snapshot.editControlSettings.bind( 'change', function() {
				if ( api.state( 'snapshot-saved' ).get() ) {
					snapshot.submitButton.prop( 'disabled', false );
				}
			} );
		},

		/**
		 * Adds Save Button when user does not have 'customize_publish' permission.
		 *
		 * @return {void}
		 */
		addSaveButton: function() {
			var snapshot = this, disableSaveButton, isSaved;

			isSaved = _.contains( [ 'future', 'pending', 'draft' ], api.state( 'changesetStatus' ).get() );
			disableSaveButton = isSaved || ! api.state( 'snapshot-exists' ).get();

			snapshot.saveButton = $( $.trim( wp.template( 'snapshot-save' )( {
				buttonText: isSaved ? snapshot.data.i18n.updateButton : snapshot.data.i18n.saveButton
			} ) ) );

			snapshot.saveButton.prop( 'disabled', disableSaveButton );
			snapshot.saveButton.insertBefore( snapshot.publishButton );

			api.state( 'snapshot-submitted' ).bind( function( submitted ) {
				if ( submitted ) {
					snapshot.saveButton.prop( 'disabled', true );
				}
			} );

			snapshot.saveButton.on( 'click', function( event ) {
				event.preventDefault();
				snapshot.saveButton.prop( 'disabled', true );
				snapshot.submitButton.prop( 'disabled', true );
				snapshot.updateSnapshot( 'draft' ).done( function() {
					snapshot.saveButton.prop( 'disabled', true );
					snapshot.submitButton.prop( 'disabled', false );
					snapshot.saveButton.text( snapshot.data.i18n.updateButton );
				} ).fail( function() {
					snapshot.saveButton.prop( 'disabled', false );
					snapshot.submitButton.prop( 'disabled', false );
				} );
			} );

			snapshot.editControlSettings.bind( 'change', function() {
				if ( api.state( 'snapshot-saved' ).get() ) {
					snapshot.saveButton.prop( 'disabled', false );
				}
			} );
		},

		/**
		 * Renders snapshot schedule and handles it's events.
		 *
		 * @returns {void}
		 */
		editSnapshotUI: function editSnapshotUI() {
			var snapshot = this, sliceBegin = 0,
				sliceEnd = -2, updateUI, toggleDateNotification;

			snapshot.snapshotEditContainerDisplayed = new api.Value( false );

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
				snapshot.countdown = snapshot.editContainer.find( '.snapshot-scheduled-countdown' );
				snapshot.dateControl = snapshot.editContainer.find( '.snapshot-control-date' );

				if ( snapshot.data.currentUserCanPublish ) {

					// Store the date inputs.
					snapshot.schedule.inputs = snapshot.editContainer.find( '.date-input' );

					snapshot.schedule.inputs.on( 'input', updateUI );

					snapshot.schedule.inputs.on( 'blur', function() {
						snapshot.populateInputs();
						updateUI();
					} );

					snapshot.updateCountdown();

					snapshot.editContainer.find( '.reset-time a' ).on( 'click', function( event ) {
						event.preventDefault();
						snapshot.updateSnapshotEditControls();
					} );
				}

				if ( snapshot.statusButton && 'future' !== snapshot.statusButton.value.get() ) {
					snapshot.countdown.hide();
				}

				snapshot.snapshotTitle = snapshot.editContainer.find( '#snapshot-title' );
				snapshot.snapshotTitle.on( 'input', updateUI );
			}

			toggleDateNotification = function() {
				if ( ! _.isEmpty( snapshot.dateNotification ) ) {
					snapshot.dateNotification.toggle( ! snapshot.isFutureDate() );
				}
			};

			// Set up toggling of the schedule container.
			snapshot.snapshotEditContainerDisplayed.bind( function( isDisplayed ) {

				if ( snapshot.statusButton ) {
					snapshot.dateControl.toggle( 'future' === snapshot.statusButton.value.get() );
				}

				if ( isDisplayed ) {
					snapshot.editContainer.stop().slideDown( 'fast' ).attr( 'aria-expanded', 'true' );
					snapshot.snapshotExpandButton.attr( 'aria-pressed', 'true' );
					snapshot.snapshotExpandButton.prop( 'title', snapshot.data.i18n.collapseSnapshotScheduling );
					toggleDateNotification();
				} else {
					snapshot.editContainer.stop().slideUp( 'fast' ).attr( 'aria-expanded', 'false' );
					snapshot.snapshotExpandButton.attr( 'aria-pressed', 'false' );
					snapshot.snapshotExpandButton.prop( 'title', snapshot.data.i18n.expandSnapshotScheduling );
				}
			} );

			snapshot.editControlSettings( 'date' ).bind( function() {
				toggleDateNotification();
			} );

			// Toggle schedule container when clicking the button.
			snapshot.snapshotExpandButton.on( 'click', function() {
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
				if ( saved && ! snapshot.dirtyEditControlValues ) {
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

			if ( snapshot.statusButton ) {
				snapshot.updateSnapshotEditControls();
			}

			snapshot.autoSaveEditBox();
		},

		/**
		 * Auto save the edit box values.
		 *
		 * @return {void}
		 */
		autoSaveEditBox: function() {
			var snapshot = this, update,
				delay = 2000, status, isFutureDateAndStatus;

			snapshot.updatePending = false;
			snapshot.dirtyEditControlValues = false;

			update = _.debounce( function() {
				status = snapshot.statusButton.value.get();
				isFutureDateAndStatus = 'future' === status && ! snapshot.isFutureDate();
				if ( 'publish' === status || isFutureDateAndStatus ) {
					snapshot.updatePending = false;
					return;
				}
				snapshot.updatePending = true;
				snapshot.editBoxAutoSaveTriggered = true;
				snapshot.dirtyEditControlValues = false;
				snapshot.updateSnapshot( status ).done( function() {
					snapshot.updatePending = snapshot.dirtyEditControlValues;
					if ( ! snapshot.updatePending ) {
						snapshot.updateSnapshotEditControls();
					} else if ( snapshot.dirtyEditControlValues ) {
						update();
					}
					snapshot.dirtyEditControlValues = false;
				} ).fail( function() {
					snapshot.updatePending = false;
					snapshot.dirtyEditControlValues = true;
				} );
			}, delay );

			snapshot.editControlSettings( 'title' ).bind( function() {
				snapshot.dirtyEditControlValues = true;
				if ( ! snapshot.updatePending ) {
					update();
				}
			} );

			snapshot.editControlSettings( 'date' ).bind( function() {
				if ( snapshot.isFutureDate() ) {
					snapshot.dirtyEditControlValues = true;
					if ( ! snapshot.updatePending ) {
						update();
					}
				}
			} );

			$( window ).on( 'beforeunload.customize-confirm', function() {
				if ( snapshot.updatePending || snapshot.dirtyEditControlValues ) {
					return snapshot.data.i18n.aysMsg;
				}
				return undefined;
			} );

			// @todo Show loader and disable button while auto saving.

			api.bind( 'changeset-saved', function() {
				if ( 'auto-draft' !== api.state( 'changesetStatus' ).get() ) {
					api.state( 'saved' ).set( true ); // Suppress the AYS dialog.
				}
			});
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
				status,
				sliceBegin = 0,
				sliceEnd = -2;

			if ( _.isEmpty( snapshot.editContainer ) ) {
				return;
			}

			status = api.state( 'changesetStatus' ).get();

			if ( snapshot.data.currentUserCanPublish ) {
				if ( '0000-00-00 00:00:00' === snapshot.data.publishDate || ! status || 'auto-draft' === status ) {
					snapshot.data.publishDate = snapshot.getCurrentTime();
				}

				// Normalize date with seconds removed.
				snapshot.data.publishDate = snapshot.data.publishDate.slice( sliceBegin, sliceEnd ) + '00';
				parsed = snapshot.parseDateTime( snapshot.data.publishDate );

				// Update date controls.
				snapshot.schedule.inputs.each( function() {
					var input = $( this ),
						fieldName = input.data( 'date-input' );
					$( this ).val( parsed[ fieldName ] );
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
			var snapshot = this,
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
				snapshot.countdown.text( countdownTemplate( {
					remainingTime: remainingTime
				} ) );
				snapshot.countdown.show();
			} else {
				snapshot.countdown.hide();
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

			formattedDate = ( '0000' + String( date.getFullYear() ) ).substr( -yearLength, yearLength );
			formattedDate += '-' + ( '00' + String( date.getMonth() + monthOffset ) ).substr( -nonYearLength, nonYearLength );
			formattedDate += '-' + ( '00' + String( date.getDate() ) ).substr( -nonYearLength, nonYearLength );
			formattedDate += ' ' + ( '00' + String( date.getHours() ) ).substr( -nonYearLength, nonYearLength );
			formattedDate += ':' + ( '00' + String( date.getMinutes() ) ).substr( -nonYearLength, nonYearLength );
			formattedDate += ':' + ( '00' + String( date.getSeconds() ) ).substr( -nonYearLength, nonYearLength );

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

			snapshot.editControlSettings( 'title' ).set( snapshot.snapshotTitle.val() );

			if ( ! date || ! snapshot.data.currentUserCanPublish ) {
				return;
			}

			date.setSeconds( 0 );
			scheduled = snapshot.formatDate( date ) !== snapshot.data.publishDate;
			snapshot.editControlSettings( 'date' ).set( snapshot.formatDate( date ) );

			if ( 'future' === snapshot.statusButton.value.get() ) {
				snapshot.updateCountdown();
			}

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
			var snapshot = this, currentDate, currentTimestamp, timestampDifferential;
			currentTimestamp = ( new Date() ).valueOf();
			currentDate = snapshot.parsePostDate( snapshot.data.initialServerDate );
			timestampDifferential = currentTimestamp - snapshot.data.initialClientTimestamp;
			timestampDifferential += snapshot.data.initialClientTimestamp - snapshot.data.initialServerTimestamp;
			currentDate.setTime( currentDate.getTime() + timestampDifferential );
			return snapshot.formatDate( currentDate );
		},

		/**
		 * Parse post date string in YYYY-MM-DD HH:MM:SS format (local timezone).
		 *
		 * @param {string} postDate Post date string.
		 * @returns {Date} Parsed date.
		 */
		parsePostDate: function parsePostDate( postDate ) {
			var dateParts = _.map( postDate.split( /\D/ ), function( datePart ) {
				return parseInt( datePart, 10 );
			} );
			return new Date( dateParts[0], dateParts[1] - 1, dateParts[2], dateParts[3], dateParts[4], dateParts[5] ); // eslint-disable-line no-magic-numbers
		},

		/**
		 * Get the primitive value of a Date object.
		 *
		 * @param {string|Date} [dateString] The post status for the snapshot.
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
		 * Amend the preview query so we can update the snapshot during `changeset_save`.
		 *
		 * @return {void}
		 */
		extendPreviewerQuery: function extendPreviewerQuery() {
			var snapshot = this, originalQuery = api.previewer.query;

			api.previewer.query = function() {
				var retval = originalQuery.apply( this, arguments );

				if ( ! _.contains( [ 'future', 'pending', 'draft' ], snapshot.statusButton.value.get() ) ) {
					return retval;
				}

				retval.customizer_state_query_vars = JSON.stringify( snapshot.getStateQueryVars() );

				if ( snapshot.editControlSettings( 'title' ).get() ) {
					retval.customize_changeset_title = snapshot.editControlSettings( 'title' ).get();
				}
				if ( snapshot.editControlSettings( 'date' ).get() && snapshot.isFutureDate() ) {
					retval.customize_changeset_date = snapshot.editControlSettings( 'date' ).get();
				}
				return retval;
			};
		},

		/**
		 * Add status button.
		 *
		 * @return {object} status button.
		 */
		addStatusButton: function addStatusButton() {
			var snapshot = this, selectMenuButton, statusButton, selectedOption, buttonText, changesetStatus, selectedStatus;
			changesetStatus = api.state( 'changesetStatus' ).get();
			statusButton = {};

			selectedStatus = changesetStatus && 'auto-draft' !== changesetStatus ? changesetStatus : 'publish';

			statusButton.value = new api.Value( selectedStatus );
			statusButton.disbleButton = new api.Value();
			statusButton.disableSelect = new api.Value();
			statusButton.buttonText = new api.Value();
			statusButton.needConfirm = false;

			statusButton.container = $( $.trim( wp.template( 'snapshot-status-button' )({
				selected: selectedStatus
			}) ) );
			statusButton.button = statusButton.container.find( '.snapshot-status-button-overlay' );
			statusButton.select = statusButton.container.find( 'select' );
			statusButton.select.selectmenu({
				width: 'auto',
				icons: {
					button: 'dashicons dashicons-arrow-down'
				},
				change: function( event, ui ) {
					statusButton.value.set( ui.item.value );
				},
				select: function() {
					if ( statusButton.hiddenButton ) {
						statusButton.hiddenButton.text( statusButton.buttonText.get() );
					}
				}
			});

			selectMenuButton = statusButton.container.find( '.ui-selectmenu-button' );
			statusButton.hiddenButton = selectMenuButton.find( '.ui-selectmenu-text' );
			statusButton.hiddenButton.addClass( 'button button-primary' );

			statusButton.dropDown = selectMenuButton.find( '.ui-icon' );
			statusButton.dropDown.addClass( 'button button-primary' );

			statusButton.updateButtonText = function( dataAttr ) {
				buttonText = statusButton.button.data( dataAttr );
				statusButton.button.text( buttonText );
				statusButton.hiddenButton.text( buttonText );
				statusButton.buttonText.set( buttonText );
			};

			statusButton.value.bind( function( status ) {
				selectedOption = statusButton.select.find( 'option:selected' );
				statusButton.button.data( 'alt-text', selectedOption.data( 'alt-text' ) );
				statusButton.button.data( 'button-text', selectedOption.text() );
				statusButton.updateButtonText( 'button-text' );

				if ( 'publish' === status ) {
					snapshot.snapshotExpandButton.hide();
					statusButton.button.data( 'confirm-text', selectedOption.data( 'confirm-text' ) );
					statusButton.button.data( 'publish-text', selectedOption.data( 'publish-text' ) );
					statusButton.needConfirm = true;
				}

				if ( 'future' === status ) {
					snapshot.snapshotEditContainerDisplayed.set( true );
					snapshot.snapshotExpandButton.show();
					if ( snapshot.isFutureDate() ) {
						snapshot.countdown.show();
						snapshot.updateSnapshot( status );
					}
				} else {
					snapshot.updateSnapshot( status );
					snapshot.snapshotEditContainerDisplayed.set( false );
					snapshot.countdown.hide();
				}
			} );

			statusButton.disbleButton.bind( function( disabled ) {
				statusButton.button.prop( 'disabled', disabled );
			} );

			statusButton.disableSelect.bind( function( disabled ) {
				statusButton.select.selectmenu( disabled ? 'disable' : 'enable' );
				statusButton.dropDown.toggleClass( 'disabled', disabled );
			} );

			statusButton.disable = function( disable ) {
				statusButton.disableSelect.set( disable );
				statusButton.disbleButton.set( disable );
			};

			statusButton.button.on( 'click', function( event ) {
				event.preventDefault();
				snapshot.updateSnapshot( statusButton.value.get() );
			} );

			snapshot.publishButton.after( statusButton.container );

			return statusButton;
		},

		/**
		 * Remove 'customize_changeset_status' if it is being auto saved for edit box to avoid revisions.
		 *
		 * @return {void}
		 */
		prefilterAjax: function prefilterAjax() {
			var snapshot = this, removeParam, isSameStatus;

			if ( ! api.state.has( 'changesetStatus' ) ) {
				return;
			}

			removeParam = function( queryString, parameter ) {
				var pars = queryString.split( /[&;]/g );

				_.each( pars, function( string, index ) {
					if ( string && string.lastIndexOf( parameter, 0 ) !== -1 ) {
						pars.splice( index, 1 );
					}
				} );

				return pars.join( '&' );
			};

			$.ajaxPrefilter( function( options, originalOptions ) {
				if ( ! originalOptions.data || ! snapshot.editBoxAutoSaveTriggered ) {
					return;
				}

				isSameStatus = api.state( 'changesetStatus' ).get() === originalOptions.data.customize_changeset_status;
				if ( 'customize_save' === originalOptions.data.action && options.data && originalOptions.data.customize_changeset_status && isSameStatus ) {
					options.data = removeParam( options.data, 'customize_changeset_status' );
					snapshot.editBoxAutoSaveTriggered = false;
				}
			} );
		},

		/**
		 * Remove a param from close button link.
		 *
		 * @param {string} targetParam param.
		 * @return {void}.
		 */
		removeParamFromClose: function removeParamFromClose( targetParam ) {
			var snapshot = this, closeButton, queryString, updatedParams;
			closeButton = $( '.customize-controls-close' );
			queryString = closeButton.prop( 'search' ).substr( 1 );

			if ( ! queryString.length ) {
				return;
			}

			updatedParams = snapshot.parseQueryString( queryString );
			delete updatedParams[ targetParam ];
			closeButton.prop( 'search', $.param( updatedParams ) );
		},

		/**
		 * Parse query string.
		 *
		 * @since 4.7.0
		 * @access public
		 *
		 * @param {string} queryString Query string.
		 * @returns {object} Parsed query string.
		 */
		parseQueryString: api.utils.parseQueryString
	} );
})( wp.customize, jQuery );
