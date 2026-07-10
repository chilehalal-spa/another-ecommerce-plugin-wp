<?php

namespace AnotherEcommerce\Install;

use AnotherEcommerce\Admin\SettingsPage;

/**
 * Rutinas de activación: tabla de órdenes, directorio protegido y opciones.
 */
final class Activator
{
    public static function activate(): void
    {
        self::createOrdersTable();
        self::createProtectedDir();
        self::seedDefaultSettings();

        update_option('aep_db_version', AEP_VERSION);
    }

    private static function createOrdersTable(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'aep_orders';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            buy_order VARCHAR(26) NOT NULL,
            token_ws VARCHAR(128) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            customer_name VARCHAR(190) NOT NULL DEFAULT '',
            customer_email VARCHAR(190) NOT NULL DEFAULT '',
            amount INT UNSIGNED NOT NULL DEFAULT 0,
            authorization_code VARCHAR(20) NOT NULL DEFAULT '',
            payment_type VARCHAR(10) NOT NULL DEFAULT '',
            card_last4 VARCHAR(4) NOT NULL DEFAULT '',
            download_token VARCHAR(64) NOT NULL DEFAULT '',
            downloads SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            download_expires_at DATETIME NULL DEFAULT NULL,
            page_url VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            paid_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY buy_order (buy_order),
            KEY token_ws (token_ws(64)),
            KEY status (status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Crea wp-content/uploads/aep-protected con acceso web bloqueado.
     * En nginx el .htaccess no aplica: hay que negar /uploads/aep-protected
     * en la configuración del servidor (se avisa en la página de ajustes).
     */
    private static function createProtectedDir(): void
    {
        $dir = aep_protected_dir();

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents(
                $htaccess,
                "# Bloquea todo acceso directo; el PDF se sirve solo vía plugin.\n" .
                "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"
            );
        }

        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silencio es salud.\n");
        }
    }

    private static function seedDefaultSettings(): void
    {
        if (get_option(SettingsPage::OPTION) !== false) {
            return;
        }

        add_option(SettingsPage::OPTION, SettingsPage::defaults());
    }
}
