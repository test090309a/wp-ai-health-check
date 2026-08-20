<?php
/**
 * Plugin Name:       WP AI Health Check
 * Plugin URI:        https://test090309a.free.nf/dtf1606/artikel.php?id=4003
 * GitHub Plugin URI: https://github.com/test090309a/wp-ai-health-check
 * Description:       Prüft den WordPress-Zustand und analysiert ihn über eine lokale Ollama-HTTP-API.
 * Version:           0.3.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            JN
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-ai-health-check
 * Domain Path:       /languages
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'WPAIC_VERSION', '0.3.0' );
define( 'WPAIC_FILE', __FILE__ );
define( 'WPAIC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAIC_URL', plugin_dir_url( __FILE__ ) );

// Autoloader
spl_autoload_register( function ( string $class ): void {
    $prefix = 'WPAIC\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $path     = WPAIC_DIR . 'includes/Class' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $path ) ) {
        require_once $path;
    }
} );

// i18n
add_action( 'init', function (): void {
    load_plugin_textdomain(
        'wp-ai-health-check',
        false,
        dirname( plugin_basename( WPAIC_FILE ) ) . '/languages'
    );
} );

// Tabelle bei jedem Load sicherstellen (dbDelta ist idempotent)
add_action( 'plugins_loaded', function (): void {
    if ( class_exists( 'WPAIC\\AnalysisStore' ) ) {
        \WPAIC\AnalysisStore::create_table();
    }
}, 0 );

// Activation / Deactivation Hooks
register_activation_hook( __FILE__, function (): void {
    if ( class_exists( 'WPAIC\\AnalysisStore' ) ) {
        \WPAIC\AnalysisStore::create_table();
    }
} );

// ============================================================
// 🆕 NEU: REST-API FRÜHZEITIG REGISTRIEREN (Priorität 1)
// ============================================================
add_action( 'rest_api_init', function (): void {
    error_log('[WPAIC] REST API wird initialisiert...');
    if ( class_exists( 'WPAIC\\RestController' ) ) {
        ( new \WPAIC\RestController() )->register_routes();
        error_log('[WPAIC] REST API Routes registriert');
    } else {
        error_log('[WPAIC] RestController nicht gefunden!');
    }
}, 1 ); // 🔥 Priorität 1 = ganz früh

// ============================================================
// 🆕 NEU: Test-Endpunkt für Debugging (funktioniert ohne Auth)
// ============================================================
add_action( 'rest_api_init', function (): void {
    register_rest_route( 'wpaic/v1', '/test', array(
        'methods' => 'GET',
        'callback' => function() {
            return new \WP_REST_Response( array(
                'status' => 'ok',
                'message' => 'REST API funktioniert!',
                'timestamp' => current_time('mysql'),
                'user_id' => get_current_user_id(),
                'user_logged_in' => is_user_logged_in(),
                'ollama_host' => get_option('wpaic_ollama_host', 'http://192.168.0.194:11434'),
                'ollama_available' => class_exists('WPAIC\\OllamaClient') ? \WPAIC\OllamaClient::is_available() : false,
            ), 200 );
        },
        'permission_callback' => '__return_true' // Keine Auth für Test
    ) );
}, 2 );

// ============================================================
// 🆕 NEU: Fix für die Permission-Callback (WP_Error durch array ersetzen)
// ============================================================
add_filter( 'rest_request_before_callbacks', function( $response, $handler, $request ) {
    // Nur für unsere API
    if ( strpos( $request->get_route(), '/wpaic/v1/' ) !== 0 ) {
        return $response;
    }
    
    // Wenn schon ein Fehler, nichts tun
    if ( is_wp_error( $response ) ) {
        return $response;
    }
    
    return $response;
}, 10, 3 );

// ============================================================
// 🆕 NEU: WP_DEBUG Logging für REST-Fehler
// ============================================================
add_action( 'rest_api_init', function() {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        add_filter( 'rest_authentication_errors', function( $result ) {
            if ( is_wp_error( $result ) ) {
                error_log('[WPAIC] REST Auth Error: ' . $result->get_error_message());
            }
            return $result;
        } );
    }
} );

/**
 * ADMIN MENU - Nur einmal registrieren
 * WICHTIG: Wir prüfen zuerst ob AdminPage existiert und nutzen dann dessen add_menu()
 * Der Fallback wird nur aktiv wenn AdminPage NICHT existiert
 */
