/* global jQuery, confirm */
/* exported CustomizeSnapshotsFrontend */
/* eslint consistent-this: [ "error", "section" ], no-magic-numbers: [ "error", { "ignore": [0,1] } ] */
/* eslint-disable no-alert */

// @todo Allow the session to be explicitly exited. Warn with confirm if clicking a non-snapshotted link (to the admin).
// @todo Inject customize_snapshot_uuid into all Ajax requests back to the site.

var CustomizeSnapshotsFrontend = ( function( $ ) {
	'use strict';

	var component = {
		data: {
			uuid: '',
			home_url: {
				scheme: '',
				host: '',
				path: ''
			},
			l10n: {
				restoreSessionPrompt: ''
			}
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

		component.keepSessionAlive();
		component.rememberSessionSnapshot();
		component.injectSnapshotIntoLinks();
	};

	/**
	 * Prompt to restore session.
	 *
	 * @returns {void}
	 */
	component.keepSessionAlive = function keepSessionAlive() {
		var currentSnapshotUuid, urlParser;
		if ( 'undefined' === typeof sessionStorage ) {
			return;
		}
		currentSnapshotUuid = sessionStorage.getItem( 'customize_snapshot_uuid' );
		if ( ! currentSnapshotUuid || component.data.uuid ) {
			return;
		}
		if ( confirm( component.data.l10n.restoreSessionPrompt ) ) {
			urlParser = document.createElement( 'a' );
			urlParser.href = location.href;
			if ( urlParser.search.length > 1 ) {
				urlParser.search += '&';
			}
			urlParser.search += 'customize_snapshot_uuid=' + encodeURIComponent( sessionStorage.getItem( 'customize_snapshot_uuid' ) );
			location.replace( urlParser.href );
		} else {
			sessionStorage.removeItem( 'customize_snapshot_uuid' );
		}
	};

	/**
	 * Remember the session's snapshot.
	 *
	 * Persist the snapshot UUID in session storage so that we can prompt to restore the snapshot query param if inadvertently dropped.
	 *
	 * @returns {void}
	 */
	component.rememberSessionSnapshot = function rememberSessionSnapshot() {
		if ( 'undefined' === typeof sessionStorage || ! component.data.uuid ) {
			return;
		}
		sessionStorage.setItem( 'customize_snapshot_uuid', component.data.uuid );
	};

	/**
	 * Inject the snapshot UUID into links in the document.
	 *
	 * @returns {void}
	 */
	component.injectSnapshotIntoLinks = function injectSnapshotIntoLinks() {
		if ( ! component.data.uuid ) {
			return;
		}
		$( document.documentElement ).on( 'click focus mouseover', 'a, area', function() {
			component.injectLinkQueryParam( this );
		} );
	};

	/**
	 * Inject the customize_snapshot_uuid query param into links on the frontend.
	 *
	 * @param {HTMLAnchorElement|HTMLAreaElement} element Link element.
	 * @param {object} element.search Query string.
	 * @returns {void}
	 */
	component.injectLinkQueryParam = function injectLinkQueryParam( element ) {
		if ( element.hostname !== component.data.home_url.host ) {
			return;
		}
		if ( 0 !== element.pathname.indexOf( component.data.home_url.path ) ) {
			return;
		}
		if ( /(^|&)customize_snapshot_uuid=/.test( element.search.substr( 1 ) ) ) {
			return;
		}

		if ( element.search.length > 1 ) {
			element.search += '&';
		}
		element.search += 'customize_snapshot_uuid=' + encodeURIComponent( component.data.uuid );
	};

	return component;
} )( jQuery );

