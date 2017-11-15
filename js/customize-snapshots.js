/* global wp, jQuery */
/* eslint consistent-this: [ "error", "snapshot", "control" ] */
/* eslint no-magic-numbers: ["error", { "ignore": [0, 1] }] */

(function( api, $ ) {
	'use strict';

	api.Snapshots = api.Class.extend( {

		data: {
			inspectLink: '',
			title: '',
			i18n: {
				title: '',
				savePending: '',
				pendingSaved: '',
				aysMsg: ''
			}
		},

		initialize: function initialize( snapshotsConfig ) {
			var snapshot = this;

			if ( _.isObject( snapshotsConfig ) ) {
				_.extend( snapshot.data, snapshotsConfig );
			}

			api.bind( 'ready', function() {
				var saveBtn = $( '#save' );

				snapshot.data.title = snapshot.data.title || api.settings.changeset.uuid;
				api.state.create( 'changesetTitle', snapshot.data.title );
				api.state.create( 'changesetInspectUrl', snapshot.data.inspectLink );

				api.control( 'changeset_scheduled_date', snapshot.setupScheduledChangesetCountdown );

				api.bind( 'save-request-params', function( data ) {
					data.customizer_state_query_vars = JSON.stringify( snapshot.getStateQueryVars() );
					data.customize_changeset_title = api.state( 'changesetTitle' );
				} );

				api.state.bind( 'change', function() {
					if ( api.state( 'activated' ).get() && 'pending' === api.state( 'selectedChangesetStatus' ).get() ) {
						if ( api.state( 'saved' ).get() && api.state( 'selectedChangesetStatus' ).get() === api.state( 'changesetStatus' ).get() ) {
							saveBtn.val( snapshot.data.i18n.pendingSaved );
						} else {
							saveBtn.val( snapshot.data.i18n.savePending );
						}
					}
				} );

				api.section( 'publish_settings', function( section ) {
					snapshot.addPendingToStatusControl();
					snapshot.addTitleControl( section );
					snapshot.addInspectChangesetControl( section );
				} );

				// For backward compat.
				snapshot.frontendPreviewUrl = new api.Value( api.previewer.previewUrl.get() );
				snapshot.frontendPreviewUrl.link( api.previewer.previewUrl );

				api.trigger( 'snapshots-ready', snapshot );
			} );

			api.bind( 'save', function( request ) {
				request.done( function( response ) {
					if ( response.edit_link ) {
						api.state( 'changesetInspectUrl' ).set( response.edit_link );
					}
					if ( response.title ) {
						api.state( 'changesetTitle' ).set( response.title );
					}

					// Trigger an event for plugins to use, for backward compat.
					api.trigger( 'customize-snapshots-update', response );
				} );
			} );
		},

		/**
		 * Add title control to publish settings section.
		 *
		 * @param {wp.customize.Section} section Publish settings section.
		 * @return {void}
		 */
		addTitleControl: function( section ) {
		    var snapshot = this, titleControl;

			titleControl = new api.Control( 'changeset_title', {
				type: 'text',
				label: snapshot.data.i18n.title,
				section: section.id,
				setting: api.state( 'changesetTitle' ),
				priority: 31
			} );

			api.control.add( titleControl );

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
		 * Setup scheduled changeset countdown.
		 *
		 * @param {wp.customize.Control} dateControl Changeset schedule date control.
		 * @return {void}
		 */
		setupScheduledChangesetCountdown: function( dateControl ) {
			var template, countdownContainer, setNextChangesetUUID;

			template = wp.template( 'snapshot-scheduled-countdown' );
			countdownContainer = $( '<div>', {
				'class': 'snapshot-countdown-container hidden'
			} );

			setNextChangesetUUID = function( response ) {
				api.state( 'changesetTitle' ).set( response.next_changeset_uuid );
				api.state( 'saved' ).set( true );
				api.unbind( 'saved', setNextChangesetUUID );
			};

			dateControl.deferred.embedded.done( function() {
				dateControl.container.append( countdownContainer );
				api.state( 'remainingTimeToPublish' ).bind( function( time ) {
					if ( 0 === parseInt( time, 10 ) ) {
						api.bind( 'saved', setNextChangesetUUID );
					}
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
			var InspectLinkControl;

			InspectLinkControl = api.Control.extend( {
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

					control.toggleInspectChangesetControl();
					api.state( 'changesetStatus' ).bind( function() {
						control.toggleInspectChangesetControl();
					} );
				},
				toggleInspectChangesetControl: function() {
					this.active.set( ! _.contains( [ '', 'auto-draft' ], api.state( 'changesetStatus' ).get() ) );
				}
			} );

			api.control.add( new InspectLinkControl( 'inspect_changeset', {
				type: 'inspect-changeset-link',
				section: section.id,
				priority: 30,
				setting: api.state( 'changesetInspectUrl' )
			} ) );
		},

		/**
		 * Add pending status to changeset status control.
		 *
		 * @return {void}
		 */
		addPendingToStatusControl: function() {
			var snapshot = this, params, coreStatusControl, draftIndex = 0;

			coreStatusControl = api.control( 'changeset_status' );

			coreStatusControl.deferred.embedded.done( function() {
				params = _.extend( {}, coreStatusControl.params );
				coreStatusControl.container.remove();
				api.control.remove( 'changeset_status' );

				_.each( params.choices, function( statusConfig, index ) {
					if ( 'draft' === statusConfig.status ) {
						draftIndex = index;
					}
				} );

				params.choices.splice( draftIndex + 1, 0, {
					label: snapshot.data.i18n.savePending,
					status: 'pending'
				} );
				api.control.add( new api.Control( 'changeset_status', params ) );
			} );
		},

		/**
		 * Get the preview URL with the snapshot UUID attached for backward compat.
		 *
		 * @returns {string} URL.
		 */
		getSnapshotFrontendPreviewUrl: function() {
			return api.previewer.getFrontendPreviewUrl();
		}
	} );

})( wp.customize, jQuery );
