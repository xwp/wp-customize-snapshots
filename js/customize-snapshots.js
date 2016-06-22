/* global jQuery, _customizeSnapshots */
/* eslint-disable no-extra-parens */

( function( api, $ ) {
	'use strict';

	var component;

	if ( ! api.Snapshots ) {
		api.Snapshots = {};
	}

	component = api.Snapshots;

	component.data = {};

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

			component.extendPreviewerQuery();
			component.addButtons();

			$( '#snapshot-save' ).on( 'click', function( event ) {
				event.preventDefault();
				component.sendUpdateSnapshotRequest( { status: 'draft', openNewWindow: event.shiftKey } );
			} );
			$( '#snapshot-submit' ).on( 'click', function( event ) {
				event.preventDefault();
				component.sendUpdateSnapshotRequest( { status: 'pending', openNewWindow: event.shiftKey } );
			} );

			if ( api.state( 'snapshot-exists' ).get() ) {
				api.state( 'saved' ).set( false );
				component.resetSavedStateQuietly();
			}
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
	 * Create the snapshot buttons.
	 *
	 * @return {void}
	 */
	component.addButtons = function() {
		var header = $( '#customize-header-actions' ),
			publishButton = header.find( '#save' ),
			snapshotButton, submitButton, data;

		snapshotButton = wp.template( 'snapshot-save' );
		data = {
			buttonText: api.state( 'snapshot-exists' ).get() ? component.data.i18n.updateButton : component.data.i18n.saveButton
		};
		snapshotButton = $( $.trim( snapshotButton( data ) ) );
		if ( ! component.data.currentUserCanPublish ) {
			snapshotButton.attr( 'title', api.state( 'snapshot-exists' ).get() ? component.data.i18n.permsMsg.update : component.data.i18n.permsMsg.save );
		}
		snapshotButton.prop( 'disabled', true );
		snapshotButton.insertAfter( publishButton );
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
	 * @param {boolean} options.openNewWindow Whether to open the frontend in a new window.
	 * @return {void}
	 */
	component.sendUpdateSnapshotRequest = function( options ) {
		var spinner = $( '#customize-header-actions .spinner' ),
			request, data, args;

		args = _.extend(
			{
				status: 'draft',
				openNewWindow: false
			},
			options
		);

		data = _.extend(
			{},
			api.previewer.query(),
			{
				nonce: api.settings.nonce.snapshot,
				customize_snapshot_uuid: component.data.uuid,
				status: args.status
			}
		);
		request = wp.ajax.post( 'customize_update_snapshot', data );

		spinner.addClass( 'is-active' );
		request.always( function( response ) {
			spinner.removeClass( 'is-active' );

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

			// Open the preview in a new window on shift+click.
			if ( args.openNewWindow ) {
				window.open( url, '_blank' );
			}

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
				messages = component.data.i18n.errorMsg;

			if ( response.errors ) {
				messages += ' ' + _.pluck( response.errors, 'message' ).join( ' ' );
			}

			// Insert the snapshot dialog error template.
			if ( 0 === $( '#' + id ).length ) {
				$( 'body' ).append( snapshotDialogShareError( {
					title: component.data.i18n.errorTitle,
					message: messages
				} ) );
			}

			// Open the dialog.
			$( '#' + id ).dialog( {
				autoOpen: true,
				modal: true
			} );
		} );
	};

	component.init();

} )( wp.customize, jQuery );
