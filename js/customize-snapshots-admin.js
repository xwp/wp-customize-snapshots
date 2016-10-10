(function( $ ) {
	'use strict';
	$( function() {
		var component = {};
		component.initialize = function() {
			component.conflict_setting();
		};

		component.conflict_setting = function() {
			$( 'input.snapshot-resolved-settings' ).change( function() {
				var $this = $( this ),
					selector = '#' + $this.data( 'settingValueSelector' );
				$( selector ).html( $this.parents( 'details' ).find( '.snapshot-conflict-setting-data' ).html() );
			} );
		};

		component.initialize();
	} );
})( jQuery );
