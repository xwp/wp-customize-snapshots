/* global jQuery, wp */
(function( $ ) {
	'use strict';
	var component = {
		doingAjax: false,
		postMigrationCount: 20
	};
	component.init = function() {
		$( function() {
			component.el = $( '#customize-snapshot-migration' );
			component.bindClick();
			component.spinner = $( '.spinner.customize-snapshot-spinner' );
			component.spinner.css( 'margin', '0' );
		} );
	};
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
	component.migrate = function( nonce, limit ) {
		var requestData, request;
		requestData = {
			nonce: nonce,
			limit: limit
		};
		request = wp.ajax.post( 'customize_snapshot_migration', requestData );
		request.always( function( data ) {
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
	};
	component.init();
})( jQuery );
