<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Optionen löschen
delete_option( 'wpaic_ollama_host' );
delete_option( 'wpaic_ollama_model' );
delete_option( 'wpaic_cron_enabled' );
delete_option( 'wpaic_last_run' );

// Transient löschen
delete_transient( 'wpaic_last_result' );

// Cron aufräumen
$timestamp = wp_next_scheduled( 'wpaic_daily_check' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'wpaic_daily_check' );
}

// Falls noch andere Cron-Events existieren
wp_clear_scheduled_hook( 'wpaic_daily_check' );