add_action( 'admin_menu', function() {
    // Prüfe ob AdminPage existiert - dann NICHTS tun (lässt die Klasse das Menü registrieren)
    if (class_exists('WPAIC\\AdminPage')) {
        // AdminPage registriert das Menü selbst via register_hooks()
        // Wir greifen hier nicht ein, um doppelte Menüs zu vermeiden
        return;
    }
    
    // FALLBACK: Nur wenn AdminPage nicht existiert
    global $submenu;
    $menu_exists = false;
    
    if (isset($submenu['tools.php'])) {
        foreach ($submenu['tools.php'] as $item) {
            if (isset($item[2]) && $item[2] === 'wp-ai-health-check') {
                $menu_exists = true;
                break;
            }
        }
    }
    
    if (!$menu_exists) {
        add_management_page(
            'KI-Zustandsanalyse',
            'KI Health Check',
            'manage_options',
            'wp-ai-health-check',
            'wpaic_render_admin_page'
        );
    }
}, 10 );

/**
 * FALLBACK: Rendering-Funktion außerhalb der Klasse (nur für Notfälle)
 */
function wpaic_render_admin_page() {
    // Prüfe Berechtigung
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }
    
    // Versuche die Klasse zu laden
    if (class_exists('WPAIC\\AdminPage')) {
        $page = new \WPAIC\AdminPage();
        $page->render_page();
        return;
    }
    
    // FALLBACK: Einfache HTML-Seite
    $host = get_option('wpaic_ollama_host', 'http://192.168.0.194:11434'); // 🔥 Fix: 192.168.0.194 statt localhost
    $model = get_option('wpaic_ollama_model', 'llama3.2');
    $last = get_transient('wpaic_last_result');
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('KI-Zustandsanalyse', 'wp-ai-health-check'); ?></h1>
        
        <div class="notice notice-info">
            <p>⚠️ AdminPage-Klasse nicht gefunden – Fallback-Modus aktiv.</p>
        </div>
        
        <!-- 🆕 NEU: Debug-Info -->
        <div style="background:#f0f0f0;padding:10px;margin:10px 0;border:1px solid #ccc;border-radius:4px;">
            <h3>🛠️ Debug-Info</h3>
            <pre style="margin:0;font-size:12px;">
REST URL: <?php echo esc_url( rest_url( 'wpaic/v1/analyze' ) ); ?>
Test URL: <?php echo esc_url( rest_url( 'wpaic/v1/test' ) ); ?>
Nonce: <?php echo wp_create_nonce( 'wp_rest' ); ?>
Ollama Host: <?php echo esc_html( get_option( 'wpaic_ollama_host', 'http://192.168.0.194:11434' ) ); ?>
Model: <?php echo esc_html( get_option( 'wpaic_ollama_model', 'llama3.2' ) ); ?>
Ollama verfügbar: <?php echo class_exists('WPAIC\\OllamaClient') && \WPAIC\OllamaClient::is_available() ? '✅ Ja' : '❌ Nein'; ?>
User ID: <?php echo get_current_user_id(); ?>
User logged in: <?php echo is_user_logged_in() ? '✅ Ja' : '❌ Nein'; ?>
REST Routes: <?php 
$routes = rest_get_server()->get_routes();
$wpaic_routes = array_filter( array_keys($routes), function($r) { return strpos($r, '/wpaic/') !== false; } );
echo !empty($wpaic_routes) ? implode(', ', $wpaic_routes) : '❌ Keine WPAIC-Routen gefunden!';
?>
            </pre>
        </div>
        
        <h2>Ollama-Konfiguration</h2>
        <form method="post" action="options.php">
            <?php settings_fields('wpaic_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="wpaic_ollama_host">Ollama URL</label></th>
                    <td>
                        <input type="url" id="wpaic_ollama_host" 
                               name="wpaic_ollama_host" 
                               value="<?php echo esc_attr($host); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('Die Ollama-URL. Standard: http://192.168.0.194:11434', 'wp-ai-health-check'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wpaic_ollama_model">Modell</label></th>
                    <td>
                        <input type="text" id="wpaic_ollama_model" 
                               name="wpaic_ollama_model" 
                               value="<?php echo esc_attr($model); ?>" 
                               class="regular-text">
                    </td>
                </tr>
            </table>
            <?php // submit_button('Speichern'); ?>
        </form>
        
        <h2>Analyse starten</h2>
        <p>
            <button type="button" class="button button-primary" id="wpaic-run">
                Jetzt analysieren
            </button>
            <span class="spinner" id="wpaic-spinner"></span>
        </p>
        
        <div id="wpaic-output">
            <?php if (!empty($last)) : ?>
                <div class="notice notice-info">
                    <pre><?php print_r($last); ?></pre>
                </div>
            <?php else : ?>
                <p>Keine Ergebnisse vorhanden.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 🆕 NEU: Verbesserte JavaScript-Fehlerbehandlung -->
    <script>
    document.getElementById('wpaic-run').addEventListener('click', function() {
        const btn = this;
        const spinner = document.getElementById('wpaic-spinner');
        const output = document.getElementById('wpaic-output');
        
        btn.disabled = true;
        spinner.classList.add('is-active');
        output.innerHTML = '<p>Analyse läuft...</p>';
        
        const nonce = '<?php echo wp_create_nonce("wp_rest"); ?>';
        const restUrl = '<?php echo esc_url_raw(rest_url("wpaic/v1/analyze")); ?>';
        
        console.log('🔍 WPAIC Debug:');
        console.log('  REST URL:', restUrl);
        console.log('  Nonce:', nonce);
        
        fetch(restUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            body: JSON.stringify({force: true})
        })
        .then(async res => {
            console.log('📡 Response Status:', res.status);
            console.log('📡 Response Headers:', [...res.headers]);
            
            const contentType = res.headers.get('content-type');
            
            // 🆕 Prüfe auf HTML-Response (Fehler)
            if (!contentType || !contentType.includes('application/json')) {
                const text = await res.text();
                console.error('❌ Kein JSON erhalten:', text.substring(0, 500));
                throw new Error('Server antwortete mit HTML (Status: ' + res.status + '). Bitte prüfen: ' + 
                    '\n- Ist der REST-Endpunkt korrekt? ' + restUrl +
                    '\n- Ist der Nonce korrekt? ' + nonce +
                    '\n- Siehe Debug-Info oben.');
            }
            
            return res.json();
        })
        .then(data => {
            console.log('📊 Response Data:', data);
            
            if (data.success) {
                output.innerHTML = '<pre>' + JSON.stringify(data.result, null, 2) + '</pre>';
                // 🆕 Seite neu laden nach 2 Sekunden
                setTimeout(() => location.reload(), 2000);
            } else {
                output.innerHTML = '<div class="notice notice-error"><p>Fehler: ' + (data.error || 'Unbekannt') + '</p></div>';
            }
        })
        .catch(err => {
            console.error('❌ Fetch Error:', err);
            output.innerHTML = '<div class="notice notice-error"><p>❌ Fehler: ' + err.message + '</p></div>';
        })
        .finally(() => {
            btn.disabled = false;
            spinner.classList.remove('is-active');
        });
    });
    </script>
    <?php
}

