/* global jQuery, wp */
/* exported CustomizeSnapshotsAdmin */

var CustomizeSnapshotsAdmin = (function( $ ) {
	'use strict';

	var component = {
		data: {
			deleteInputName: 'customize_changeset_remove_settings[]',
			postId: 0,
			forkNonce: ''
		},
		dataSlug: 'cs-action',
		linkActions: {
			remove: 'remove',
			restore: 'restore'
		}
	};

	/**
	 * Initialize component.
	 *
	 * @param {object} data - Data.
	 * @return {void}
	 */
	component.init = function( data ) {
		_.extend( component.data, data );

		component.forkButtons = $( 'button.snapshot-fork' );
		component.forkList = $( '#snapshot-fork-list' );
		component.toogleSettingRemovalLink = $( '.snapshot-toggle-setting-removal' );

		component.forkItemTemplate = wp.template( 'snapshot-fork-item' );
		component.toogleSettingRemovalLink.data( component.dataSlug, component.linkActions.remove );

		component.forkButtons.on( 'click', component.fork );
		component.toogleSettingRemovalLink.on( 'click', component.toggleSettingRemoval );
	};

	/**
	 * Handles snapshot fork actions.
	 *
	 * @return {void}
	 */
	component.fork = function fork() {
		var request;

		component.forkButtons.addClass( 'disabled loading' );

		request = wp.ajax.post( 'snapshot_fork', {
			post_id: component.data.postId,
			nonce: component.data.forkNonce
		} );

		request.done( function( data ) {
			var listItem = $( $.trim( component.forkItemTemplate( data ) ) ), showMetaboxToggle, metaboxContainer;

			// Make sure the metabox is shown and expanded.
			showMetaboxToggle = $( '#customize_changeset-fork-hide' );
			if ( ! showMetaboxToggle.prop( 'checked' ) ) {
				showMetaboxToggle.click();
			}
			metaboxContainer = $( '#customize_changeset-fork' );
			if ( metaboxContainer.hasClass( 'closed' ) ) {
				metaboxContainer.find( '> .handlediv' ).click();
			}

			component.forkList.append( listItem );
			listItem.find( 'a' ).focus();
		} );

		request.always( function() {
			component.forkButtons.removeClass( 'disabled loading' );
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
		} else if ( component.linkActions.restore === link.data( component.dataSlug ) ) {
			component.showSettingAndChangeLinkText( link );
		}
	};

	return component;
})( jQuery );
