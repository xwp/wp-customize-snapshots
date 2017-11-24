/* global wp, jQuery, _ */
/* eslint consistent-this: [ "error", "snapshot", "inspectControl" ] */
/* eslint no-magic-numbers: ["error", { "ignore": [0, 1] }] */
/* eslint max-nested-callbacks: ["error", 4] */

(function( api, $ ) {
	'use strict';

	api.Snapshots = api.Class.extend( {

		data: {
			inspectLink: '',
			title: '',
			conflictNonce: '',
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
			controls: {},
			pendingRequest: {},
			notificationCode: 'snapshot_conflict'
		},

		initialize: function initialize( snapshotsConfig ) {
			var snapshot = this;

			if ( _.isObject( snapshotsConfig ) ) {
				_.extend( snapshot.data, snapshotsConfig );
			}

			_.bindAll( snapshot, 'addConflictButton' );

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
				snapshot.handleEventForConflicts();

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

			api.control.bind( 'add', snapshot.addConflictButton );
			api.control.each( snapshot.addConflictButton );
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
		},

		/**
		 * Handles snapshot conflict events.
		 *
		 * @return {void}
		 */
		handleEventForConflicts: function handleEventForConflicts() {
			var snapshot = this;

			api.state( 'saved' ).bind( function( saved ) {
				if ( saved ) {
					_.each( snapshot.conflict.controls, function( control ) {
						control.remove();
					} );

					if ( snapshot.conflict._currentRequest ) {
						snapshot.conflict._currentRequest.abort();
						snapshot.conflict._currentRequest = null;
					}

					snapshot.conflict.controls = {};
					snapshot.conflict.pendingRequest = {};

					api.control.each( function( control ) {
						_.each( control.settings, function( setting ) {
							setting.unbind( snapshot.onConflictFirstChange );
						} );
						control.notifications.remove( snapshot.conflict.notificationCode );
						snapshot.addConflictButton( control );
					} );
				}
			} );
		},

		/**
		 * Add conflict button icon on first change and trigger handleConflictRequest.
		 *
		 * @param {object} control where conflict button will be added.
		 *
		 * @return {void}
		 */
		addConflictButton: function addConflictButton( control ) {
			var snapshot = this;

			control.deferred.embedded.done( function() {
				var onFirstChange, hasDirty, updateCurrentValue, bindFirstChange = false;

				if ( ! control.setting ) {
					return;
				}

				// @todo Move this outside because being used other method also?
				onFirstChange = snapshot.onConflictFirstChange = function() {
					_.each( control.settings, function( setting ) {
						setting.unbind( onFirstChange );
						snapshot.handleConflictRequest( setting, control );
					} );
				};

				updateCurrentValue = function() {
					_.each( control.settings, function( setting ) {
						snapshot.updateConflictValueMarkup( setting.id, control.container );
					} );
				};

				hasDirty = _.find( control.settings, function( setting ) {
					return setting._dirty;
				} );

				if ( hasDirty ) {
					onFirstChange();
				} else {
					bindFirstChange = true;
				}
				_.each( control.settings, function( setting ) {
					if ( bindFirstChange ) {
						setting.bind( onFirstChange );
					}
					setting.bind( updateCurrentValue );
				} );
			} );
		},

		/**
		 * Handles the snapshot conflict request
		 *
		 * @param {object} setting to check conflicts
		 * @param {object} controlObj object
		 *
		 * @return {void}
		 */
		handleConflictRequest: function handleConflictRequest( setting, controlObj ) {
			var snapshot = this, sendConflictRequest;

			if ( _.isUndefined( controlObj ) || _.isUndefined( controlObj.notifications ) ) {
				return;
			}

			if ( snapshot.conflict._currentRequest ) {
				snapshot.conflict._currentRequest.abort();
				snapshot.conflict._currentRequest = null;
			}

			if ( snapshot.conflict._debouncedTimeoutId ) {
				clearTimeout( snapshot.conflict._debouncedTimeoutId );
				snapshot.conflict._debouncedTimeoutId = null;
			}

			if ( ! _.isFunction( setting.findControls ) ) {
				return;
			}

			snapshot.conflict.pendingRequest[setting.id] = setting.findControls();

			sendConflictRequest = function() {
				var data, settingIds;

				settingIds = _.keys( snapshot.conflict.pendingRequest );

				if ( _.isEmpty( settingIds ) ) {
					return;
				}

				data = {
					setting_ids: settingIds,
					nonce: snapshot.data.conflictNonce,
					changeset_uuid: api.settings.changeset.uuid
				};

				snapshot.conflict._currentRequest = wp.ajax.post( 'customize_snapshot_conflict_check', data );

				snapshot.conflict._currentRequest.done( function( returnData ) {
					var multiple, controls, buttonTemplate, notification, notificationsContainer;

					if ( ! _.isEmpty( returnData ) ) {
						_.each( returnData, function( value, key ) {
							snapshot.conflict.controls[key] = $( $.trim( snapshot.conflict.warningTemplate( {
								setting_id: key,
								conflicts: value
							} ) ) );

							multiple = false;
							controls = snapshot.conflict.pendingRequest[key];
							buttonTemplate = $( $.trim( snapshot.conflict.conflictButtonTemplate( {
								setting_id: key
							} ) ) );

							_.each( controls, function( control ) {
								if ( control && control.notifications && api.Notification && control.container ) {
									control.notificationsTemplate = snapshot.conflict.notificationTemplate;
									notification = new api.Notification( snapshot.conflict.notificationCode, {
										type: 'warning',
										message: $.trim( buttonTemplate.html() )
									} );
									control.notifications.add( snapshot.conflict.notificationCode, notification );
									if ( ! multiple ) {
										notificationsContainer = control.container.find( '.customize-control-notifications-container' );
										if ( notificationsContainer.length ) {
											snapshot.conflict.controls[key].insertAfter( notificationsContainer );
											snapshot.updateConflictValueMarkup( key, snapshot.conflict.controls[key] );
										}
									}
									control.container.find( '.snapshot-conflicts-button' ).show();
									multiple = true;
								}
							} );
						} );
					}
					snapshot.conflict.pendingRequest = {};
				} );
			};

			snapshot.conflict._debouncedTimeoutId = _.delay( sendConflictRequest, snapshot.conflict.refreshBuffer );
		},

		/**
		 * Update markup with current value
		 *
		 * @param {string} settingId setting id.
		 * @param {object} selector control container or conflict markup container.
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
