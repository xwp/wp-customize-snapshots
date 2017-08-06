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
			confirmationMsg: ''
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
		var isCustomizerFramePreview = /(^|\?|&)customize_messenger_channel=/.test( location.search );
		if ( ! component.hasSessionStorage || ! component.data.uuid || isCustomizerFramePreview ) {
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
	 * Set up changesets frontend publish button in admin bar.
	 *
	 * @returns {void}
	 */
	component.setupPublishButton = function setupPublishButton() {
		var publishBtn = $( '#wp-admin-bar-publish-customize-changeset a' );

		if ( ! publishBtn.length ) {
			return;
		}

		publishBtn.click( function() {
			if ( ! window.confirm( component.data.confirmationMsg ) ) { // eslint-disable-line no-alert
				return false;
			}
			sessionStorage.removeItem( 'customize_changeset_uuid' );
			return true;
		} );
	};

	return component;
} )( jQuery );

