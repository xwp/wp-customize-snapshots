/* global jQuery, _customizeSnapshots, JSON */
/* exported customizeSnapshots */
var customizeSnapshots = ( function( $ ) {

	var self = {},
		api = wp.customize,
		uuid = _customizeSnapshots.uuid,
		is_preview = _customizeSnapshots.is_preview,
		theme = _customizeSnapshots.snapshot_theme,
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
			if ( is_preview ) {
				api.state( 'saved' ).set( false );
			}
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

			if ( is_preview ) {
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
			snapshotButton, data;

		if ( header.length && 0 === header.find( '#snapshot-save' ).length ) {
			snapshotButton = wp.template( 'snapshot-save' ),
			data = {
				buttonText: _customizeSnapshots.i18n.saveButton
			};
			$( snapshotButton( data ) ).insertAfter( header.find( '#save' ) );
		}
		// @todo Change the button text depending on the snapshot state.
	};

	/**
	 * Create the snapshot dialog form.
	 */
	self.addDialogForm = function() {
		var snapshotDialogForm = wp.template( 'snapshot-dialog-form' ),
			data = {
				title: _customizeSnapshots.i18n.formTitle,
				is_preview: is_preview,
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
			preview: ( is_preview ? 'on' : 'off' )
		} );

		request.done( function( response ) {
			var url = wp.customize.previewer.previewUrl(),
				regex = new RegExp( '([?&])customize_snapshot_uuid=.*?(&|$)', 'i' ),
				separator = url.indexOf( '?' ) !== -1 ? '&' : '?',
				id = 'snapshot-dialog-share-link',
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
			if ( ! is_preview ) {
				uuid = response.customize_snapshot_next_uuid;
			}

			// We need to remove old dialog before building a new one.
			if ( $( '#' + id ).length ) {
				$( '#' + id ).remove();
			}

			// Insert the snapshot dialog share link template.
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
		} );

		request.fail( function() {
			var id = 'snapshot-dialog-share-error',
				snapshotDialogShareError = wp.template( id );

			// Insert the snapshot dialog share error template.
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
}( jQuery ) );