/* global jQuery */
/* exported CustomizeSnapshotsAdmin */
var CustomizeSnapshotsAdmin = (function( $, wp ) {
	'use strict';
	var component = {};

	component.data = {
		deleteInputName: 'customize_snapshot_remove_settings[]'
	};

	/**
	 * Handles snapshot fork actions.
	 *
	 * @return {void}
	 */
	component.forkClick = function() {
		var $forkButton = $( '#snapshot-fork' ),
			$forkSpinner = $( '.snapshot-fork-spinner' ),
			$forkList = $( '#snapshot-fork-list' ),
			forkItemTemplate = wp.template( 'snapshot-fork-item' );

		$forkButton.on( 'click', function( e ) {
			var request;
			$forkSpinner.addClass( 'is-active' );
			request = wp.ajax.post( 'snapshot_fork', {
				ID: $forkButton.data( 'post-id' ),
				nonce: $forkButton.data( 'nonce' )
			} );

			request.always( function() {
				$forkSpinner.removeClass( 'is-active' );
			} );

			request.done( function( data ) {
				var item;
				item = $( $.trim( forkItemTemplate( data ) ) );
				$forkList.append( item );
			} );

			e.preventDefault();
		} );
	};

	/**
	 * Initialize component.
	 *
	 * @return {void}
	 */
	component.init = function() {
		$( function() {
			component.forkClick();
			component.deleteSetting();
		} );
	};

	/**
	 * Handles snapshot setting delete actions.
	 *
	 * @return {void}
	 */
	component.deleteSetting = function() {
		var $linkToRemoveOrRestore = $( '.snapshot-toggle-setting-removal' ),
			linkActions = ['remove', 'restore'],
			dataSlug = 'cs-action';

		$linkToRemoveOrRestore.data( dataSlug, linkActions[0] );

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

			$link.data( dataSlug, linkActions[ 1 ] )
				.after( component.constructHiddenInputWithValue( settingId ) );
			component.changeLinkText( $link );
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
				'name': component.data.deleteInputName,
				'type': 'hidden'
			} )
			.val( settingId );
		};

		component.changeLinkText = function( $link ) {
			var oldLinkText, newLinkText;
			oldLinkText = $link.text();
			newLinkText = $link.data( 'text-restore' );

			$link.data( 'text-restore', oldLinkText )
				.text( newLinkText );
		};

		component.showSettingAndChangeLinkText = function( $link ) {
			var $settingDisplay, settingId;
			$settingDisplay = component.getSettingDisplay( $link );
			settingId = component.getSettingId( $link );

			$link.data( dataSlug, linkActions[ 0 ] );
			component.changeLinkText( $link );
			component.removeHiddenInputWithValue( settingId );
			$settingDisplay.removeClass( 'snapshot-setting-removed' );
		};

		component.removeHiddenInputWithValue = function( settingId ) {
			$( 'input[name="' + component.data.deleteInputName + '"][value="' + settingId + '"]' ).remove();
		};

		$linkToRemoveOrRestore.on( 'click', function( event ) {
			var $clickedLink = $( this );

			event.preventDefault();

			if ( component.isLinkSetToRemoveSetting( $clickedLink ) ) {
				component.hideSettingAndChangeLinkText( $clickedLink );
			} else if ( component.isLinkSetToRestoreSetting( $clickedLink ) ) {
				component.showSettingAndChangeLinkText( $clickedLink );
			}
		} );
	};

	return component;
})( jQuery, window.wp );
