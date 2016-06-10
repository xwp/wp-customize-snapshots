/* global jQuery, _customizeSnapshots, JSON */
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
			if ( ! api.settings.theme.active || ( component.data.theme && component.data.theme !== api.settings.theme.stylesheet ) ) {
				return;
			}
			api.state.create( 'snapshot-saved', true);
			api.state.create( 'snapshot-submitted', true );
			api.bind( 'change', function () {
				api.state( 'snapshot-saved' ).set( false );
				api.state( 'snapshot-submitted' ).set( false );
			} );

			component.previewerQuery();
			component.addButtons();

			$( '#snapshot-save' ).on( 'click', function( event ) {
				event.preventDefault();
				component.sendUpdateSnapshotRequest( { status: 'draft', openNewWindow: event.shiftKey } );
			} );
			$( '#snapshot-submit' ).on( 'click', function( event ) {
				event.preventDefault();
				component.sendUpdateSnapshotRequest( { status: 'pending', openNewWindow: event.shiftKey } );
			} );

			if ( component.data.isPreview ) {
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
							message: component.data.isPreview ? component.data.i18n.permsMsg.update : component.data.i18n.permsMsg.save
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

		api.bind( 'saved', function() {
			var url = window.location.href,
				request,
				updatedUrl,
				urlParts;

			// Set the button text back to "Save".
			component.changeButton( component.data.i18n.saveButton, component.data.i18n.permsMsg.save );

			request = wp.ajax.post( 'customize_get_snapshot_uuid', {
				nonce: component.data.nonce,
				wp_customize: 'on'
			} );

			// Update the UUID and scope.
			request.done( function( response ) {
				component.data.scope = 'dirty';
				component.data.uuid = response.uuid;
			} );

			// Replace the history state with an updated Customizer URL that does not include the Snapshot UUID or scope.
			urlParts = url.split( '?' );
			if ( history.replaceState && urlParts[1] ) {
				updatedUrl = urlParts[0] + '?' + _.filter( urlParts[1].split( '&' ), function( queryPair ) {
					return ! /^(customize_snapshot_uuid|scope)=/.test( queryPair );
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
	component.previewerQuery = function() {
		var originalQuery = api.previewer.query;

		api.previewer.query = function() {
			var allCustomized = {},
				retval;

			retval = originalQuery.apply( this, arguments );

			if ( component.data.isPreview ) {
				api.each( function( value, key ) {
					allCustomized[ key ] = {
						'value': value(),
						'dirty': value._dirty
					};
				} );
				retval.snapshot_customized = JSON.stringify( allCustomized );
				retval.snapshot_uuid = component.data.uuid;
			}

			return retval;
		};
	};

	/**
	 * Create the snapshot share button.
	 *
	 * @return {void}
	 */
	component.addButtons = function() {
		var header = $( '#customize-header-actions' ),
			publishButton = header.find( '#save' ),
			snapshotButton, submitButton, data;

		snapshotButton = wp.template( 'snapshot-save' );
		data = {
			buttonText: component.data.isPreview ? component.data.i18n.updateButton : component.data.i18n.saveButton
		};
		snapshotButton = $( $.trim( snapshotButton( data ) ) );
		if ( ! component.data.currentUserCanPublish ) {
			snapshotButton.attr( 'title', component.data.isPreview ? component.data.i18n.permsMsg.update : component.data.i18n.permsMsg.save );
		}
		snapshotButton.prop( 'disabled', true );
		snapshotButton.insertAfter( publishButton );
		api.state( 'snapshot-saved' ).bind( function( saved ) {
			snapshotButton.prop( 'disabled', saved );
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
	 * Change the snapshot share button.
	 *
	 * @param {string} buttonText The button text.
	 * @param {string} permsMsg The permissions message.
	 * @return {void}
	 */
	component.changeButton = function( buttonText, permsMsg ) {
		var snapshotButton = $( '#customize-header-actions' ).find( '#snapshot-save' );

		if ( snapshotButton.length ) {
			snapshotButton.text( buttonText );
			if ( ! component.data.currentUserCanPublish ) {
				snapshotButton.attr( 'title', permsMsg );
			}
		}
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
			scope = component.data.scope,
			request, customized, args;

		args = _.extend(
			{
				status: 'draft',
				openNewWindow: false
			},
			options
		);

		spinner.addClass( 'is-active' );

		customized = {};
		api.each( function( value, key ) {
			customized[ key ] = {
				'value': value(),
				'dirty': value._dirty
			};
		} );

		request = wp.ajax.post( 'customize_update_snapshot', {
			nonce: component.data.nonce,
			wp_customize: 'on',
			snapshot_customized: JSON.stringify( customized ),
			customize_snapshot_uuid: component.data.uuid,
			scope: scope,
			status: args.status,
			preview: ( component.data.isPreview ? 'on' : 'off' )
		} );

		request.done( function( response ) {
			var url = api.previewer.previewUrl(),
				regex = new RegExp( '([?&])customize_snapshot_uuid=.*?(&|$)', 'i' ),
				separator = url.indexOf( '?' ) !== -1 ? '&' : '?',
				customizeUrl = window.location.href,
				customizeSeparator = customizeUrl.indexOf( '?' ) !== -1 ? '&' : '?';

			// Set the UUID.
			if ( ! component.data.uuid ) {
				component.data.uuid = response.customize_snapshot_uuid;
			}

			if ( url.match( regex ) ) {
				url = url.replace( regex, '$1customize_snapshot_uuid=' + encodeURIComponent( component.data.uuid ) + '$2' );
			} else {
				url = url + separator + 'customize_snapshot_uuid=' + encodeURIComponent( component.data.uuid );
			}

			if ( 'full' === scope ) {
				url += '&scope=' + encodeURIComponent( scope );
			}

			// Change the save button text to update.
			component.changeButton( component.data.i18n.updateButton, component.data.i18n.permsMsg.update );
			component.data.isPreview = true;

			spinner.removeClass( 'is-active' );
			component.resetSavedStateQuietly();

			// Replace the history state with an updated Customizer URL that includes the Snapshot UUID.
			if ( history.replaceState && ! customizeUrl.match( regex ) ) {
				customizeUrl += customizeSeparator + 'customize_snapshot_uuid=' + encodeURIComponent( component.data.uuid );
				if ( 'full' === scope ) {
					customizeUrl += '&scope=' + encodeURIComponent( scope );
				}
				history.replaceState( {}, document.title, customizeUrl );
			}

			api.state( 'snapshot-saved' ).set( true );
			if ( 'pending' === args.status ) {
				api.state( 'snapshot-submitted' ).set( true );
			}

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

		request.fail( function() {
			var id = 'snapshot-dialog-error',
				snapshotDialogShareError = wp.template( id );

			// Insert the snapshot dialog error template.
			if ( 0 === $( '#' + id ).length ) {
				$( 'body' ).append( snapshotDialogShareError( {
					title: component.data.i18n.errorTitle,
					message: component.data.i18n.errorMsg
				} ) );
			}

			// Open the dialog.
			$( '#' + id ).dialog( {
				autoOpen: true,
				modal: true
			} );

			spinner.removeClass( 'is-active' );
		} );
	};

	component.init();

} )( wp.customize, jQuery );
