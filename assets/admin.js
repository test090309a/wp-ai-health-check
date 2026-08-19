( function ( wp ) {
    const runBtn  = document.getElementById( 'wpaic-run' );
    const output  = document.getElementById( 'wpaic-output' );
    const spinner = document.getElementById( 'wpaic-spinner' );
    const cfg     = window.WPAIC_CFG;

    if ( ! runBtn ) return;

    runBtn.addEventListener( 'click', async () => {
        spinner.classList.add( 'is-active' );
        runBtn.disabled = true;
        output.innerHTML = '<p>' + cfg.i18n.running + '</p>';

        try {
            const res = await fetch( cfg.restUrl + '/analyze', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   cfg.nonce,
                },
                body: JSON.stringify( { force: true } ),
            } );

            const data = await res.json();

            if ( ! data.success ) {
                output.innerHTML = '<div class="notice notice-error"><p>' +
                    ( data.error || cfg.i18n.failed ) + '</p></div>';
                return;
            }

            renderResult( data.result );

            // Scroll zum Ergebnis
            output.scrollIntoView( { behavior: 'smooth', block: 'start' } );

        } catch ( e ) {
            output.innerHTML = '<div class="notice notice-error"><p>' +
                cfg.i18n.failed + ' ' + escHtml( e.message ) + '</p></div>';
        } finally {
            spinner.classList.remove( 'is-active' );
            runBtn.disabled = false;
        }
    } );

    function renderResult( r ) {
        if ( ! r || typeof r !== 'object' ) {
            output.innerHTML = '<pre>' + escHtml( String( r ) ) + '</pre>';
            return;
        }

        let html = '';

        // Summary
        if ( r.summary ) {
            html += '<div class="notice notice-info"><p>' +
                escHtml( r.summary ) + '</p></div>';
        }

        // Risks
        if ( r.risks && Array.isArray( r.risks ) && r.risks.length > 0 ) {
            html += '<h3>' + wp.i18n.__( 'Risiken', 'wp-ai-health-check' ) + '</h3>';
            html += '<ul>';
            r.risks.forEach( function( risk ) {
                const level = risk.level || 'low';
                html += '<li><strong class="wpaic-' + escAttr( level ) + '">' +
                    escHtml( risk.title || '' ) + '</strong>: ' +
                    escHtml( risk.detail || '' ) + '</li>';
            } );
            html += '</ul>';
        }

        // Recommendations
        if ( r.recommendations && Array.isArray( r.recommendations ) && r.recommendations.length > 0 ) {
            html += '<h3>' + wp.i18n.__( 'Empfehlungen', 'wp-ai-health-check' ) + '</h3>';
            html += '<ul>';
            r.recommendations.forEach( function( rec ) {
                html += '<li>' + escHtml( rec ) + '</li>';
            } );
            html += '</ul>';
        }

        output.innerHTML = html || '<p>' + cfg.i18n.noResult + '</p>';
    }

    function escHtml( s ) {
        const d = document.createElement( 'div' );
        d.textContent = String( s );
        return d.innerHTML;
    }

    function escAttr( s ) {
        return String( s ).replace( /["'<>&]/g, function( c ) {
            const map = {
                '"': '&quot;',
                "'": '&#39;',
                '<': '&lt;',
                '>': '&gt;',
                '&': '&amp;'
            };
            return map[ c ] || c;
        } );
    }
} )( window.wp );