/* global jQuery, wp */
(function( $ ) {
	'use strict';
	var component = {
		doingAjax: false,
		postMigrationCount: 5
	};

	/**
	 * Initialize js.
	 *
	 * @return {void}
	 */
	component.init = function() {
		$( function() {
			component.el = $( '#customize-snapshot-migration' );
			component.bindClick();
			component.spinner = $( '.spinner.customize-snapshot-spinner' );
			component.spinner.css( 'margin', '0' );
		} );
	};

	/**
	 * Bind migrate click event.
	 *
	 * @return {void}
	 */
	component.bindClick = function() {
		component.el.click( function() {
			if ( component.doingAjax ) {
				return;
			}
			component.spinner.css( 'visibility', 'visible' );
			component.doingAjax = true;
			component.migrate( component.el.data( 'nonce' ), component.postMigrationCount );
		} );
	};

	/**
	 * Initiate migrate ajax request.
	 *
	 * @param {String} nonce Nonce.
	 * @param {Number} limit Limit for migrate posts.
	 *
	 * @return {void}
	 */
	component.migrate = function( nonce, limit ) {
		var request,
			requestData = {
				nonce: nonce,
				limit: limit
			};

		request = wp.ajax.post( 'customize_snapshot_migration', requestData );

		request.done( function( data ) {
			var outerDiv = $( 'div.customize-snapshot-migration' ), delay = 100, newLimit;
			if ( data.remaining_posts ) {
				newLimit = data.remaining_posts > limit ? limit : data.remaining_posts;
				_.delay( component.migrate, delay, nonce, newLimit );
			} else {
				component.spinner.css( 'visibility', 'hidden' );
				outerDiv.removeClass( 'notice-error' ).addClass( 'notice-success' ).find( 'p' ).html( component.el.data( 'migration-success' ) );
				component.doingAjax = false;
			}
		} );

		request.fail( function() {
			component.spinner.css( 'visibility', 'initial' );
			component.doingAjax = false;
			if ( window.console ) {
				window.console.error( 'Migration ajax failed. Click notice to start it again.' );
			}
		} );
	};

	component.init();

})( jQuery );
