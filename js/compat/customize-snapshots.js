/* global jQuery, _customizeSnapshots */

( function( api, $ ) {
	'use strict';

	if ( ! api.Snapshots ) {
	    return;
	}

	api.SnapshotsCompat = api.Snapshots.extend({

		uuidParam: 'customize_snapshot_uuid',

		initialize: function initialize( snapshotsConfig ) {
			var snapshot = this;

			if ( _.isObject( snapshotsConfig ) ) {
				_.extend( snapshot.data, snapshotsConfig );
			}

			api.bind( 'ready', function() {
				api.state.create( 'snapshot-exists', snapshot.data.snapshotExists );
				snapshot.extendPreviewerQuery();
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
		}

	});

} )( wp.customize, jQuery );
