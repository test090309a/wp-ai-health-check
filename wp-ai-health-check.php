<?php
/**
 * Plugin Name:       WP AI Health Check
 * Plugin URI:        https://example.com/wp-ai-health-check
 * GitHub Plugin URI: https://github.com/test090309a/wp-ai-health-check
 * Description:       Prüft den WordPress-Zustand und analysiert ihn über eine lokale Ollama-HTTP-API.
 * Version:           0.2.0
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

define( 'WPAIC_VERSION', '0.2.0' );
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
    $host = get_option('wpaic_ollama_host', 'http://localhost:11434');
    $model = get_option('wpaic_ollama_model', 'llama3.2');
    $last = get_transient('wpaic_last_result');
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('KI-Zustandsanalyse', 'wp-ai-health-check'); ?></h1>
        
        <div class="notice notice-info">
            <p>⚠️ AdminPage-Klasse nicht gefunden – Fallback-Modus aktiv.</p>
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
            <?php submit_button('Speichern'); ?>
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
        
        fetch(restUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            body: JSON.stringify({force: true})
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                output.innerHTML = '<pre>' + JSON.stringify(data.result, null, 2) + '</pre>';
            } else {
                output.innerHTML = '<div class="notice notice-error"><p>Fehler: ' + (data.error || 'Unbekannt') + '</p></div>';
            }
        })
        .catch(err => {
            output.innerHTML = '<div class="notice notice-error"><p>Fehler: ' + err.message + '</p></div>';
        })
        .finally(() => {
            btn.disabled = false;
            spinner.classList.remove('is-active');
        });
    });
    </script>
    <?php
}

// Komponenten booten
add_action( 'plugins_loaded', function (): void {
    if (class_exists('WPAIC\\RestController')) {
        ( new \WPAIC\RestController() )->register_hooks();
    }
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

// Assets
add_action( 'admin_enqueue_scripts', function ( string $hook ): void {
    if ( 'tools_page_wp-ai-health-check' !== $hook && 'admin_page_wp-ai-health-check' !== $hook ) {
        return;
    }
    wp_enqueue_style( 'wpaic-admin', WPAIC_URL . 'assets/admin.css', array(), WPAIC_VERSION );
    wp_enqueue_script( 'wpaic-admin', WPAIC_URL . 'assets/admin.js', array( 'wp-api', 'wp-i18n' ), WPAIC_VERSION, true );
    wp_localize_script( 'wpaic-admin', 'WPAIC_CFG', array(
        'restUrl' => esc_url_raw( rest_url( 'wpaic/v1' ) ),
        'nonce'   => wp_create_nonce( 'wp_rest' ),
        'i18n'    => array(
            'running'  => __( 'Analyse läuft…', 'wp-ai-health-check' ),
            'failed'   => __( 'Analyse fehlgeschlagen.', 'wp-ai-health-check' ),
            'noResult' => __( 'Keine Ergebnisse vorhanden.', 'wp-ai-health-check' ),
        ),
    ) );
} );

// Cron cleanup
register_deactivation_hook( __FILE__, function (): void {
    if (class_exists('WPAIC\\Cron')) {
        \WPAIC\Cron::clear_scheduled();
    }
} );