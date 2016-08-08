/* global jQuery, confirm */
/* exported CustomizeSnapshotsFrontend */
/* eslint consistent-this: [ "error", "section" ], no-magic-numbers: [ "error", { "ignore": [0,1] } ] */
/* eslint-disable no-alert */

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
				restoreSessionPrompt: '',
				leaveSessionPrompt: ''
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

		component.hasSessionStorage = 'undefined' !== typeof sessionStorage;

		component.keepSessionAlive();
		component.rememberSessionSnapshot();
		component.injectSnapshotIntoLinks();
		component.handleExitSnapshotSessionLink();
	};

	/**
	 * Prompt to restore session.
	 *
	 * @returns {void}
	 */
	component.keepSessionAlive = function keepSessionAlive() {
		var currentSnapshotUuid, urlParser;
		if ( ! component.hasSessionStorage ) {
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
		if ( ! component.hasSessionStorage || ! component.data.uuid ) {
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
		$( document.documentElement ).on( 'click focus mouseover', 'a, area', function( event ) {
			component.injectLinkQueryParam( this );

			if ( 'click' === event.type && ! component.doesLinkHaveSnapshotQueryParam( this ) && ! $( this ).parent().hasClass( 'ab-customize-snapshots-item' ) ) {
				if ( confirm( component.data.l10n.leaveSessionPrompt ) ) {
					if ( component.hasSessionStorage ) {
						sessionStorage.removeItem( 'customize_snapshot_uuid' );
					}
				} else {
					event.preventDefault();
				}
			}
		} );
	};

	/**
	 * Should the supplied link have a snapshot UUID added (or does it have one already)?
	 *
	 * @param {HTMLAnchorElement|HTMLAreaElement} element Link element.
	 * @param {string} element.search Query string.
	 * @param {string} element.pathname Path.
	 * @param {string} element.hostname Hostname.
	 * @returns {boolean} Is appropriate for snapshot link.
	 */
	component.shouldLinkHaveSnapshotParam = function shouldLinkHaveSnapshotParam( element ) {

		// Skip links to different hosts.
		if ( element.hostname !== component.data.home_url.host ) {
			return false;
		}

		// Skip links that aren't under the home path.
		if ( 0 !== element.pathname.indexOf( component.data.home_url.path ) ) {
			return false;
		}

		// Skip wp login and signup pages.
		if ( /\/wp-(login|signup)\.php$/.test( element.pathname ) ) {
			return false;
		}

		// Allow links to admin ajax as faux frontend URLs.
		if ( /\/wp-admin\/admin-ajax\.php$/.test( element.pathname ) ) {
			return true;
		}

		// Disallow links to admin.
		if ( /\/wp-admin(\/|$)/.test( element.pathname ) ) {
			return false;
		}

		// Skip links in admin bar.
		if ( $( element ).closest( '#wpadminbar' ).length ) {
			return false;
		}

		return true;
	};

	/**
	 * Return whether the supplied link element has the snapshot query param.
	 *
	 * @param {HTMLAnchorElement|HTMLAreaElement} element Link element.
	 * @param {object} element.search Query string.
	 * @returns {boolean} Whether query param is present.
	 */
	component.doesLinkHaveSnapshotQueryParam = function( element ) {
		return /(^|&)customize_snapshot_uuid=/.test( element.search.substr( 1 ) );
	};

	/**
	 * Inject the customize_snapshot_uuid query param into links on the frontend.
	 *
	 * @param {HTMLAnchorElement|HTMLAreaElement} element Link element.
	 * @param {object} element.search Query string.
	 * @returns {void}
	 */
	component.injectLinkQueryParam = function injectLinkQueryParam( element ) {
		if ( component.doesLinkHaveSnapshotQueryParam( element ) || ! component.shouldLinkHaveSnapshotParam( element ) ) {
			return;
		}

		if ( element.search.length > 1 ) {
			element.search += '&';
		}
		element.search += 'customize_snapshot_uuid=' + encodeURIComponent( component.data.uuid );
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
				sessionStorage.removeItem( 'customize_snapshot_uuid' );
			} );
		} );
	};

	return component;
} )( jQuery );

