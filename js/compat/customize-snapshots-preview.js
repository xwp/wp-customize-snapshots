/* global jQuery, JSON */
/* exported CustomizeSnapshotsPreview */
/* eslint consistent-this: [ "error", "section" ], no-magic-numbers: [ "error", { "ignore": [-1,0,1] } ] */

/*
 * The code here is derived from Customize REST Resources: https://github.com/xwp/wp-customize-rest-resources
 */

var CustomizeSnapshotsPreview = (function( api, $ ) {
	'use strict';

	var component = {
		data: {
			home_url: {
				scheme: '',
				host: '',
				path: ''
			},
			rest_api_url: {
				scheme: '',
				host: '',
				path: ''
			},
			admin_ajax_url: {
				scheme: '',
				host: '',
				path: ''
			},
			initial_dirty_settings: []
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

		component.injectSnapshotIntoAjaxRequests();
		component.handleFormSubmissions();
	};

	/**
	 * Get customize query vars.
	 *
	 * @see wp.customize.previewer.query
	 *
	 * @returns {{
	 *     customized: string,
	 *     nonce: string,
	 *     wp_customize: string,
	 *     theme: string
	 * }} Query vars.
	 */
	component.getCustomizeQueryVars = function getCustomizeQueryVars() {
		var customized = {};
		api.each( function( setting ) {
			if ( setting._dirty || -1 !== _.indexOf( component.data.initial_dirty_settings, setting.id ) ) {
				customized[setting.id] = setting.get();
			}
		} );
		return {
			wp_customize: 'on',
			theme: api.settings.theme.stylesheet,
			nonce: api.settings.nonce.preview,
			customized: JSON.stringify( customized )
		};
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
	 * This will not work 100% of the time, such as if an Admin Ajax handler is
	 * specifically looking for a $_GET param vs a $_POST param.
	 *
	 * @param {object} options Options.
	 * @param {string} options.type Type.
	 * @param {string} options.url URL.
	 * @param {object} originalOptions Original options.
	 * @param {XMLHttpRequest} xhr XHR.
	 * @returns {void}
	 */
	component.prefilterAjax = function prefilterAjax( options, originalOptions, xhr ) {
		var requestMethod, urlParser, queryVars, isMatchingHomeUrl, isMatchingRestUrl, isMatchingAdminAjaxUrl;

		urlParser = document.createElement( 'a' );
		urlParser.href = options.url;

		isMatchingHomeUrl = urlParser.host === component.data.home_url.host && 0 === urlParser.pathname.indexOf( component.data.home_url.path );
		isMatchingRestUrl = urlParser.host === component.data.rest_api_url.host && 0 === urlParser.pathname.indexOf( component.data.rest_api_url.path );
		isMatchingAdminAjaxUrl = urlParser.host === component.data.admin_ajax_url.host && 0 === urlParser.pathname.indexOf( component.data.admin_ajax_url.path );

		if ( ! isMatchingHomeUrl && ! isMatchingRestUrl && ! isMatchingAdminAjaxUrl ) {
			return;
		}

		requestMethod = options.type.toUpperCase();

		// Customizer currently requires POST requests, so use override (force Backbone.emulateHTTP).
		if ( 'POST' !== requestMethod ) {
			xhr.setRequestHeader( 'X-HTTP-Method-Override', requestMethod );
			options.type = 'POST';
		}

		if ( options.data && 'GET' === requestMethod ) {
			/*
			 * Make sure the query vars for the REST API persist in GET (since
			 * REST API explicitly look at $_GET['filter']).
			 * We have to make sure the REST query vars are added as GET params
			 * when the method is GET as otherwise they won't be parsed properly.
			 * The issue lies in \WP_REST_Request::get_parameter_order() which
			 * only is looking at \WP_REST_Request::$method instead of $_SERVER['REQUEST_METHOD'].
			 * @todo Improve \WP_REST_Request::get_parameter_order() to be more aware of X-HTTP-Method-Override
			 */
			if ( urlParser.search.substr( 1 ).length > 1 ) {
				urlParser.search += '&';
			}
			urlParser.search += options.data;
		}

		// Add Customizer post data.
		if ( options.data ) {
			options.data += '&';
		} else {
			options.data = '';
		}
		queryVars = component.getCustomizeQueryVars();
		queryVars.wp_customize_preview_ajax = 'true';
		options.data += $.param( queryVars );
	};

	/**
	 * Handle form submissions.
	 *
	 * This fixes Core ticket {@link https://core.trac.wordpress.org/ticket/20714|#20714: Theme customizer: Impossible to preview a search results page}
	 * Implements todo in {@link https://github.com/xwp/wordpress-develop/blob/4.5.3/src/wp-includes/js/customize-preview.js#L69-L73}
	 *
	 * @returns {void}
	 */
	component.handleFormSubmissions = function handleFormSubmissions() {
		$( function() {

			// Defer so that we can be sure that our event handler will come after any other event handlers.
			_.defer( function() {
				component.replaceFormSubmitHandler();
			} );
		} );
	};

	/**
	 * Replace form submit handler.
	 *
	 * @returns {void}
	 */
	component.replaceFormSubmitHandler = function replaceFormSubmitHandler() {
		var body = $( document.body );
		body.off( 'submit.preview' );
		body.on( 'submit.preview', 'form', function( event ) {
			var urlParser;

			/*
			 * If the default wasn't prevented already (in which case the form
			 * submission is already being handled by JS), and if it has a GET
			 * request method, then take the serialized form data and add it as
			 * a query string to the action URL and send this in a url message
			 * to the Customizer pane so that it will be loaded. If the form's
			 * action points to a non-previewable URL, the the Customizer pane's
			 * previewUrl setter will reject it so that the form submission is
			 * a no-op, which is the same behavior as when clicking a link to an
			 * external site in the preview.
			 */
			if ( ! event.isDefaultPrevented() && 'GET' === this.method.toUpperCase() ) {
				urlParser = document.createElement( 'a' );
				urlParser.href = this.action;
				if ( urlParser.search.substr( 1 ).length > 1 ) {
					urlParser.search += '&';
				}
				urlParser.search += $( this ).serialize();
				api.preview.send( 'url', urlParser.href );
			}

			// Now preventDefault as is done on the normal submit.preview handler in customize-preview.js.
			event.preventDefault();
		} );
	};

	return component;
} )( wp.customize, jQuery );
