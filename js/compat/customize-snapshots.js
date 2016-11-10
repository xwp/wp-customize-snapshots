/* global jQuery, _customizeSnapshots */

( function( api, $ ) {
	'use strict';

	if ( ! api.Snapshots ) {
	    return;
	}

	api.SnapshotCompat = api.Snapshots.extend({

		initialize: function initialize( snapshotsConfig ) {
			var snapshot = this;

			api.Snapshots.prototype.initialize.call( snapshot, snapshotsConfig );
		}
	});

} )( wp.customize, jQuery );
