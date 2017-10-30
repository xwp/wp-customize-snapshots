/* global wp */
/* eslint no-magic-numbers: [ "error", { "ignore": [0,1,-1] } ], consistent-this: [ "error", "snapshot" ] */

(function( api ) {
	'use strict';

	api.Snapshots = api.Class.extend( {
		// @todo Add stuff.

		data: {
			editLink: '',
			title: '',
			i18n: {}
		},

		initialize: function initialize( snapshotsConfig ) {
			var snapshot = this;

			if ( _.isObject( snapshotsConfig ) ) {
				_.extend( snapshot.data, snapshotsConfig );
			}

			api.bind( 'ready', function() {
				// @todo Add snapshot-exists, snapshot-saved, snapshot-submitted states for back-compat? Skip if they are not used.

				api.trigger( 'snapshots-ready', snapshot );
			} );
		}
	} );

})( wp.customize );
