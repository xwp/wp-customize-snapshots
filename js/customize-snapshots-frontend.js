/* global jQuery, confirm */
/* exported CustomizeSnapshotsFrontend */
/* eslint consistent-this: [ "error", "section" ], no-magic-numbers: [ "error", { "ignore": [-1,0,1] } ] */
/* eslint-disable no-alert */

/*
 * The code here is derived from the initial Transactions pull request: https://github.com/xwp/wordpress-develop/pull/61
 * See https://github.com/xwp/wordpress-develop/blob/97fd5019c488a0713d34b517bdbff67c62c48a5d/src/wp-includes/js/customize-preview.js#L98-L111
 */

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

		component.hasSessionStorage = 'undefined' !== typeof sessionStorage;

		component.keepSessionAlive();
		component.rememberSessionSnapshot();
		component.injectSnapshotIntoLinks();
		component.handleExitSnapshotSessionLink();
		component.injectSnapshotIntoAjaxRequests();
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
		currentSnapshotUuid = sessionStorage.getItem( 'customize_snapshot_uuid' );
		if ( ! currentSnapshotUuid || component.data.uuid ) {
			return;
		}

		urlParser = document.createElement( 'a' );
		urlParser.href = location.href;
		if ( urlParser.search.length > 1 ) {
			urlParser.search += '&';
		}
		urlParser.search += 'customize_snapshot_uuid=' + encodeURIComponent( sessionStorage.getItem( 'customize_snapshot_uuid' ) );

		$( function() {
			adminBarItem = $( '#wp-admin-bar-resume-customize-snapshot' );
			if ( adminBarItem.length ) {
				adminBarItem.find( '> a' ).prop( 'href', urlParser.href );
				adminBarItem.show();
			} else if ( confirm( component.data.l10n.restoreSessionPrompt ) ) {
				location.replace( urlParser.href );
			} else {
				sessionStorage.removeItem( 'customize_snapshot_uuid' );
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
		sessionStorage.setItem( 'customize_snapshot_uuid', component.data.uuid );
	};

	/**
	 * Inject the snapshot UUID into links in the document.
	 *
	 * @returns {void}
	 */
	component.injectSnapshotIntoLinks = function injectSnapshotIntoLinks() {
		var linkSelectors = 'a, area';

		if ( ! component.data.uuid ) {
			return;
		}
		$( function() {

			// Inject links into initial document.
			$( document.body ).find( linkSelectors ).each( function() {
				component.injectLinkQueryParam( this );
			} );

			// Inject links for new elements added to the page
			if ( 'undefined' !== typeof MutationObserver ) {
				component.mutationObserver = new MutationObserver( function( mutations ) {
					_.each( mutations, function( mutation ) {
						$( mutation.target ).find( linkSelectors ).each( function() {
							component.injectLinkQueryParam( this );
						} );
					} );
				} );
				component.mutationObserver.observe( document.documentElement, {
					childList: true,
					subtree: true
				} );
			} else {

				// If mutation observers aren't available, fallback to just-in-time injection.
				$( document.documentElement ).on( 'click focus mouseover', linkSelectors, function() {
					component.injectLinkQueryParam( this );
				} );
			}
		} );
	};

	/**
	 * Is matching base URL (host and path)?
	 *
	 * @param {HTMLAnchorElement} parsedUrl Parsed URL.
	 * @param {string} parsedUrl.hostname Host.
	 * @param {string} parsedUrl.pathname Path.
	 * @returns {boolean} Whether matched.
	 */
	component.isMatchingBaseUrl = function isMatchingBaseUrl( parsedUrl ) {
		return parsedUrl.hostname === component.data.home_url.host && 0 === parsedUrl.pathname.indexOf( component.data.home_url.path );
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
		if ( ! component.isMatchingBaseUrl( element ) ) {
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

	/**
	 * Inject the snapshot UUID into Ajax requests.
	 *
	 * @return {void}
	 */
	component.injectSnapshotIntoAjaxRequests = function injectSnapshotIntoAjaxRequests() {
		$.ajaxPrefilter( component.prefilterAjax );
	};

	/**
	 * Rewrite Ajax requests to inject Customizer state.
	 *
	 * @param {object} options Options.
	 * @param {string} options.type Type.
	 * @param {string} options.url URL.
	 * @returns {void}
	 */
	component.prefilterAjax = function prefilterAjax( options ) {
		var urlParser;
		if ( ! component.data.uuid ) {
			return;
		}

		urlParser = document.createElement( 'a' );
		urlParser.href = options.url;

		// Abort if the request is not for this site.
		if ( ! component.isMatchingBaseUrl( urlParser ) ) {
			return;
		}

		// Skip if snapshot UUID already in URL.
		if ( -1 !== urlParser.search.indexOf( 'customize_snapshot_uuid=' + component.data.uuid ) ) {
			return;
		}

		if ( urlParser.search.substr( 1 ).length > 0 ) {
			urlParser.search += '&';
		}
		urlParser.search += 'customize_snapshot_uuid=' + component.data.uuid;

		options.url = urlParser.href;
	};

	return component;
} )( jQuery );

