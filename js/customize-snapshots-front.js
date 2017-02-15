/* global jQuery, wp */
/* exported CustomizeSnapshotsFront */
var CustomizeSnapshotsFront = (function( $ ) {
	'use strict';

	var component = {
		data: {
			confirmationMsg: '',
			action: '',
			snapshotsFrontendPublishNonce: '',
			errorMsg: ''
		}
	};
	/**
	 * Init.
	 *
	 * @param {object} args Args.
	 * @returns {void}
	 */
	component.init = function init( args ) {
		_.extend( component.data, args );

		$( document ).ready( function() {
			component.setupPublishButton();
		} );
	};

	/**
	 * Set up snapshot frontend publish button.
	 *
	 * @returns {void}
	 */
	component.setupPublishButton = function setupPublishButton() {
		var publishBtn = $( '#wp-admin-bar-publish-customize-snapshot a' );

		if ( ! publishBtn.length ) {
			return;
		}

		publishBtn.click( function( e ) {
			var request,
				data = {
					nonce: component.data.snapshotsFrontendPublishNonce,
					uuid: component.data.uuid
				};
			e.preventDefault();

			if ( ! window.confirm( component.data.confirmationMsg ) ) { // eslint-disable-line no-alert
				return false;
			}
			if ( ! wp.customize.settings.theme.active ) {
				data.stylesheet = wp.customize.settings.theme.stylesheet;
			}
			request = wp.ajax.post( component.data.action, data );

			request.done( function( resp ) {
				if ( resp && resp.success ) {
					window.location = e.target.href;
				}
			} );
			request.fail( function( resp ) {
				if ( resp && resp.errorMsg ) {
					window.alert( resp.errorMsg ); // eslint-disable-line no-alert
				}
			} );

			return true;
		} );
	};

	return component;
})( jQuery );
