/* global jQuery, wp */
/* eslint consistent-this: ["error", "snapshot"] */

( function( api, $ ) {
	'use strict';

	if ( ! api.Snapshots ) {
		return;
	}

	api.SnapshotsCompat = api.Snapshots.extend( {

		uuidParam: 'customize_snapshot_uuid',

		initialize: function initialize( snapshotsConfig ) {
			var snapshot = this;

			if ( _.isObject( snapshotsConfig ) ) {
				_.extend( snapshot.data, snapshotsConfig );
			}

			window._wpCustomizeControlsL10n.save = snapshot.data.i18n.publish;
			window._wpCustomizeControlsL10n.saved = snapshot.data.i18n.published;

			api.bind( 'ready', function() {
				api.state.create( 'snapshot-exists', snapshot.data.snapshotExists );
				snapshot.extendPreviewerQuery();

				if ( api.state( 'snapshot-exists' ).get() ) {
					api.state( 'saved' ).set( false );
					snapshot.resetSavedStateQuietly();
				}
			} );

			// Make sure that saved state is false so that Published button behaves as expected.
			api.bind( 'save', function() {
				api.state( 'saved' ).set( false );
			} );

			api.bind( 'saved', function( response ) {
				var url = window.location.href,
					updatedUrl,
					urlParts;

				// Update the UUID.
				if ( response.new_customize_snapshot_uuid ) {
					snapshot.data.uuid = response.new_customize_snapshot_uuid;
					snapshot.previewLink.attr( 'target', snapshot.data.uuid );
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

			api.Snapshots.prototype.initialize.call( snapshot, snapshotsConfig );
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
				api.previewer.query(),
				options,
				{
					nonce: api.settings.nonce.snapshot,
					customize_snapshot_uuid: snapshot.data.uuid
				}
			);

			request = wp.ajax.post( 'customize_update_snapshot', data );

			spinner.addClass( 'is-active' );
			request.always( function( response ) {
				spinner.removeClass( 'is-active' );
				if ( response.edit_link ) {
					snapshot.data.editLink = response.edit_link;
				}
				if ( response.snapshot_publish_date ) {
					snapshot.data.publishDate = response.snapshot_publish_date;
				}
				if ( response.title ) {
					snapshot.data.title = response.title;
				}
				snapshot.updateSnapshotEditControls();
				snapshot.data.dirty = false;

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
					url = url.replace( regex, '$1customize_snapshot_uuid=' + encodeURIComponent( snapshot.data.uuid ) + '$2' );
				} else {
					url = url + separator + 'customize_snapshot_uuid=' + encodeURIComponent( snapshot.data.uuid );
				}

				// Change the save button text to update.
				api.state( 'snapshot-exists' ).set( true );

				// Replace the history state with an updated Customizer URL that includes the Snapshot UUID.
				if ( history.replaceState && ! customizeUrl.match( regex ) ) {
					customizeUrl += customizeSeparator + 'customize_snapshot_uuid=' + encodeURIComponent( snapshot.data.uuid );
					history.replaceState( {}, document.title, customizeUrl );
				}

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
				dialogElement = $( '#' + id );
				if ( ! dialogElement.length ) {
					dialogElement = $( snapshotDialogShareError( {
						title: snapshot.data.i18n.errorTitle,
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
		},

		/**
		 * Amend the preview query so we can update the snapshot during `customize_save`.
		 *
		 * @return {void}
		 */
		extendPreviewerQuery: function extendPreviewerQuery() {
			var snapshot = this, originalQuery = api.previewer.query;

			api.previewer.query = function() {
				var retval = originalQuery.apply( this, arguments );
				if ( api.state( 'snapshot-exists' ).get() ) {
					retval.customize_snapshot_uuid = snapshot.data.uuid;
					if ( snapshot.snapshotTitle && snapshot.snapshotTitle.val() ) {
						retval.title = snapshot.snapshotTitle.val();
					}
				}
				return retval;
			};
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

			snapshot.spinner = header.find( '.spinner' );
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

			snapshot.snapshotButton = $( $.trim( wp.template( 'snapshot-save' )( templateData ) ) );

			if ( ! snapshot.data.currentUserCanPublish ) {
				snapshot.snapshotButton.attr( 'title', api.state( 'snapshot-exists' ).get() ? snapshot.data.i18n.permsMsg.update : snapshot.data.i18n.permsMsg.save );
			}
			snapshot.snapshotButton.prop( 'disabled', true );

			snapshot.snapshotButton.on( 'click', function( event ) {
				event.preventDefault();
				if ( snapshot.isFutureDate() ) {
					snapshot.updateSnapshot( 'future' );
				} else {
					snapshot.updateSnapshot( 'draft' );
				}
			} );

			snapshot.snapshotButton.insertAfter( publishButton );

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
		 * Hides the future date notification used for 4.7.
		 *
		 * @return {void}.
		 */
		toggleDateNotification: function showDateNotification() {
			var snapshot = this;
			if ( ! _.isEmpty( snapshot.dateNotification ) ) {
				snapshot.dateNotification.addClass( 'hidden' );
			}
		},

		/**
		 * Auto save edit box when the dates are changed for 4.7.
		 *
		 * @return {void}
		 */
		autoSaveEditBox: function autoSaveEditor() {

		},

		/**
		 * Renders snapshot schedule and handles it's events.
		 *
		 * @returns {void}
		 */
		editSnapshotUI: function editSnapshotUI() {
			var snapshot = this;
			api.Snapshots.prototype.editSnapshotUI.call( snapshot );

			api.state( 'saved' ).bind( function( saved ) {
				if ( saved && ! _.isEmpty( snapshot.editContainer ) ) {
					snapshot.data.dirty = false;
					snapshot.data.publishDate = snapshot.getCurrentTime();
					snapshot.snapshotEditContainerDisplayed.set( false );
					snapshot.updateSnapshotEditControls();
				}
			} );
		},

		/**
		 * Updates snapshot schedule with `snapshot.data`.
		 *
		 * @return {void}
		 */
		updateSnapshotEditControls: function updateSnapshotEditControls() {
			var snapshot = this, parsed,
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
		 * Populate setting value from the inputs.
		 *
		 * @returns {void}
		 */
		populateSetting: function populateSetting() {
			var snapshot = this,
				date = snapshot.getDateFromInputs(),
				scheduled, isDirtySetting, isDirtyDate;

			if ( ! date || ! snapshot.data.currentUserCanPublish ) {
				snapshot.dirtySnapshotPostSetting.set( snapshot.data.title !== snapshot.snapshotTitle.val() );
				return;
			}

			date.setSeconds( 0 );
			scheduled = snapshot.formatDate( date ) !== snapshot.data.publishDate;

			isDirtySetting = snapshot.data.title !== snapshot.snapshotTitle.val() || scheduled;
			snapshot.dirtySnapshotPostSetting.set( isDirtySetting );

			isDirtyDate = scheduled && snapshot.isFutureDate();
			snapshot.dirtyScheduleDate.set( isDirtyDate );

			snapshot.updateCountdown();
			snapshot.editContainer.find( '.reset-time' ).toggle( scheduled );
		}
	} );

} )( wp.customize, jQuery );
