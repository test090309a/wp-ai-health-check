<?php
declare( strict_types = 1 );

namespace WPAIC;

defined( 'ABSPATH' ) || exit;

class DashboardWidget {

    public function register_hooks(): void {
        add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
    }

    public function enqueue_styles(): void {
        wp_add_inline_style( 'dashboard', $this->get_styles() );
    }

    private function get_styles(): string {
        return '
            .wpaic-dashboard-widget { padding: 0 12px 12px; }
            .wpaic-dashboard-widget .wpaic-summary { 
                background: #f0f6fc; 
                padding: 10px 12px; 
                border-radius: 4px; 
                margin: 0 0 12px 0;
                border-left: 4px solid #2271b1;
            }
            .wpaic-dashboard-widget .wpaic-summary p { margin: 0; }
            .wpaic-dashboard-widget .wpaic-risk-high { color: #d63638; font-weight: 600; }
            .wpaic-dashboard-widget .wpaic-risk-medium { color: #dba617; font-weight: 600; }
            .wpaic-dashboard-widget .wpaic-risk-low { color: #007cba; font-weight: 600; }
            .wpaic-dashboard-widget .wpaic-risks-list { margin: 8px 0 0 0; padding-left: 20px; }
            .wpaic-dashboard-widget .wpaic-risks-list li { margin: 4px 0; }
            .wpaic-dashboard-widget .wpaic-timestamp { color: #666; font-size: 12px; }
            .wpaic-dashboard-widget .wpaic-spinner { float: none; display: none; margin-left: 6px; }
            .wpaic-dashboard-widget .wpaic-spinner.is-active { display: inline-block; }
            .wpaic-dashboard-widget .wpaic-result { margin-top: 10px; }
            .wpaic-dashboard-widget .wpaic-actions { margin-top: 12px; padding-top: 12px; border-top: 1px solid #eee; }
            .wpaic-dashboard-widget .wpaic-actions .button { margin-right: 6px; }
        ';
    }

    public function add_dashboard_widget(): void {
        wp_add_dashboard_widget(
            'wpaic_dashboard_widget',
            __( '🔍 KI Health Check', 'wp-ai-health-check' ),
            array( $this, 'render_widget' )
        );
    }

    public function render_widget(): void {
        $last_run = get_option( 'wpaic_last_run' );
        $model = get_option( 'wpaic_ollama_model', 'llama3.2' );
        
        // 🔥 FIX: result_json ist bereits ein Array!
        $last_db = AnalysisStore::latest();
        $last = null;
        if ( $last_db && ! empty( $last_db['result_json'] ) ) {
            $last = $last_db['result_json'];
        } elseif ( $last_db && is_string( $last_db['result_json'] ) ) {
            $last = json_decode( $last_db['result_json'], true );
        }
        
        // Transient als Fallback (wenn DB leer)
        if ( empty( $last ) ) {
            $last_raw = get_transient( 'wpaic_last_result' );
            if ( ! empty( $last_raw ) ) {
                $last = $this->parse_result( $last_raw );
            }
        }

        // 🔥 REST-URL und Nonce für JavaScript bereitstellen
        $rest_url = esc_url_raw( rest_url( 'wpaic/v1' ) );
        $nonce = wp_create_nonce( 'wp_rest' );
        ?>
        <div class="wpaic-dashboard-widget">

            <?php if ( $last_run ) : ?>
                <div class="wpaic-timestamp">
                    <?php echo esc_html__( 'Letzte Analyse:', 'wp-ai-health-check' ); ?>
                    <strong><?php echo esc_html( $last_run ); ?></strong>
                    <?php echo esc_html__( 'mit', 'wp-ai-health-check' ); ?>
                    <strong><?php echo esc_html( $model ); ?></strong>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $last ) && is_array( $last ) && isset( $last['summary'] ) ) : ?>

                <?php if ( isset( $last['summary'] ) ) : ?>
                    <div class="wpaic-summary">
                        <p><?php echo wp_kses_post( $last['summary'] ); ?></p>
                    </div>
                <?php endif; ?>

                <?php
                $risks = $last['risks'] ?? array();
                $high = array_filter( $risks, function( $r ) { 
                    $level = strtolower( $r['level'] ?? '' );
                    return $level === 'high' || $level === 'critical'; 
                } );
                $medium = array_filter( $risks, function( $r ) { 
                    $level = strtolower( $r['level'] ?? '' );
                    return $level === 'medium'; 
                } );
                $low = array_filter( $risks, function( $r ) { 
                    $level = strtolower( $r['level'] ?? '' );
                    return $level === 'low'; 
                } );
                ?>

                <?php if ( ! empty( $high ) ) : ?>
                    <div style="margin: 10px 0 0 0; padding: 8px 12px; background: #fcf0f0; border-radius: 4px; border-left: 4px solid #d63638;">
                        <strong style="color: #d63638;">🔴 <?php echo count( $high ); ?> kritische Risiken</strong>
                        <ul class="wpaic-risks-list">
                            <?php foreach ( array_slice( $high, 0, 3 ) as $risk ) : ?>
                                <li><span class="wpaic-risk-high">●</span> <?php echo esc_html( $risk['title'] ?? '' ); ?></li>
                            <?php endforeach; ?>
                            <?php if ( count( $high ) > 3 ) : ?>
                                <li><em><?php echo sprintf( esc_html__( '… und %d weitere', 'wp-ai-health-check' ), count( $high ) - 3 ); ?></em></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $medium ) && empty( $high ) ) : ?>
                    <div style="margin: 10px 0 0 0; padding: 8px 12px; background: #fcf9f0; border-radius: 4px; border-left: 4px solid #dba617;">
                        <strong style="color: #dba617;">🟡 <?php echo count( $medium ); ?> mittlere Risiken</strong>
                        <ul class="wpaic-risks-list">
                            <?php foreach ( array_slice( $medium, 0, 3 ) as $risk ) : ?>
                                <li><span class="wpaic-risk-medium">●</span> <?php echo esc_html( $risk['title'] ?? '' ); ?></li>
                            <?php endforeach; ?>
                            <?php if ( count( $medium ) > 3 ) : ?>
                                <li><em><?php echo sprintf( esc_html__( '… und %d weitere', 'wp-ai-health-check' ), count( $medium ) - 3 ); ?></em></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ( empty( $high ) && empty( $medium ) && ! empty( $low ) ) : ?>
                    <div style="margin: 10px 0 0 0; padding: 8px 12px; background: #f0f6fc; border-radius: 4px; border-left: 4px solid #007cba;">
                        <strong style="color: #007cba;">🟢 <?php echo count( $low ); ?> kleine Optimierungshinweise</strong>
                        <ul class="wpaic-risks-list">
                            <?php foreach ( array_slice( $low, 0, 3 ) as $risk ) : ?>
                                <li><span class="wpaic-risk-low">●</span> <?php echo esc_html( $risk['title'] ?? '' ); ?></li>
                            <?php endforeach; ?>
                            <?php if ( count( $low ) > 3 ) : ?>
                                <li><em><?php echo sprintf( esc_html__( '… und %d weitere', 'wp-ai-health-check' ), count( $low ) - 3 ); ?></em></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="wpaic-actions">
                    <a href="<?php echo esc_url( admin_url( 'tools.php?page=wp-ai-health-check' ) ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Vollständige Analyse', 'wp-ai-health-check' ); ?>
                    </a>
                    <button type="button" class="button" id="wpaic-dashboard-run">
                        <?php esc_html_e( 'Neu analysieren', 'wp-ai-health-check' ); ?>
                    </button>
                    <span class="spinner wpaic-spinner" id="wpaic-dashboard-spinner"></span>
                </div>

                <div class="wpaic-result" id="wpaic-dashboard-result"></div>

                <script>
                (function() {
                    const btn = document.getElementById('wpaic-dashboard-run');
                    if (!btn) return;

                    const spinner = document.getElementById('wpaic-dashboard-spinner');
                    const result = document.getElementById('wpaic-dashboard-result');

                    // 🔥 FIX: cfg aus window.WPAIC_CFG mit Fallback
                    const cfg = window.WPAIC_CFG || {};
                    
                    // 🔥 Hardcoded Fallback für REST-URL und Nonce (falls cfg undefined)
                    const restUrl = cfg.restUrl || '<?php echo $rest_url; ?>';
                    const nonce = cfg.nonce || '<?php echo $nonce; ?>';

                    console.log('🔍 Dashboard Widget Config:', {
                        restUrl: restUrl,
                        nonce: nonce ? nonce.substring(0, 10) + '...' : '❌ MISSING'
                    });

                    btn.addEventListener('click', function() {
                        btn.disabled = true;
                        spinner.classList.add('is-active');
                        result.innerHTML = '<p style="color:#666;"><?php esc_html_e('Analyse läuft…', 'wp-ai-health-check'); ?></p>';

                        const url = restUrl + '/analyze';
                        console.log('🚀 Dashboard: Starting analysis at:', url);

                        fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': nonce
                            },
                            body: JSON.stringify({force: true})
                        })
                        .then(async res => {
                            console.log('📡 Dashboard Response Status:', res.status);
                            
                            // 🔥 Prüfe Content-Type
                            const contentType = res.headers.get('content-type');
                            if (!contentType || !contentType.includes('application/json')) {
                                const text = await res.text();
                                console.error('❌ Dashboard: Kein JSON erhalten:', text.substring(0, 200));
                                throw new Error('Server antwortete mit HTML (Status: ' + res.status + '). Bitte Permalinks speichern.');
                            }
                            
                            if (!res.ok) {
                                throw new Error('HTTP ' + res.status + ': ' + res.statusText);
                            }
                            
                            return res.json();
                        })
                        .then(data => {
                            console.log('📊 Dashboard Response:', data);
                            if (data.success) {
                                result.innerHTML = '<div class="notice notice-success" style="margin:0;"><p><?php esc_html_e('✅ Analyse abgeschlossen! Seite wird neu geladen…', 'wp-ai-health-check'); ?></p></div>';
                                setTimeout(function() { location.reload(); }, 1500);
                            } else {
                                result.innerHTML = '<div class="notice notice-error" style="margin:0;"><p><?php esc_html_e("Fehler:", "wp-ai-health-check"); ?> ' + (data.error || '<?php esc_html_e("Unbekannt", "wp-ai-health-check"); ?>') + '</p></div>';
                            }
                        })
                        .catch(function(err) {
                            console.error('❌ Dashboard Error:', err);
                            result.innerHTML = '<div class="notice notice-error" style="margin:0;"><p><?php esc_html_e("Fehler:", "wp-ai-health-check"); ?> ' + err.message + '<br><small>Siehe Browser-Konsole (F12) für Details.</small></p></div>';
                        })
                        .finally(function() {
                            btn.disabled = false;
                            spinner.classList.remove('is-active');
                        });
                    });
                })();
                </script>

            <?php else : ?>

                <div style="padding: 20px 0; text-align: center; color: #666;">
                    <p style="font-size: 24px; margin: 0;">📊</p>
                    <p><?php esc_html_e( 'Noch keine Analyse durchgeführt.', 'wp-ai-health-check' ); ?></p>
                </div>
                <div class="wpaic-actions">
                    <a href="<?php echo esc_url( admin_url( 'tools.php?page=wp-ai-health-check' ) ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Erste Analyse starten', 'wp-ai-health-check' ); ?>
                    </a>
                </div>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Hilfstfunktion: Versucht, das letzte Ergebnis zu parsen.
     */
    private function parse_result( mixed $raw ): ?array {
        if ( is_array( $raw ) ) {
            return $raw;
        }
        if ( ! is_string( $raw ) ) {
            return null;
        }

        $clean = preg_replace( '/^```json\s*/', '', $raw );
        $clean = preg_replace( '/\s*```$/', '', $clean );
        $clean = trim( $clean );

        $decoded = json_decode( $clean, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }

        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }

        return null;
    }
}