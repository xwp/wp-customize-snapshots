/* global jQuery, wp */
/* exported CustomizeSnapshotsAdmin */

var CustomizeSnapshotsAdmin = (function( $ ) {
	'use strict';

	var component = {
		data: {
			deleteInputName: 'customize_snapshot_remove_settings[]'
		}
	};

	/**
	 * Initialize component.
	 *
	 * @return {void}
	 */
	component.init = function() {
		component.forkButton = $( '#snapshot-fork' );
		component.forkSpinner = $( '.snapshot-fork-spinner' );
		component.forkList = $( '#snapshot-fork-list' );
		component.toogleSettingRemovalLink = $( '.snapshot-toggle-setting-removal' );

		component.forkItemTemplate = wp.template( 'snapshot-fork-item' );
		component.linkActions = {
			remove: 'remove',
			restore: 'restore'
		};
		component.dataSlug = 'cs-action';

		component.toogleSettingRemovalLink.data( component.dataSlug, component.linkActions.remove );

		component.forkButton.on( 'click', component.fork );
		component.toogleSettingRemovalLink.on( 'click', component.toggleSettingRemoval );
	};

	/**
	 * Handles snapshot fork actions.
	 *
	 * @return {void}
	 */
	component.fork = function( event ) {
		var request;

		event.preventDefault();

		component.forkButton.prop( 'disabled', true );
		component.forkSpinner.addClass( 'is-active' );

		request = wp.ajax.post( 'snapshot_fork', {
			post_id: component.forkButton.data( 'post-id' ),
			nonce: component.forkButton.data( 'nonce' )
		} );

		request.done( function( data ) {
			component.forkList.append( $( $.trim( component.forkItemTemplate( data ) ) ) );
		} );

		request.always( function() {
			component.forkSpinner.removeClass( 'is-active' );
			component.forkButton.prop( 'disabled', false );
		} );
	};

	/**
	 * Change link text.
	 *
	 * @param {object} link jQuery selector of toggle setting removal link.
	 * @return {void}
	 */
	component.changeLinkText = function( link ) {
		var oldLinkText = link.text(),
			newLinkText = link.data( 'text-restore' );

		link.data( 'text-restore', oldLinkText ).text( newLinkText );
	};

	/**
	 * Show setting and changeset link text.
	 *
	 * @param {object} link jQuery selector of toggle setting removal link.
	 * @return {void}
	 */
	component.showSettingAndChangeLinkText = function( link ) {
		var settingId = link.attr( 'id' );

		link.data( component.dataSlug, component.linkActions.remove );
		component.changeLinkText( link );
		$( 'input[name="' + component.data.deleteInputName + '"][value="' + settingId + '"]' ).remove();
		link.parents( 'details' ).removeClass( 'snapshot-setting-removed' );
	};

	/**
	 * Hide setting and change link text.
	 *
	 * @param {object} link jQuery selector of toggle setting removal link.
	 * @return {void}
	 */
	component.hideSettingAndChangeLinkText = function( link ) {
		var hiddenInput, settingId = link.attr( 'id' );

		hiddenInput = $( '<input>' ).attr( {
			'name': component.data.deleteInputName,
			'type': 'hidden'
		} ).val( settingId );

		link.data( component.dataSlug, component.linkActions.restore ).after( hiddenInput );
		component.changeLinkText( link );
		link.parents( 'details' ).removeAttr( 'open' ).addClass( 'snapshot-setting-removed' );
	};

	/**
	 * Remove or restore settings.
	 *
	 * @param {object} event Event.
	 * @return {void}
	 */
	component.toggleSettingRemoval = function( event ) {
		var link = $( this );

		event.preventDefault();

		if ( component.linkActions.remove === link.data( component.dataSlug ) ) {
			component.hideSettingAndChangeLinkText( link );
		} else if ( component.linkActions[ 1 ] === link.data( component.dataSlug ) ) {
			component.showSettingAndChangeLinkText( link );
		}
	};

	return component;
})( jQuery );
