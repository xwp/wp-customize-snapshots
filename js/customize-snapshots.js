/* global wp, jQuery, _ */
/* eslint consistent-this: [ "error", "snapshot", "inspectControl" ] */
/* eslint no-magic-numbers: [ "error", { "ignore": [0, 1] } ] */
/* eslint max-nested-callbacks: [ "error", 4 ] */

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
				aysMsg: '',
				conflictNotification: ''
			}
		},

		conflict: {
			warningTemplate: wp.template( 'snapshot-conflict' ),
			conflictButtonTemplate: wp.template( 'snapshot-conflict-button' ),
			notificationTemplate: wp.template( 'snapshot-notification-template' ),
			conflictValueTemplate: wp.template( 'snapshot-conflict-value' ),
			refreshBuffer: 250,
			controlThickboxes: {},
			controlsWithPendingRequest: {},
			notificationCode: 'snapshot_conflict',
			nonce: ''
		},

		/**
		 * Initialize.
		 *
		 * @param {object} snapshotsConfig Snapshot configuration.
		 * @return {void}
		 */
		initialize: function initialize( snapshotsConfig ) {
			var snapshot = this;

			if ( _.isObject( snapshotsConfig ) ) {
				_.extend( snapshot.data, snapshotsConfig );
			}

			_.bindAll( snapshot, 'addConflictButton', 'handleConflictRequestOnFirstChange', 'sendConflictRequest', 'resetConflicts' );

			api.bind( 'ready', function() {
				var saveBtn = $( '#save' );

				snapshot.conflict.nonce = api.settings.nonce['conflict-nonce'];
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

					/**
					 * This is not ideal but is just a workaround to fix https://core.trac.wordpress.org/ticket/42686 for 4.9 and 4.9.1.
					 */
					section.expanded.bind( function( expanded ) {
						if ( snapshot.data.addWorkaroundFor42686 && expanded ) {
							api.settings.changeset.currentUserCanPublish = true;
						}
					} );
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

			api.state( 'saved' ).bind( snapshot.resetConflicts );
			api.control.bind( 'add', snapshot.addConflictButton );
			api.control.each( snapshot.addConflictButton );
		},

		/**
		 * Add title control to publish settings section.
		 *
		 * @param {wp.customize.Section} section Publish settings section.
		 * @return {void}
		 */
		addTitleControl: function addTitleControl( section ) {
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
		getStateQueryVars: function getStateQueryVars() {
			var snapshot = this, queryVars;

			queryVars = {
				'autofocus[control]': null,
				'autofocus[section]': null,
				'autofocus[outer_section]': null,
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
					if ( ! found && construct.expanded && construct.expanded.get() && ! construct.extended( api.OuterSection ) ) {
						queryVars[ 'autofocus[' + constructType + ']' ] = construct.id;
						found = true;
					}
				} );
				return found;
			} );

			api.section.each( function( section ) {
				if ( section.expanded && section.expanded.get() && section.extended( api.OuterSection ) ) {
					queryVars[ 'autofocus[outer_section]' ] = section.id;
				}
			} );

			return queryVars;
		},

		/**
		 * Setup scheduled changeset countdown.
		 *
		 * @param {wp.customize.Control} dateControl Changeset schedule date control.
		 * @return {void}
		 */
		setupScheduledChangesetCountdown: function setupScheduledChangesetCountdown( dateControl ) {
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
		addInspectChangesetControl: function addInspectChangesetControl( section ) {
			var InspectLinkControl;

			InspectLinkControl = api.Control.extend( {
				defaults: _.extend( {}, api.Control.prototype.defaults, {
					templateId: 'snapshot-inspect-link-control'
				} ),
				ready: function() {
					var inspectControl = this, link;
					link = inspectControl.container.find( 'a' );
					link.attr( 'href', inspectControl.setting() );
					inspectControl.setting.bind( function( value ) {
					    link.attr( 'href', value );
					} );

					inspectControl.toggleInspectChangesetControl();
					api.state( 'changesetStatus' ).bind( function() {
						inspectControl.toggleInspectChangesetControl();
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
		addPendingToStatusControl: function addPendingToStatusControl() {
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
		getSnapshotFrontendPreviewUrl: function getSnapshotFrontendPreviewUrl() {
			return api.previewer.getFrontendPreviewUrl();
		},

		/**
		 * Resets conflicts.
		 *
		 * @param {boolean} saved - If saved or not.
		 * @return {void}
		 */
		resetConflicts: function resetConflicts( saved ) {
			var snapshot = this;

			if ( ! saved ) {
				return;
			}

			_.each( snapshot.conflict.controlThickboxes, function( thickBox ) {
				thickBox.remove();
			} );

			if ( snapshot.conflict.currentRequest ) {
				snapshot.conflict.currentRequest.abort();
				snapshot.conflict.currentRequest = null;
			}

			snapshot.conflict.controlThickboxes = {};
			snapshot.conflict.controlsWithPendingRequest = {};

			api.control.each( function( control ) {
				control.notifications.remove( snapshot.conflict.notificationCode );
				snapshot.addConflictButton( control );
			} );
		},

		/**
		 * Handle conflict on first settings change.
		 *
		 * @param {wp.customize.Control} control - Control.
		 * @return {void}
		 */
		handleConflictRequestOnFirstChange: function handleConflictRequestOnFirstChange( control ) {
			var snapshot = this;

			_.each( control.settings, function( setting ) {
				snapshot.handleConflictRequest( setting, control );
			} );
		},

		/**
		 * Add conflict button icon on first change and trigger handleConflictRequest.
		 *
		 * @param {wp.customize.Control} control - Control where conflict button will be added.
		 * @return {void}
		 */
		addConflictButton: function addConflictButton( control ) {
			var snapshot = this, changeOnce, unbindAll;

			control.deferred.embedded.done( function() {
				var hasDirty, updateCurrentValue, bindFirstChange = false;

				if ( ! control.setting || api.section( control.section() ).extended( api.OuterSection ) ) {
					return;
				}

				updateCurrentValue = function() {
					_.each( control.settings, function( setting ) {
						if ( setting.extended( api.Setting ) ) {
							snapshot.updateConflictValueMarkup( setting.id, control.container );
						}
					} );
				};

				hasDirty = _.find( control.settings, function( setting ) {
					return setting.extended( api.Setting ) ? setting._dirty : false;
				} );

				changeOnce = function() {
					this.unbind( changeOnce );
					snapshot.handleConflictRequestOnFirstChange( control );
				};

				unbindAll = function() {
					api.state( 'saved' ).unbind( unbindAll );
					_.each( control.settings, function( setting ) {
						setting.unbind( changeOnce );
						setting.unbind( updateCurrentValue );
					} );
				};

				api.state( 'saved' ).bind( unbindAll );

				if ( hasDirty ) {
					snapshot.handleConflictRequestOnFirstChange( control );
				} else {
					bindFirstChange = true;
				}
				_.each( control.settings, function( setting ) {
					if ( bindFirstChange ) {
						setting.bind( changeOnce );
					}
					setting.bind( updateCurrentValue );
				} );
			} );
		},

		/**
		 * Handles the snapshot conflict request.
		 *
		 * @param {wp.customize.Setting} setting - Setting to check conflicts.
		 * @param {wp.customize.Control} control - Control.
		 *
		 * @return {void}
		 */
		handleConflictRequest: function handleConflictRequest( setting, control ) {
			var snapshot = this;

			if ( _.isUndefined( control ) || _.isUndefined( control.notifications ) ) {
				return;
			}

			if ( snapshot.conflict.currentRequest ) {
				snapshot.conflict.currentRequest.abort();
				snapshot.conflict.currentRequest = null;
			}

			if ( snapshot.conflict.conflictRequestTimeout ) {
				clearTimeout( snapshot.conflict.conflictRequestTimeout );
				snapshot.conflict.conflictRequestTimeout = null;
			}

			if ( ! _.isFunction( setting.findControls ) ) {
				return;
			}

			snapshot.conflict.controlsWithPendingRequest[ setting.id ] = setting.findControls();
			snapshot.conflict.conflictRequestTimeout = _.delay( snapshot.sendConflictRequest, snapshot.conflict.refreshBuffer );
		},

		/**
		 * Send Conflict Request
		 *
		 * @return {void}
		 */
		sendConflictRequest: function sendConflictRequest() {
			var snapshot = this, settingIds;

			settingIds = _.keys( snapshot.conflict.controlsWithPendingRequest );

			if ( _.isEmpty( settingIds ) ) {
				return;
			}

			snapshot.conflict.currentRequest = wp.ajax.post( 'customize_snapshot_conflict_check', {
				setting_ids: settingIds,
				nonce: snapshot.conflict.nonce,
				changeset_uuid: api.settings.changeset.uuid
			} );

			snapshot.conflict.currentRequest.done( function( response ) {
				var multiple, controls, buttonTemplate, notification, notificationsContainer;

				if ( ! _.isEmpty( response ) ) {
					_.each( response, function( conflicts, settingId ) {

						snapshot.conflict.controlThickboxes[ settingId ] = $( $.trim( snapshot.conflict.warningTemplate( {
							setting_id: settingId,
							conflicts: conflicts
						} ) ) );

						multiple = false;
						controls = snapshot.conflict.controlsWithPendingRequest[ settingId ];
						buttonTemplate = $( $.trim( snapshot.conflict.conflictButtonTemplate( {
							setting_id: settingId
						} ) ) );

						_.each( controls, function( control ) {
							control.notificationsTemplate = snapshot.conflict.notificationTemplate;
							notification = new api.Notification( snapshot.conflict.notificationCode, {
								type: 'warning',
								message: $.trim( buttonTemplate.html() )
							} );

							control.notifications.add( snapshot.conflict.notificationCode, notification );

							if ( ! multiple ) {
								notificationsContainer = control.container.find( '.customize-control-notifications-container' );
								if ( notificationsContainer.length ) {
									snapshot.conflict.controlThickboxes[ settingId ].insertAfter( notificationsContainer );
									snapshot.updateConflictValueMarkup( settingId, snapshot.conflict.controlThickboxes[ settingId ] );
								}
							}

							control.container.find( '.snapshot-conflicts-button' ).show();
							multiple = true;
						} );
					} );
				}
				snapshot.conflict.controlsWithPendingRequest = {};
			} );
		},

		/**
		 * Update markup with current value
		 *
		 * @param {string} settingId - Setting id.
		 * @param {jQuery} selector  - Control container or conflict markup container.
		 *
		 * @return {void}
		 */
		updateConflictValueMarkup: function updateConflictValueMarkup( settingId, selector ) {
			var snapshot = this, newCurrentValueMarkup,
				snapshotCurrentValueSelector = selector.find( 'details:first .snapshot-value' );

			if ( snapshotCurrentValueSelector.length ) {
				newCurrentValueMarkup = snapshot.conflict.conflictValueTemplate( {
					value: api( settingId ).get()
				} );
				snapshotCurrentValueSelector.html( newCurrentValueMarkup );
			}
		}
	} );

})( wp.customize, jQuery );
