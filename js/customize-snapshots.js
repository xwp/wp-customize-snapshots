/* global wp, $ */
/* eslint consistent-this: [ "error", "snapshot" ] */

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

			_.bindAll( snapshot, 'addTitleControl' );

			api.bind( 'ready', function() {
				// @todo Add snapshot-exists, snapshot-saved, snapshot-submitted states for back-compat? Skip if they are not used.

				snapshot.spinner = $( '#customize-header-actions' ).find( '.spinner' );
				snapshot.saveBtn = $( '#save' );

				snapshot.data.uuid = snapshot.data.uuid || api.settings.changeset.uuid;
				snapshot.data.title = snapshot.data.title || snapshot.data.uuid;
				api.state.create( 'changesetTitle', snapshot.data.title );

				api.section( 'publish_settings', snapshot.addTitleControl );
				api.trigger( 'snapshots-ready', snapshot );
			} );
		},

		/**
		 * Add title control to publish settings section.
		 *
		 * @param {wp.customize.Section} section Publish settings section.
		 * @return {void}
		 */
		addTitleControl: function( section ) {
		    var snapshot = this, control, toggleControl, originalQuery;

			control = new api.Control( 'changeset_title', {
				type: 'text',
				label: snapshot.data.i18n.title,
				section: section.id,
				setting: api.state( 'changesetTitle' ),
				priority: 31
			} );

			api.control.add( control );

			toggleControl = function( status ) {
				var activate = 'publish' !== status;
				control.active.validate = function() {
					return activate;
				};
				control.active.set( activate );
			};

			toggleControl( api.state( 'selectedChangesetStatus' ).get() );
			api.state( 'selectedChangesetStatus' ).bind( toggleControl );

			originalQuery = api.previewer.query;

			api.previewer.query = function() {
				var retval = originalQuery.apply( this, arguments );
				retval.customize_changeset_title = api.state( 'changesetTitle' );
				return retval;
			};

			api.state( 'changesetTitle' ).bind( function() {
				api.state( 'saved' ).set( false );
			} );

			$( window ).on( 'beforeunload.customize-confirm', function() {
				if ( ! api.state( 'saved' ).get() ) {
					return snapshot.data.i18n.aysMsg;
				}
				return undefined;
			} );
		}
	} );

})( wp.customize );
