/* global jQuery */
/* exported CustomizeSnapshotsFrontend */
/* eslint consistent-this: [ "error", "section" ], no-magic-numbers: [ "error", { "ignore": [0,1] } ] */

// @todo Use session storage to make sure the user stays in the snapshot frontend preview, unless explicitly exited. Warn with confirm if clicking.
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
		if ( ! args.uuid ) {
			throw new Error( 'Missing UUID' );
		}

		_.extend( component.data, args );

		// Inject snapshot UUID into links on click.
		$( document.documentElement ).on( 'click focus mouseover', 'a, area', function() {
			component.injectQueryParam( this );
		} );
	};

	/**
	 * Inject the customize_snapshot_uuid query param into links on the frontend.
	 *
	 * @param {HTMLAnchorElement|HTMLAreaElement} element Link element.
	 * @param {object} element.search Query string.
	 * @returns {void}
	 */
	component.injectQueryParam = function injectQueryParam( element ) {
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

