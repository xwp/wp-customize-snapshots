/* global jQuery, wp */
/* exported CustomizeSnapshotsFront */
var CustomizeSnapshotsFront = (function( $ ) {
    'use strict';

    var component = {
        data: {
            confirmationMsg: '',
            action: '',
            snapshotsFrontendPublishNonce: ''
        }
    };
    /**
     * Init.
     *
     * @param {object} args Args.
     * @returns {void}
     */
    component.init = function init( args ) {
        _.extend( component.data, args );

        $( document ).ready( function() {
            component.setupPublishButton();
        } );
    };

    /**
     * Set up snapshot frontend publish button.
     *
     * @returns {void}
     */
    component.setupPublishButton = function setupPublishButton() {
        var publishBtn = $( '#wp-admin-bar-publish-customize-snapshot a' );

        if ( ! publishBtn.length ) {
            return;
        }

        publishBtn.click( function( e ) {
            var request;
            e.preventDefault();

            if ( ! confirm( component.data.confirmationMsg ) ) {
                return false;
            }
            request = wp.ajax.post( component.data.action, {
                nonce: component.data.snapshotsFrontendPublishNonce,
                uuid: component.data.uuid
            } );
            request.done( function( data ) {
                if ( data && data.success ){
                    window.location = e.target.href; // @todo Redirect to correct URL.
                }
            } );
            request.fail( function( data ) {
                alert( data.errorMsg ); // @todo Maybe tune the way of failure notice.
            } );

            return true;
        } );
    };

    return component;
})( jQuery );