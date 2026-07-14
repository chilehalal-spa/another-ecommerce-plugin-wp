<?php
/**
 * Limpieza al desinstalar el plugin (no al desactivarlo): borra opciones,
 * tablas de órdenes y productos, y el directorio protegido con los PDF.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('aep_settings');
delete_option('aep_db_version');

global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aep_orders"); // phpcs:ignore WordPress.DB
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aep_products"); // phpcs:ignore WordPress.DB

// El plugin no está cargado durante la desinstalación: la ruta del
// directorio protegido se calcula aquí mismo.
$uploads = wp_upload_dir(null, false);
$protected = trailingslashit($uploads['basedir']) . 'aep-protected';

if (is_dir($protected)) {
    foreach ((array) scandir($protected) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $protected . '/' . $entry;
        if (is_file($path)) {
            wp_delete_file($path);
        }
    }
    rmdir($protected); // phpcs:ignore WordPress.WP.AlternativeFunctions
}
