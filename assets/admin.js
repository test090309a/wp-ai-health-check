( function ( wp ) {
    'use strict';

    // 🔥 Warten auf DOM-Bereitschaft
    document.addEventListener( 'DOMContentLoaded', function() {
        const runBtn  = document.getElementById( 'wpaic-run' );
        const output  = document.getElementById( 'wpaic-output' );
        const spinner = document.getElementById( 'wpaic-spinner' );
        
        // 🔥 Fallback für cfg, falls WPAIC_CFG nicht gesetzt ist
        let cfg = window.WPAIC_CFG || {};
        
        // 🔥 Sicherstellen dass alle benötigten cfg-Werte existieren
        if ( ! cfg.restUrl ) {
            console.warn( '⚠️ WPAIC_CFG.restUrl is undefined, using fallback' );
            cfg.restUrl = '/wp-json/wpaic/v1';
        }
        
        if ( ! cfg.nonce ) {
            console.warn( '⚠️ WPAIC_CFG.nonce is undefined, using fallback' );
            // Versuche nonce aus meta-Tag zu holen
            const metaNonce = document.querySelector( 'meta[name="X-WP-Nonce"]' );
            cfg.nonce = metaNonce ? metaNonce.content : '';
        }
        
        if ( ! cfg.i18n ) {
            cfg.i18n = {
                running: 'Analyse läuft…',
                failed: 'Analyse fehlgeschlagen.',
                noResult: 'Keine Ergebnisse vorhanden.'
            };
        }

        // 🔥 Debug-Log
        console.log( '🔍 WPAIC Config:', {
            restUrl: cfg.restUrl,
            nonce: cfg.nonce ? cfg.nonce.substring(0, 10) + '...' : '❌ MISSING',
            i18n: cfg.i18n,
            isLoggedIn: !!wp?.api?.settings?.root
        } );

        if ( ! runBtn ) {
            console.warn( '⚠️ wpaic-run button not found' );
            return;
        }

        // ============================================================
        // HAUPTFUNKTION: Analyse starten
        // ============================================================
        async function startAnalysis() {
            const url = cfg.restUrl + '/analyze';
            console.log( '🚀 Starting analysis at:', url );
            console.log( '📝 Using nonce:', cfg.nonce ? cfg.nonce.substring(0, 10) + '...' : '❌ MISSING' );

            spinner.classList.add( 'is-active' );
            runBtn.disabled = true;
            output.innerHTML = '<p>' + cfg.i18n.running + ' (bitte warten, kann bis zu 1200 Sekunden dauern)</p>';

            try {
                const res = await fetch( url, {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': cfg.nonce,
                    },
                    body: JSON.stringify( { force: true } ),
                } );

                console.log( '📡 Response Status:', res.status );
                console.log( '📡 Response Status Text:', res.statusText );
                console.log( '📡 Response Headers:', [...res.headers] );

                // 🔥 Prüfe auf HTTP-Fehler
                if ( !res.ok ) {
                    let errorMsg = 'HTTP ' + res.status + ': ' + res.statusText;
                    
                    if ( res.status === 404 ) {
                        errorMsg = '❌ REST-Endpunkt nicht gefunden!<br>' +
                                   'Bitte speichere die Permalinks unter ' +
                                   '<a href="' + window.location.origin + '/wp-admin/options-permalink.php">' +
                                   'Einstellungen > Permalinks</a> und lade die Seite neu.';
                    } else if ( res.status === 504 ) {
                        errorMsg = '⏳ Ollama antwortet zu langsam (Gateway Timeout).<br>' +
                                   'Tipps:<br>' +
                                   '• Wähle ein kleineres Modell (z.B. llama3.2:3b)<br>' +
                                   '• Prüfe ob Ollama läuft: ' + 
                                   '<a href="' + cfg.restUrl + '/test" target="_blank">Test-Endpunkt</a><br>' +
                                   '• Der Server hat nach 1200 Sekunden abgebrochen.';
                    } else if ( res.status === 403 ) {
                        errorMsg = '❌ Keine Berechtigung. Bitte als Admin anmelden.';
                    } else if ( res.status === 503 ) {
                        errorMsg = '❌ Ollama ist nicht erreichbar.<br>' +
                                   'Prüfe: ' + 
                                   '<a href="http://192.168.0.194:11434/api/tags" target="_blank">' +
                                   'http://192.168.0.194:11434/api/tags</a>';
                    }
                    
                    output.innerHTML = '<div class="notice notice-error"><p>' + errorMsg + '</p></div>';
                    return;
                }

                // 🔥 Prüfe Content-Type
                const contentType = res.headers.get( 'content-type' );
                if ( !contentType || !contentType.includes( 'application/json' ) ) {
                    const text = await res.text();
                    console.error( '❌ Kein JSON erhalten:', text.substring(0, 500) );
                    
                    // Prüfe ob es eine WordPress-Fehlerseite ist
                    if ( text.includes( '<html' ) || text.includes( '<!DOCTYPE' ) ) {
                        output.innerHTML = '<div class="notice notice-error"><p>' +
                            '❌ Server antwortete mit HTML statt JSON.<br>' +
                            'Dies passiert oft wenn:<br>' +
                            '• Der REST-Endpunkt nicht existiert (Permalinks speichern!)<br>' +
                            '• Ein PHP-Fehler aufgetreten ist (wp-content/debug.log prüfen)<br>' +
                            '• Die Berechtigung fehlt<br><br>' +
                            '<strong>Status:</strong> ' + res.status + ' ' + res.statusText + '<br>' +
                            '<strong>URL:</strong> ' + url + '<br>' +
                            '<strong>Nonce:</strong> ' + (cfg.nonce ? '✅ Vorhanden' : '❌ Fehlt') +
                            '</p><pre style="max-height:200px;overflow:auto;font-size:11px;">' + 
                            escHtml(text.substring(0, 500)) + '...</pre></div>';
                    } else {
                        output.innerHTML = '<div class="notice notice-error"><p>' +
                            '❌ Unerwartete Antwort: ' + escHtml(text.substring(0, 200)) + '</p></div>';
                    }
                    return;
                }

                const data = await res.json();
                console.log( '📊 Response Data:', data );

                if ( !data.success ) {
                    let errorMsg = data.error || cfg.i18n.failed;
                    
                    // 🔥 Detaillierte Fehleranalyse
                    if ( data.error && data.error.includes( 'timeout' ) ) {
                        errorMsg = '⏳ ' + errorMsg + '<br>' +
                                   'Versuche ein kleineres Modell (z.B. llama3.2:3b)';
                    }
                    
                    output.innerHTML = '<div class="notice notice-error"><p>' + errorMsg + '</p></div>';
                    return;
                }

                // 🔥 Erfolg - Ergebnis rendern
                console.log( '✅ Analyse erfolgreich, Dauer:', data.duration_ms + 'ms' );
                renderResult( data.result, output, cfg );

                // 🔥 Scroll zum Ergebnis
                output.scrollIntoView( { behavior: 'smooth', block: 'start' } );

                // 🔥 Seite nach 2 Sekunden neu laden
                setTimeout( function() {
                    console.log( '🔄 Reloading page to show persisted analysis...' );
                    window.location.reload();
                }, 2000 );

            } catch ( e ) {
                console.error( '❌ Fetch Error:', e );
                console.error( '❌ Error Stack:', e.stack );
                
                let errorMsg = cfg.i18n.failed + ': ' + e.message;
                
                // 🔥 Spezifische Fehlermeldungen
                if ( e.message.includes( 'Failed to fetch' ) ) {
                    errorMsg = '❌ Netzwerkfehler: Kann Server nicht erreichen.<br>' +
                               'Prüfe:<br>' +
                               '• Ist WordPress erreichbar?<br>' +
                               '• Ist der REST-Endpunkt korrekt? ' + cfg.restUrl + '<br>' +
                               '• Sind die Permalinks gespeichert?';
                } else if ( e.message.includes( 'Unexpected token' ) ) {
                    errorMsg = '❌ Server antwortete mit ungültigem JSON.<br>' +
                               'Prüfe wp-content/debug.log auf PHP-Fehler.';
                }
                
                output.innerHTML = '<div class="notice notice-error"><p>' + 
                    errorMsg + 
                    '<br><small>Siehe Browser-Konsole (F12) für Details.</small></p></div>';
            } finally {
                spinner.classList.remove( 'is-active' );
                runBtn.disabled = false;
            }
        }

        // ============================================================
        // FUNKTION: Ergebnis rendern
        // ============================================================
        function renderResult( r, container, cfg ) {
            if ( !r || typeof r !== 'object' ) {
                container.innerHTML = '<pre>' + escHtml( String( r ) ) + '</pre>';
                return;
            }

            let html = '';

            // 🔥 Summary
            if ( r.summary ) {
                html += '<div class="notice notice-info" style="margin:10px 0;border-left-color:#2271b1;">' +
                    '<h3 style="margin-top:0;">📋 Zusammenfassung</h3>' +
                    '<p style="font-size:14px;">' + escHtml( r.summary ) + '</p></div>';
            }

            // 🔥 Risks
            if ( r.risks && Array.isArray( r.risks ) && r.risks.length > 0 ) {
                html += '<h3>⚠️ Risiken</h3>';
                html += '<ul style="list-style:none;padding-left:0;">';
                r.risks.forEach( function( risk ) {
                    const level = risk.level || 'low';
                    const color = level === 'high' || level === 'critical' ? '#d63638' : 
                                  level === 'medium' ? '#dba617' : '#007cba';
                    const bgColor = level === 'high' || level === 'critical' ? '#fcf0f0' : 
                                    level === 'medium' ? '#fcf9f0' : '#f0f6fc';
                    html += '<li style="margin:8px 0;padding:12px 16px;background:' + bgColor + 
                            ';border-radius:4px;border-left:4px solid ' + color + ';">' +
                        '<span style="color:' + color + ';font-weight:bold;display:block;margin-bottom:4px;">' +
                        escHtml( risk.title || '' ) + '</span>' +
                        '<span style="display:block;color:#444;font-size:13px;">' + 
                        escHtml( risk.detail || '' ) + '</span>' +
                        '<span style="display:inline-block;font-size:10px;text-transform:uppercase;color:#fff;font-weight:bold;background:' + 
                        color + ';padding:1px 10px;border-radius:10px;margin-top:4px;">' +
                        escHtml( level ) + '</span></li>';
                } );
                html += '</ul>';
            }

            // 🔥 Recommendations
            if ( r.recommendations && Array.isArray( r.recommendations ) && r.recommendations.length > 0 ) {
                html += '<h3>💡 Empfehlungen</h3>';
                html += '<ol style="padding-left:25px;margin:10px 0;">';
                r.recommendations.forEach( function( rec ) {
                    html += '<li style="margin:6px 0;padding:4px 0;">' + escHtml( rec ) + '</li>';
                } );
                html += '</ol>';
            }

            container.innerHTML = html || '<p>' + (cfg.i18n?.noResult || 'Keine Ergebnisse.') + '</p>';
        }

        // ============================================================
        // HELPER: escHtml
        // ============================================================
        function escHtml( s ) {
            const d = document.createElement( 'div' );
            d.textContent = String( s );
            return d.innerHTML;
        }

        // ============================================================
        // HELPER: escAttr (beibehalten für Kompatibilität)
        // ============================================================
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

        // ============================================================
        // EVENT LISTENER
        // ============================================================
        runBtn.addEventListener( 'click', startAnalysis );
        console.log( '✅ WPAIC Admin JS loaded successfully' );

        // 🔥 Zusätzliche Test-Funktion für die Konsole
        window.wpaic_test = {
            startAnalysis: startAnalysis,
            cfg: cfg,
            renderResult: renderResult,
            escHtml: escHtml
        };
        console.log( '💡 Type "wpaic_test" in console for debugging' );

    } );
} )( window.wp );