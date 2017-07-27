/* global jQuery, confirm */
/* exported CustomizeSnapshotsFrontend */
/* eslint consistent-this: [ "error", "section" ], no-magic-numbers: [ "error", { "ignore": [-1,0,1] } ] */
/* eslint-disable no-alert */

var CustomizeSnapshotsFrontend = ( function( $ ) {
	'use strict';

	var component = {
		data: {
			uuid: '',
			l10n: {
				restoreSessionPrompt: ''
			},
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
	 * @param {string} args.uuid UUID.
	 * @returns {void}
	 */
	component.init = function init( args ) {
		_.extend( component.data, args );

		component.hasSessionStorage = 'undefined' !== typeof sessionStorage;

		component.keepSessionAlive();
		component.rememberSessionSnapshot();
		component.handleExitSnapshotSessionLink();

		if ( component.data.uuid ) {
			$( document ).ready( function() {
				component.setupPublishButton();
			} );
		}
	};

	/**
	 * Prompt to restore session.
	 *
	 * @returns {void}
	 */
	component.keepSessionAlive = function keepSessionAlive() {
		var currentSnapshotUuid, urlParser, adminBarItem;
		if ( ! component.hasSessionStorage ) {
			return;
		}
		currentSnapshotUuid = sessionStorage.getItem( 'customize_changeset_uuid' );
		if ( ! currentSnapshotUuid || component.data.uuid ) {
			return;
		}

		urlParser = document.createElement( 'a' );
		urlParser.href = location.href;
		if ( urlParser.search.length > 1 ) {
			urlParser.search += '&';
		}
		urlParser.search += 'customize_changeset_uuid=' + encodeURIComponent( sessionStorage.getItem( 'customize_changeset_uuid' ) );

		$( function() {
			adminBarItem = $( '#wp-admin-bar-resume-customize-snapshot' );
			if ( adminBarItem.length ) {
				adminBarItem.find( '> a' ).prop( 'href', urlParser.href );
				adminBarItem.show();
			} else if ( confirm( component.data.l10n.restoreSessionPrompt ) ) {
				location.replace( urlParser.href );
			} else {
				sessionStorage.removeItem( 'customize_changeset_uuid' );
			}
		} );
	};

	/**
	 * Remember the session's snapshot.
	 *
	 * Persist the snapshot UUID in session storage so that we can prompt to restore the snapshot query param if inadvertently dropped.
	 *
	 * @returns {void}
	 */
	component.rememberSessionSnapshot = function rememberSessionSnapshot() {
		if ( ! component.hasSessionStorage || ! component.data.uuid ) {
			return;
		}
		sessionStorage.setItem( 'customize_changeset_uuid', component.data.uuid );
	};

	/**
	 * Handle electing to exit from the snapshot session.
	 *
	 * @returns {void}
	 */
	component.handleExitSnapshotSessionLink = function handleExitSnapshotSessionLink() {
		$( function() {
			if ( ! component.hasSessionStorage ) {
				return;
			}
			$( '#wpadminbar' ).on( 'click', '#wp-admin-bar-exit-customize-snapshot', function() {
				sessionStorage.removeItem( 'customize_changeset_uuid' );
			} );
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
} )( jQuery );

