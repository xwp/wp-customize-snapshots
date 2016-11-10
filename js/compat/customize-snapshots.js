/* global jQuery, _customizeSnapshots */

( function( api, $ ) {
	'use strict';

	if ( ! api.Snapshots ) {
	    return;
	}

	api.SnapshotCompat = api.Snapshots.extend({

		initialize: function initialize( snapshotsConfig ) {
			var snapshot = this;

			if ( _.isObject( snapshotsConfig ) ) {
				_.extend( snapshot.data, snapshotsConfig );
			}

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
		}
	});

} )( wp.customize, jQuery );
