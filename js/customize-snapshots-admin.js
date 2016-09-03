( function( $ ) {
	'use strict';

	$( function() {
		var component, linkSelector, linkText, linkActions, dataSlug, inputName;

		component = {};
		linkSelector = '.snapshot-toggle-setting-removal';
		linkText = [ 'Remove setting', 'Restore setting' ];
		linkActions = [ 'remove', 'restore' ];
		dataSlug = 'cs-action';
		inputName = 'customize_snapshot_remove_settings[]';

		component.initializeLink = function() {
			$( linkSelector ).text( linkText[ 0 ] )
				.data( dataSlug, linkActions[ 0 ] );
		};

		component.initializeLink();

		component.isLinkSetToRemoveSetting = function( $link ) {
			return linkActions[ 0 ] === component.getClickedLinkAction( $link );
		};

		component.isLinkSetToRestoreSetting = function( $link ) {
			return linkActions[ 1 ] === component.getClickedLinkAction( $link );
		};

		component.getClickedLinkAction = function( $link ) {
			return $link.data( dataSlug );
		};

		component.hideSettingAndChangeLinkText = function( $link ) {
			var $settingDisplay, settingId;
			$settingDisplay = component.getSettingDisplay( $link );
			settingId = component.getSettingId( $link );

			$link.text( linkText[ 1 ] )
				.data( dataSlug, linkActions[ 1 ] )
				.after( component.constructHiddenInputWithValue( settingId ) );
			$settingDisplay.removeAttr( 'open' )
				.addClass( 'snapshot-setting-removed' );
		};

		component.getSettingDisplay = function( $link ) {
			return $link.parents( 'details' );
		};

		component.getSettingId = function( $link ) {
			return $link.attr( 'id' );
		};

		component.constructHiddenInputWithValue = function( settingId ) {
			return $( '<input>' ).attr( {
				'name': inputName,
				'type': 'hidden'
			} )
			.val( settingId );
		};

		component.showSettingAndChangeLinkText = function( $link ) {
			var $settingDisplay, settingId;
			$settingDisplay = component.getSettingDisplay( $link );
			settingId = component.getSettingId( $link );

			$link.text( linkText[ 0 ] )
				.data( dataSlug, linkActions[ 0 ] );
			component.removeHiddenInputWithValue( settingId );
			$settingDisplay.removeClass( 'snapshot-setting-removed' );
		};

		component.removeHiddenInputWithValue = function( settingId ) {
			$( 'input[name="' + inputName + '"][value="' + settingId + '"]' ).remove();
		};

		$( linkSelector ).on( 'click', function( event ) {
			var $clickedLink = $( this );

			event.preventDefault();

			if ( component.isLinkSetToRemoveSetting( $clickedLink ) ) {
				component.hideSettingAndChangeLinkText( $clickedLink );
			} else if ( component.isLinkSetToRestoreSetting( $clickedLink ) ) {
				component.showSettingAndChangeLinkText( $clickedLink );
			}
		} );

	} );
} )( jQuery );
