/* global jQuery */
(function( $, wp ) {
	'use strict';
	var component = {};

	component.forkClick = function() {
		var $forkButton = $( '#snapshot-fork' ),
			$forkSnipper = $( '.snapshot-fork-spinner' ),
			$forkList = $( '#snapshot-fork-list' ),
			forkItemTemplate = wp.template( 'snapshot-fork-item' );
		$forkButton.on( 'click', function( e ) {
			var request;
			$forkSnipper.addClass( 'is-active' );
			request = wp.ajax.post( 'snapshot_fork', {
				ID: $forkButton.data( 'post-id' ),
				nonce: $forkButton.data( 'nonce' )
			} );
			request.always( function() {
				$forkSnipper.removeClass( 'is-active' );
			} );
			request.done( function( data ) {
				var item;
				item = $( $.trim( forkItemTemplate( data ) ) );
				$forkList.append( item );
			} );
			e.preventDefault();
		} );
	};
	component.init = function() {
		$( function() {
			component.forkClick();
		} );
	};
	component.init();
})( jQuery, window.wp );
