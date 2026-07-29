<?php
/**
 * Plugin Name:       Another Ecommerce Plugin
 * Description:       Venta de PDFs con botón "Comprar ahora" súper simplificado: pago con Webpay Plus (Transbank), sin carrito ni registro, entrega por correo con enlace de descarga temporal.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Zumito
 * License:           GPL-2.0-or-later
 * Text Domain:       another-ecommerce-plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AEP_VERSION', '1.1.0');
define('AEP_FILE', __FILE__);
define('AEP_DIR', plugin_dir_path(__FILE__));
define('AEP_URL', plugin_dir_url(__FILE__));

/**
 * Directorio protegido donde vive el PDF. No se enlaza nunca por URL directa:
 * se bloquea con .htaccess y el archivo solo se sirve vía DownloadController.
 */
function aep_protected_dir(): string
{
    $uploads = wp_upload_dir(null, false);

    return trailingslashit($uploads['basedir']) . 'aep-protected';
}

if (file_exists(AEP_DIR . 'vendor/autoload.php')) {
    require_once AEP_DIR . 'vendor/autoload.php';
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'AnotherEcommerce\\')) {
        return;
    }
    $relative = substr($class, strlen('AnotherEcommerce\\'));
    $file = AEP_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

add_filter('cron_schedules', static function (array $schedules): array {
    $schedules['aep_fifteen_minutes'] = [
        'interval' => 15 * MINUTE_IN_SECONDS,
        'display' => __('Cada 15 minutos (Venta PDF)', 'another-ecommerce-plugin'),
    ];

    return $schedules;
});

register_activation_hook(__FILE__, [\AnotherEcommerce\Install\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('aep_reconcile_orders');
});

add_action('plugins_loaded', static function (): void {
    \AnotherEcommerce\Plugin::boot();
});
