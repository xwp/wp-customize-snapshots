/* global jQuery, _customizeSnapshots, JSON */
/* exported customizeSnapshots */
var customizeSnapshots = ( function( $ ) {

	var self = {},
		api = wp.customize,
		uuid = _customizeSnapshots.uuid,
		isPreview = _customizeSnapshots.is_preview,
		theme = _customizeSnapshots.snapshot_theme,
		currentUserCanPublish = _customizeSnapshots.current_user_can_publish,
		dialog, form;

	/**
	 * Inject the functionality.
	 */
	self.init = function() {
		window._wpCustomizeControlsL10n.save = _customizeSnapshots.i18n.publish;
		window._wpCustomizeControlsL10n.saved = _customizeSnapshots.i18n.published;

		api.bind( 'ready', function() {
			if ( ! api.settings.theme.active || ( theme && theme !== api.settings.theme.stylesheet ) ) {
				return;
			}
			self.previewerQuery();
			self.addButton();
			self.addDialogForm();
			self.dialogEvents();
			if ( isPreview ) {
				api.state( 'saved' ).set( false );
				self.resetSavedStateQuietly();
			}
		} );

		api.bind( 'save', function( request ) {
			request.fail( function( response ) {
				var id = 'snapshot-dialog-error',
					snapshotDialogPublishError = wp.template( id );

				if ( response.responseText ) {

					// Insert the dialog error template.
					if ( 0 === $( '#' + id ).length ) {
						$( 'body' ).append( snapshotDialogPublishError( {
							title: _customizeSnapshots.i18n.publish,
							message: _customizeSnapshots.i18n.permsMsg
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
	};

	/**
	 * Amend the preview query so we can update the snapshot during `customize_save`.
	 */
	self.previewerQuery = function() {
		var originalQuery = api.previewer.query;

		api.previewer.query = function() {
			var previewer = this,
				allCustomized = {},
				retval;

			retval = originalQuery.apply( previewer, arguments );

			if ( isPreview ) {
				api.each( function( value, key ) {
					allCustomized[ key ] = {
						'value': value(),
						'dirty': false
					};
				} );
				retval.snapshot_customized = JSON.stringify( allCustomized );
				retval.snapshot_uuid = uuid;
			}

			return retval;
		};
	};

	/**
	 * Create the snapshot share button.
	 */
	self.addButton = function() {
		var header = $( '#customize-header-actions' ),
			publishButton = header.find( '#save' ),
			snapshotButton, data;

		if ( header.length && 0 === header.find( '#snapshot-save' ).length ) {
			snapshotButton = wp.template( 'snapshot-save' );
			data = {
				buttonText: currentUserCanPublish ? _customizeSnapshots.i18n.saveButton : _customizeSnapshots.i18n.saveDraftButton
			};
			snapshotButton = $( $.trim( snapshotButton( data ) ) );
			if ( ! currentUserCanPublish ) {
				snapshotButton.attr( 'title', _customizeSnapshots.i18n.permsMsg );
				snapshotButton.addClass( 'button-primary' ).removeClass( 'button-secondary' );
			}
			snapshotButton.insertAfter( publishButton );
		}

		if ( ! currentUserCanPublish ) {
			publishButton.hide();
		}

		header.addClass( 'button-added' );
	};

	/**
	 * Create the snapshot dialog form.
	 */
	self.addDialogForm = function() {
		var snapshotDialogForm = wp.template( 'snapshot-dialog-form' ),
			data = {
				title: _customizeSnapshots.i18n.formTitle,
				is_preview: isPreview,
				message: _customizeSnapshots.i18n.saveMsg,
				scope: _customizeSnapshots.scope,
				scopeTitle: _customizeSnapshots.i18n.scopeTitle,
				dirtyLabel: _customizeSnapshots.i18n.dirtyLabel,
				fullLabel: _customizeSnapshots.i18n.fullLabel
			};
		$( 'body' ).append( snapshotDialogForm( data ) );
	};

	/**
	 * Create the dialog events.
	 */
	self.dialogEvents = function() {
		dialog = $( '#snapshot-dialog-form' ).dialog( {
			autoOpen: false,
			modal: true,
			buttons: {
				Save: {
					text: _customizeSnapshots.i18n.saveButton,
					click: self.sendUpdateSnapshotRequest
				},
				Cancel: {
					text: _customizeSnapshots.i18n.cancelButton,
					click: function() {
						dialog.dialog( 'close' );
					}
				}
			},
			close: function() {
				form[ 0 ].reset();
			}
		} );

		form = dialog.find( 'form' ).on( 'submit', function( event ) {
			event.preventDefault();
			dialog.next( '.ui-dialog-buttonpane' ).find( 'button:first-child' ).disabled( true );
			self.sendUpdateSnapshotRequest();
		} );

		$( '#snapshot-save' ).on( 'click', function( event ) {
			event.preventDefault();
			dialog.dialog( 'open' );
			dialog.find( 'form input[name=scope]' ).blur();
			dialog.next( '.ui-dialog-buttonpane' ).find( 'button' ).blur();
		} );
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
	 */
	self.resetSavedStateQuietly = function() {
		wp.customize.state( 'saved' )._value = true;
	};

	/**
	 * Make the AJAX request to update/save a snapshot.
	 */
	self.sendUpdateSnapshotRequest = function() {
		var spinner = $( '#customize-header-actions .spinner' ),
			scope = dialog.find( 'form input[name="scope"]:checked' ).val(),
			request, customized;

		if ( ! scope ) {
			scope = dialog.find( 'form input[type="hidden"]' ).val();
		}

		dialog.dialog( 'close' );
		spinner.addClass( 'is-active' );

		customized = {};
		api.each( function( value, key ) {
			customized[ key ] = {
				'value': value(),
				'dirty': value._dirty
			};
		} );

		request = wp.ajax.post( 'customize_update_snapshot', {
			nonce: _customizeSnapshots.nonce,
			wp_customize: 'on',
			snapshot_customized: JSON.stringify( customized ),
			customize_snapshot_uuid: uuid,
			scope: scope,
			preview: ( isPreview ? 'on' : 'off' )
		} );

		request.done( function( response ) {
			var url = wp.customize.previewer.previewUrl(),
				regex = new RegExp( '([?&])customize_snapshot_uuid=.*?(&|$)', 'i' ),
				separator = url.indexOf( '?' ) !== -1 ? '&' : '?',
				id = 'snapshot-dialog-link',
				snapshotDialogShareLink = wp.template( id );

			if ( url.match( regex ) ) {
				url = url.replace( regex, '$1' + 'customize_snapshot_uuid=' + response.customize_snapshot_uuid + '$2' );
			} else {
				url = url + separator + 'customize_snapshot_uuid=' + response.customize_snapshot_uuid;
			}

			if ( 'dirty' !== scope ) {
				scope = 'full';
			}
			url += '&scope=' + scope;

			// Write over the UUID
			if ( ! isPreview ) {
				uuid = response.customize_snapshot_next_uuid;
			}

			// We need to remove old dialog before building a new one.
			if ( $( '#' + id ).length ) {
				$( '#' + id ).remove();
			}

			// Insert the snapshot dialog link template.
			$( 'body' ).append( snapshotDialogShareLink( {
				title: _customizeSnapshots.i18n.previewTitle,
				url: url
			} ) );

			spinner.removeClass( 'is-active' );

			// Open the dialog.
			$( '#' + id ).dialog( {
				autoOpen: true,
				modal: true
			} );
			$( '#' + id ).find( 'a' ).blur();

			self.resetSavedStateQuietly();
		} );

		request.fail( function() {
			var id = 'snapshot-dialog-error',
				snapshotDialogShareError = wp.template( id );

			// Insert the snapshot dialog error template.
			if ( 0 === $( '#' + id ).length ) {
				$( 'body' ).append( snapshotDialogShareError( {
					title: _customizeSnapshots.i18n.formTitle,
					message: _customizeSnapshots.i18n.errorMsg
				} ) );
			}

			spinner.removeClass( 'is-active' );

			// Open the dialog.
			$( '#' + id ).dialog( {
				autoOpen: true,
				modal: true
			} );
		} );
	};

	self.init();

	return self;
}( jQuery ) );
