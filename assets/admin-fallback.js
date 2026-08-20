/**
 * WP AI Health Check - Fallback JavaScript
 * Wird nur verwendet, wenn die AdminPage-Klasse nicht geladen werden kann.
 */
( function() {
    'use strict';

    document.addEventListener( 'DOMContentLoaded', function() {
        const runBtn = document.getElementById( 'wpaic-run' );
        const spinner = document.getElementById( 'wpaic-spinner' );
        const output = document.getElementById( 'wpaic-output' );

        if ( ! runBtn ) {
            return;
        }

        const cfg = window.WPAIC_FALLBACK_CFG || {};

        runBtn.addEventListener( 'click', function() {
            const btn = this;

            btn.disabled = true;
            spinner.classList.add( 'is-active' );
            output.innerHTML = '<p>Analyse läuft...</p>';

            const nonce = cfg.nonce || '';
            const restUrl = cfg.restUrl || '/wp-json/wpaic/v1/analyze';

            fetch( restUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                body: JSON.stringify( { force: true } )
            } )
            .then( async function( res ) {
                const contentType = res.headers.get( 'content-type' );
                if ( ! contentType || ! contentType.includes( 'application/json' ) ) {
                    const text = await res.text();
                    throw new Error( 'Server antwortete mit HTML (Status: ' + res.status + ')' );
                }
                return res.json();
            } )
            .then( function( data ) {
                if ( data.success ) {
                    output.innerHTML = '<pre>' + JSON.stringify( data.result, null, 2 ) + '</pre>';
                    setTimeout( function() {
                        location.reload();
                    }, 2000 );
                } else {
                    output.innerHTML = '<div class="notice notice-error"><p>Fehler: ' + ( data.error || 'Unbekannt' ) + '</p></div>';
                }
            } )
            .catch( function( err ) {
                output.innerHTML = '<div class="notice notice-error"><p>❌ Fehler: ' + err.message + '</p></div>';
            } )
            .finally( function() {
                btn.disabled = false;
                spinner.classList.remove( 'is-active' );
            } );
        } );
    } );
} )( window );