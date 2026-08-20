( function ( wp ) {
    'use strict';

    // 🔥 Warten auf DOM-Bereitschaft
    document.addEventListener( 'DOMContentLoaded', function() {
        // ============================================================
        // KONFIGURATION
        // ============================================================
        let cfg = window.WPAIC_CFG || {};
        
        // 🔥 Fallback für cfg, falls WPAIC_CFG nicht gesetzt ist
        if ( ! cfg.restUrl ) {
            console.warn( '⚠️ WPAIC_CFG.restUrl is undefined, using fallback' );
            cfg.restUrl = '/wp-json/wpaic/v1';
        }
        
        if ( ! cfg.nonce ) {
            console.warn( '⚠️ WPAIC_CFG.nonce is undefined, using fallback' );
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
        
        if ( ! cfg.adminAjax ) {
            cfg.adminAjax = '/wp-admin/admin-ajax.php';
        }

        // 🔥 Debug-Log
        console.log( '🔍 WPAIC Config:', {
            restUrl: cfg.restUrl,
            nonce: cfg.nonce ? cfg.nonce.substring(0, 10) + '...' : '❌ MISSING',
            adminAjax: cfg.adminAjax,
            i18n: cfg.i18n,
            isLoggedIn: !!wp?.api?.settings?.root
        } );

        // ============================================================
        // DOM-REFERENZEN
        // ============================================================
        const runBtn = document.getElementById( 'wpaic-run' );
        const output = document.getElementById( 'wpaic-output' );
        const spinner = document.getElementById( 'wpaic-spinner' );
        const deleteStatus = document.getElementById( 'wpaic-delete-status' );
        const modelSelect = document.getElementById( 'wpaic_ollama_model' );
        const modelStatus = document.getElementById( 'wpaic-model-status' );

        // ============================================================
        // HELPER: escHtml
        // ============================================================
        function escHtml( s ) {
            const d = document.createElement( 'div' );
            d.textContent = String( s );
            return d.innerHTML;
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

            // 🔥 Uncertainties
            if ( r.uncertainties && Array.isArray( r.uncertainties ) && r.uncertainties.length > 0 ) {
                html += '<div style="margin:20px 0;padding:12px 16px;background:#fcf9f0;border-radius:4px;border-left:4px solid #dba617;">' +
                    '<h3 style="margin-top:0;">🤔 Unsicherheiten</h3><ul style="padding-left:20px;margin:5px 0;">';
                r.uncertainties.forEach( function( u ) {
                    html += '<li style="margin:4px 0;">' + escHtml( u ) + '</li>';
                } );
                html += '</ul></div>';
            }

            container.innerHTML = html || '<p>' + (cfg.i18n?.noResult || 'Keine Ergebnisse.') + '</p>';
        }

        // ============================================================
        // MODELLWECHSEL (AJAX)
        // 🔥 FIX: nonce → _wpnonce
        // ============================================================
        if ( modelSelect ) {
            modelSelect.addEventListener( 'change', function() {
                const model = this.value;
                const previousModel = this.dataset.previousValue || this.value;
                
                if ( !model ) return;
                
                if ( modelStatus ) {
                    modelStatus.innerHTML = '⏳ Modell wird aktiviert...';
                    modelStatus.style.color = '#dba617';
                }
                
                fetch( cfg.adminAjax, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'wpaic_set_model',
                        _wpnonce: cfg.nonce,  // 🔥 FIX: nonce → _wpnonce
                        model: model
                    })
                })
                .then( function( response ) {
                    return response.json();
                } )
                .then( function( data ) {
                    if ( data.success ) {
                        if ( modelStatus ) {
                            modelStatus.innerHTML = '✅ Modell gewechselt zu: <strong>' + data.data.model + '</strong>';
                            modelStatus.style.color = '#46b450';
                        }
                        modelSelect.dataset.previousValue = model;
                        console.log( '✅ Modell gewechselt zu:', data.data.model );
                        
                        modelStatus.style.transition = 'opacity 0.3s';
                        modelStatus.style.opacity = '0.5';
                        setTimeout( function() {
                            modelStatus.style.opacity = '1';
                        }, 300 );
                    } else {
                        if ( modelStatus ) {
                            modelStatus.innerHTML = '❌ Fehler: ' + (data.data || 'Unbekannt');
                            modelStatus.style.color = '#d63638';
                        }
                        modelSelect.value = previousModel;
                    }
                } )
                .catch( function( err ) {
                    console.error( '❌ Modellwechsel fehlgeschlagen:', err );
                    if ( modelStatus ) {
                        modelStatus.innerHTML = '❌ Netzwerkfehler beim Modellwechsel';
                        modelStatus.style.color = '#d63638';
                    }
                    modelSelect.value = previousModel;
                } );
            } );
            
            modelSelect.dataset.previousValue = modelSelect.value;
        }

        // ============================================================
        // ALLE LÖSCHEN
        // 🔥 FIX: nonce → _wpnonce
        // ============================================================
        const deleteAllBtn = document.getElementById( 'wpaic-delete-all' );
        if ( deleteAllBtn ) {
            deleteAllBtn.addEventListener( 'click', function() {
                if ( !confirm( '⚠️ Alle Analysen wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden!' ) ) {
                    return;
                }
                
                this.disabled = true;
                if ( deleteStatus ) {
                    deleteStatus.innerHTML = '⏳ Lösche alle...';
                    deleteStatus.style.color = '#dba617';
                }
                
                fetch( cfg.adminAjax, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'wpaic_delete_all_analyses',
                        _wpnonce: cfg.nonce  // 🔥 FIX: nonce → _wpnonce
                    })
                } )
                .then( function( r ) {
                    return r.json();
                } )
                .then( function( data ) {
                    if ( data.success ) {
                        if ( deleteStatus ) {
                            deleteStatus.innerHTML = '✅ ' + data.data.deleted + ' Einträge gelöscht! Seite wird neu geladen...';
                            deleteStatus.style.color = '#46b450';
                        }
                        setTimeout( function() {
                            window.location.reload();
                        }, 1500 );
                    } else {
                        if ( deleteStatus ) {
                            deleteStatus.innerHTML = '❌ Fehler: ' + (data.data || 'Unbekannt');
                            deleteStatus.style.color = '#d63638';
                        }
                        deleteAllBtn.disabled = false;
                    }
                } )
                .catch( function( err ) {
                    console.error( '❌ Fehler:', err );
                    if ( deleteStatus ) {
                        deleteStatus.innerHTML = '❌ Netzwerkfehler: ' + err.message;
                        deleteStatus.style.color = '#d63638';
                    }
                    deleteAllBtn.disabled = false;
                } );
            } );
        }

        // ============================================================
        // ÄLTER ALS 30 TAGE LÖSCHEN
        // 🔥 FIX: nonce → _wpnonce
        // ============================================================
        const deleteOldBtn = document.getElementById( 'wpaic-delete-old' );
        if ( deleteOldBtn ) {
            deleteOldBtn.addEventListener( 'click', function() {
                if ( !confirm( '⚠️ Analysen älter als 30 Tage wirklich löschen?' ) ) {
                    return;
                }
                
                this.disabled = true;
                if ( deleteStatus ) {
                    deleteStatus.innerHTML = '⏳ Lösche alte Einträge...';
                    deleteStatus.style.color = '#dba617';
                }
                
                fetch( cfg.adminAjax, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'wpaic_delete_old_analyses',
                        _wpnonce: cfg.nonce,  // 🔥 FIX: nonce → _wpnonce
                        days: 30
                    })
                } )
                .then( function( r ) {
                    return r.json();
                } )
                .then( function( data ) {
                    if ( data.success ) {
                        if ( deleteStatus ) {
                            deleteStatus.innerHTML = '✅ ' + data.data.deleted + ' alte Einträge gelöscht! Seite wird neu geladen...';
                            deleteStatus.style.color = '#46b450';
                        }
                        setTimeout( function() {
                            window.location.reload();
                        }, 1500 );
                    } else {
                        if ( deleteStatus ) {
                            deleteStatus.innerHTML = '❌ Fehler: ' + (data.data || 'Unbekannt');
                            deleteStatus.style.color = '#d63638';
                        }
                        deleteOldBtn.disabled = false;
                    }
                } )
                .catch( function( err ) {
                    console.error( '❌ Fehler:', err );
                    if ( deleteStatus ) {
                        deleteStatus.innerHTML = '❌ Netzwerkfehler: ' + err.message;
                        deleteStatus.style.color = '#d63638';
                    }
                    deleteOldBtn.disabled = false;
                } );
            } );
        }

        // ============================================================
        // ANALYSE STARTEN
        // ============================================================
        if ( ! runBtn ) {
            console.warn( '⚠️ wpaic-run button not found' );
            return;
        }

        async function startAnalysis() {
            const url = cfg.restUrl + '/analyze';
            console.log( '🚀 Starting analysis at:', url );
            console.log( '📝 Using nonce:', cfg.nonce ? cfg.nonce.substring(0, 10) + '...' : '❌ MISSING' );

            spinner.classList.add( 'is-active' );
            runBtn.disabled = true;
            output.innerHTML = '<p>' + cfg.i18n.running + ' (bitte warten, kann etwa dauern.)</p>';

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
                                   '<a href="' + cfg.ollamaHost + '/api/tags" target="_blank">' +
                                   cfg.ollamaHost + '/api/tags</a>';
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

        runBtn.addEventListener( 'click', startAnalysis );

        // ============================================================
        // HISTORIE: EINZELNES LÖSCHEN (via REST API)
        // ============================================================
        document.querySelectorAll( '.wpaic-delete-btn' ).forEach( function( btn ) {
            btn.addEventListener( 'click', function() {
                if ( !confirm( 'Analyse wirklich löschen?' ) ) {
                    return;
                }
                var id = this.getAttribute( 'data-id' );
                var row = document.getElementById( 'wpaic-history-row-' + id );
                
                var url = cfg.restUrl + '/history';
                
                fetch( url, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': cfg.nonce,
                    },
                    body: JSON.stringify({ id: parseInt( id ) }),
                } )
                .then( function( r ) { 
                    if ( !r.ok ) throw new Error( 'HTTP ' + r.status );
                    return r.json(); 
                } )
                .then( function( data ) {
                    if ( data.success ) {
                        if ( row ) row.remove();
                        window.location.reload();
                    } else {
                        alert( 'Löschen fehlgeschlagen: ' + (data.error || 'Unbekannt') );
                    }
                } )
                .catch( function( err ) { 
                    alert( 'Fehler beim Löschen: ' + err.message );
                } );
            } );
        } );

        // ============================================================
        // HISTORIE: MODAL ANZEIGEN (via REST API)
        // ============================================================
        const modal = document.getElementById( 'wpaic-detail-modal' );
        const modalContent = document.getElementById( 'wpaic-detail-content' );

        document.querySelectorAll( '.wpaic-view-btn' ).forEach( function( btn ) {
            btn.addEventListener( 'click', function() {
                var id = this.getAttribute( 'data-id' );
                
                modalContent.innerHTML = '<p>Lädt...</p>';
                modal.style.display = 'flex';
                
                var url = cfg.restUrl + '/history?limit=100';
                
                fetch( url, {
                    headers: { 'X-WP-Nonce': cfg.nonce },
                } )
                .then( function( r ) { 
                    if ( !r.ok ) throw new Error( 'HTTP ' + r.status );
                    return r.json(); 
                } )
                .then( function( data ) {
                    if ( data.entries ) {
                        var entry = data.entries.find( function( e ) { 
                            return e.id == id; 
                        } );
                        if ( entry && entry.result_json ) {
                            var r = entry.result_json;
                            var html = '<h3>Analyse #' + id + '</h3>';
                            html += '<p><strong>Datum:</strong> ' + escHtml( entry.run_at ) + '</p>';
                            html += '<p><strong>Modell:</strong> ' + escHtml( entry.model ) + '</p>';
                            html += '<p><strong>Dauer:</strong> ' + escHtml( String( entry.duration_ms ) ) + ' ms</p>';
                            if ( r.summary ) {
                                html += '<div class="notice notice-info"><p>' + escHtml( r.summary ) + '</p></div>';
                            }
                            if ( r.risks && r.risks.length ) {
                                html += '<h4>Risiken</h4><ul>';
                                r.risks.forEach( function( risk ) {
                                    html += '<li><strong>' + escHtml( risk.title || '' ) + '</strong>: ' + 
                                            escHtml( risk.detail || '' ) + ' <em>(' + escHtml( risk.level || '' ) + ')</em></li>';
                                } );
                                html += '</ul>';
                            }
                            if ( r.recommendations && r.recommendations.length ) {
                                html += '<h4>Empfehlungen</h4><ul>';
                                r.recommendations.forEach( function( rec ) {
                                    html += '<li>' + escHtml( rec ) + '</li>';
                                } );
                                html += '</ul>';
                            }
                            if ( r.uncertainties && r.uncertainties.length ) {
                                html += '<h4>🤔 Unsicherheiten</h4><ul>';
                                r.uncertainties.forEach( function( u ) {
                                    html += '<li>' + escHtml( u ) + '</li>';
                                } );
                                html += '</ul>';
                            }
                            modalContent.innerHTML = html;
                        } else {
                            modalContent.innerHTML = '<p>Keine Details verfügbar.</p>';
                        }
                    }
                } )
                .catch( function( err ) { 
                    modalContent.innerHTML = '<p>Fehler beim Laden: ' + err.message + '</p>';
                } );
            } );
        } );

        // ============================================================
        // MODAL SCHLIESSEN
        // ============================================================
        document.querySelectorAll( '.wpaic-modal-close' ).forEach( function( btn ) {
            btn.addEventListener( 'click', function() {
                modal.style.display = 'none';
            } );
        } );

        if ( modal ) {
            modal.addEventListener( 'click', function( e ) {
                if ( e.target === this ) {
                    this.style.display = 'none';
                }
            } );
        }

        // ============================================================
        // DEBUG-KONSOLE
        // ============================================================
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