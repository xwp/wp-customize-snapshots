/* global wp, $ */
/* eslint consistent-this: [ "error", "snapshot", "control" ] */

(function( api ) {
	'use strict';

	api.Snapshots = api.Class.extend( {
		// @todo Add stuff.

		data: {
			inspectLink: '',
			title: '',
			i18n: {}
		},

		initialize: function initialize( snapshotsConfig ) {
			var snapshot = this;

			if ( _.isObject( snapshotsConfig ) ) {
				_.extend( snapshot.data, snapshotsConfig );
			}

			_.bindAll( snapshot, 'setupScheduledChangesetCountdown' );

			api.bind( 'ready', function() {
				// @todo Add snapshot-exists, snapshot-saved, snapshot-submitted states for back-compat? Skip if they are not used.

				snapshot.spinner = $( '#customize-header-actions' ).find( '.spinner' );
				snapshot.saveBtn = $( '#save' );

				snapshot.data.uuid = snapshot.data.uuid || api.settings.changeset.uuid;
				snapshot.data.title = snapshot.data.title || snapshot.data.uuid;
				api.state.create( 'changesetTitle', snapshot.data.title );
				api.state.create( 'changesetInspectLink', snapshot.data.inspectLink );

				snapshot.extendPreviewerQuery();
				api.control( 'changeset_scheduled_date', snapshot.setupScheduledChangesetCountdown );

				api.section( 'publish_settings', function( section ) {
					snapshot.addTitleControl( section );
					snapshot.addInspectChangesetControl( section );
				} );

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
		    var snapshot = this, control, toggleControl;

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

			api.state( 'changesetTitle' ).bind( function() {
				api.state( 'saved' ).set( false );
			} );

			$( window ).on( 'beforeunload.customize-confirm', function() {
				if ( ! api.state( 'saved' ).get() ) {
					return snapshot.data.i18n.aysMsg;
				}
				return undefined;
			} );
		},

		/**
		 * Amend the preview query so we can update the snapshot during `changeset_save`.
		 *
		 * @return {void}
		 */
		extendPreviewerQuery: function extendPreviewerQuery() {
			var snapshot = this, originalQuery = api.previewer.query;

			// @todo See if can be done using 'save-request-params' event?
			api.previewer.query = function() {
				var retval = originalQuery.apply( this, arguments );

				if ( api.state( 'selectedChangesetStatus' ) && 'publish' !== api.state( 'selectedChangesetStatus' ) ) {
					retval.customizer_state_query_vars = JSON.stringify( snapshot.getStateQueryVars() );
					retval.customize_changeset_title = api.state( 'changesetTitle' );
				}

				return retval;
			};
		},

		/**
		 * Get state query vars.
		 * @todo Reuse method in compat mode?
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
		 * Setup scheduled changeset countdown.
		 *
		 * @param {wp.customize.Control} control Changeset schedule date control.
		 * @return {void}
		 */
		setupScheduledChangesetCountdown: function( control ) {
			var template, countdownContainer;

			template = wp.template( 'snapshot-scheduled-countdown' );
			countdownContainer = $( '<div>', {
				'class': 'snapshot-countdown-container hidden'
			} );

			control.deferred.embedded.done( function() {
				control.container.append( countdownContainer );
				api.state( 'remainingTimeToPublish' ).bind( function( time ) {
					countdownContainer.removeClass( 'hidden' ).html( template( {
						remainingTime: time
					} ) );
				} );

				api.state( 'changesetStatus' ).bind( function( status ) {
					if ( 'future' !== status ) {
						countdownContainer.addClass( 'hidden' );
					}
				} );
			} );
		},

		/**
		 * Add inspect changeset post link.
		 *
		 * @param {wp.customize.Section} section Section.
		 * @return {void}
		 */
		addInspectChangesetControl: function( section ) {
			var inspectLinkControl;

			inspectLinkControl = api.Control.extend( {
				defaults: _.extend( {}, api.Control.prototype.defaults, {
					templateId: 'snapshot-inspect-link-control'
				} ),
				ready: function() {
					var control = this, link;
					link = control.container.find( 'a' );
					link.attr( 'href', control.setting() );
					control.setting.bind( function( value ) {
					    link.attr( 'href', value );
					} );

					control.toggleEditLinkControl();
					api.state( 'changesetStatus' ).bind( function() {
						control.toggleEditLinkControl();
					} );
				},
				toggleEditLinkControl: function() {
					this.active.set( 'publish' !== api.state( 'changesetStatus' ).get() && api.state( 'changesetStatus' ).get() );
				}
			} );

			api.control.add( new inspectLinkControl( 'inspect_changeset', {
				type: 'inspect-changeset-link',
				section: section.id,
				priority: 30,
				setting: api.state( 'changesetInspectLink' )
			} ) );
		}
	} );

})( wp.customize );