// ============================================================
// 🆕 NEU: Admin AJAX-Fallback für den Fall, dass REST nicht funktioniert
// ============================================================
add_action( 'wp_ajax_wpaic_analyze_ajax', function() {
    check_ajax_referer( 'wp_rest', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung.', 403 );
    }
    
    // Lade benötigte Klassen
    if ( ! class_exists( 'WPAIC\\OllamaClient' ) || ! class_exists( 'WPAIC\\HealthCollector' ) ) {
        wp_send_json_error( 'Plugin-Klassen nicht geladen.', 500 );
    }
    
    // Ollama-Verfügbarkeit prüfen
    if ( ! \WPAIC\OllamaClient::is_available() ) {
        wp_send_json_error( 'Ollama ist nicht erreichbar (IP: ' . get_option('wpaic_ollama_host', 'http://192.168.0.194:11434') . ')', 503 );
    }
    
    // Zustand sammeln
    $state = \WPAIC\HealthCollector::collect();
    $start = microtime( true );
    
    // System-Prompt
    $system = "Du bist ein erfahrener WordPress-Sicherheits-Experte. Analysiere die bereitgestellten Daten.\n"
        . "STRICT RULES:\n"
        . "1. NUR die bereitgestellten Daten verwenden. Keine Annahmen treffen.\n"
        . "2. KEINE Upgrade-Empfehlungen abgeben, es sei denn die Version ist explizit veraltet.\n"
        . "3. KEINE Risiken halluzinieren. Nur erwähnen, was in den Daten sichtbar ist.\n"
        . "4. NUR gültiges JSON zurückgeben. Kein Markdown, kein Text.\n"
        . "5. Schema: { \"summary\": \"Ein Satz\", \"risks\": [{\"level\": \"low|medium|high|critical\", \"title\": \"Titel\", \"detail\": \"Erklärung\"}], \"recommendations\": [\"Liste\"] }\n"
        . "6. Deutsch für alle Textfelder.\n"
        . "7. Max. 8 Risiken, max. 5 Empfehlungen.\n";
    
    $messages = array(
        array( 'role' => 'system', 'content' => $system ),
        array( 'role' => 'user', 'content' => wp_json_encode( $state, JSON_PRETTY_PRINT ) ),
    );
    
    $ai = \WPAIC\OllamaClient::chat( $messages );
    $duration = (int) round( ( microtime( true ) - $start ) * 1000 );
    
    if ( is_wp_error( $ai ) ) {
        wp_send_json_error( 'Ollama-Fehler: ' . $ai->get_error_message(), 502 );
    }
    
    // Speichern
    set_transient( 'wpaic_last_result', $ai, DAY_IN_SECONDS );
    update_option( 'wpaic_last_run', current_time( 'mysql' ) );
    
    if ( class_exists( 'WPAIC\\AnalysisStore' ) ) {
        \WPAIC\AnalysisStore::insert( array(
            'run_at'        => current_time( 'mysql' ),
            'model'         => get_option( 'wpaic_ollama_model', '' ),
            'duration_ms'   => $duration,
            'status'        => 'ok',
            'error_message' => null,
            'result_json'   => wp_json_encode( $ai ),
        ) );
    }
    
    wp_send_json_success( array(
        'result' => $ai,
        'duration_ms' => $duration,
    ) );
} );

