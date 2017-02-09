/* global jQuery */
/* exported CustomizeSnapshotsFront */
var CustomizeSnapshotsFront = (function( $ ) {
    'use strict';

    var component = {
        data: {
            confirmationMsg: ''
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

    component.setupPublishButton = function setupPublishButton() {
        var publishBtn = $( '#wp-admin-bar-publish-customize-snapshot a' );

        if ( ! publishBtn.length ) {
            return;
        }

        publishBtn.click( function( e ) {
            e.preventDefault();

            if ( ! confirm( component.data.confirmationMsg ) ) {
                return false;
            }

            return true;
        } );
    };

    return component;
})( jQuery );