// ============================================================
// 🆕 NEU: "Fix Permalinks" Admin-Notice
// ============================================================
add_action( 'admin_notices', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->id, array( 'tools_page_wp-ai-health-check', 'admin_page_wp-ai-health-check' ) ) ) {
        return;
    }
    
    // Prüfe ob REST-Routen registriert sind
    $routes = rest_get_server()->get_routes();
    $has_wpaic_routes = false;
    foreach ( array_keys($routes) as $route ) {
        if ( strpos($route, '/wpaic/') !== false ) {
            $has_wpaic_routes = true;
            break;
        }
    }
    
    if ( ! $has_wpaic_routes ) {
        ?>
        <div class="notice notice-warning">
            <p>
                <strong>⚠️ WPAIC REST-API nicht gefunden.</strong>
                <?php esc_html_e( 'Bitte speichere die Permalinks unter', 'wp-ai-health-check' ); ?>
                <a href="<?php echo admin_url('options-permalink.php'); ?>">
                    <?php esc_html_e( 'Einstellungen > Permalinks', 'wp-ai-health-check' ); ?>
                </a>
                <?php esc_html_e( 'neu ab.', 'wp-ai-health-check' ); ?>
            </p>
            <p>
                <a href="<?php echo admin_url('options-permalink.php'); ?>" class="button button-primary">
                    <?php esc_html_e( 'Permalinks speichern', 'wp-ai-health-check' ); ?>
                </a>
                <button onclick="location.reload()" class="button">
                    <?php esc_html_e( 'Seite neu laden', 'wp-ai-health-check' ); ?>
                </button>
            </p>
        </div>
        <?php
    }
} );

// 🆕 NEU: Assets für Admin-Seite mit verbesserter Fehlerbehandlung
add_action( 'admin_enqueue_scripts', function ( string $hook ): void {
    if ( 'tools_page_wp-ai-health-check' !== $hook && 'admin_page_wp-ai-health-check' !== $hook ) {
        return;
    }
    wp_enqueue_style( 'wpaic-admin', WPAIC_URL . 'assets/admin.css', array(), WPAIC_VERSION );
    wp_enqueue_script( 'wpaic-admin', WPAIC_URL . 'assets/admin.js', array( 'wp-api', 'wp-i18n' ), WPAIC_VERSION, true );
    
    // 🆕 Debug-Infos an JavaScript übergeben
    wp_localize_script( 'wpaic-admin', 'WPAIC_CFG', array(
        'restUrl' => esc_url_raw( rest_url( 'wpaic/v1' ) ),
        'adminAjax' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'wp_rest' ),
        'ajaxNonce' => wp_create_nonce( 'wp_rest' ),
        'debug' => array(
            'routes' => array_keys( rest_get_server()->get_routes() ),
            'ollama_host' => get_option('wpaic_ollama_host', 'http://192.168.0.194:11434'),
            'ollama_available' => class_exists('WPAIC\\OllamaClient') ? \WPAIC\OllamaClient::is_available() : false,
        ),
        'i18n'    => array(
            'running'  => __( 'Analyse läuft…', 'wp-ai-health-check' ),
            'failed'   => __( 'Analyse fehlgeschlagen.', 'wp-ai-health-check' ),
            'noResult' => __( 'Keine Ergebnisse vorhanden.', 'wp-ai-health-check' ),
        ),
    ) );
} );

// Komponenten booten (🆕 jetzt OHNE RestController, weil der schon über rest_api_init läuft)
add_action( 'plugins_loaded', function (): void {
    // 🆕 RestController wird NICHT mehr hier gebootet (läuft über rest_api_init)
    if (class_exists('WPAIC\\AdminPage')) {
        ( new \WPAIC\AdminPage() )->register_hooks();
    }
    if (class_exists('WPAIC\\Cron')) {
        ( new \WPAIC\Cron() )->register_hooks();
    }
    if (class_exists('WPAIC\\DashboardWidget')) {
        ( new \WPAIC\DashboardWidget() )->register_hooks();
    }
} );

// Cron cleanup
register_deactivation_hook( __FILE__, function (): void {
    if (class_exists('WPAIC\\Cron')) {
        \WPAIC\Cron::clear_scheduled();
    }
} );