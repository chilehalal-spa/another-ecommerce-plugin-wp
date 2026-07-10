<?php

namespace AnotherEcommerce\Admin;

use AnotherEcommerce\Delivery\DownloadController;
use AnotherEcommerce\Delivery\Mailer;
use AnotherEcommerce\Orders\OrderRepository;
use AnotherEcommerce\Payment\WebpayGateway;

/**
 * Administración: ajustes (credenciales, precio, PDF, textos) y lista de ventas.
 */
final class SettingsPage
{
    public const OPTION = 'aep_settings';
    public const CAPABILITY = 'manage_options';

    private const MENU_SLUG = 'aep-settings';
    private const ORDERS_SLUG = 'aep-orders';
    private const PER_PAGE = 25;

    public function __construct(
        private OrderRepository $orders,
        private Mailer $mailer,
    ) {
    }

    public static function defaults(): array
    {
        return [
            'environment' => 'integration',
            'commerce_code' => '',
            'api_key' => '',
            'product_name' => 'Mi PDF',
            'price' => 0,
            'pdf_file' => '',
            'link_hours' => 72,
            'max_downloads' => 5,
            'email_subject' => 'Tu compra: {producto}',
            'email_body' => "Hola {nombre}:\n\n¡Gracias por tu compra! (orden {orden}).\n\nDescarga tu copia de \"{producto}\" con el botón de abajo. El enlace es personal, vence en {horas} horas y admite hasta {descargas} descargas.\n\nSi tienes cualquier problema, responde este correo.",
            'terms_text' => 'Al comprar aceptas la entrega inmediata del contenido digital y renuncias expresamente al derecho de retracto (art. 3 bis letra b, Ley 19.496). Esta compra no admite reembolsos.',
        ];
    }

    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);

        return wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    public function registerMenu(): void
    {
        add_menu_page(
            'Venta PDF',
            'Venta PDF',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderSettingsPage'],
            'dashicons-media-document',
            56
        );

        add_submenu_page(self::MENU_SLUG, 'Ajustes', 'Ajustes', self::CAPABILITY, self::MENU_SLUG, [$this, 'renderSettingsPage']);
        add_submenu_page(self::MENU_SLUG, 'Ventas', 'Ventas', self::CAPABILITY, self::ORDERS_SLUG, [$this, 'renderOrdersPage']);
    }

    public function registerSettings(): void
    {
        register_setting('aep_settings_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
        ]);
    }

    public function sanitize(mixed $input): array
    {
        $current = self::all();
        $input = is_array($input) ? $input : [];

        $environment = ($input['environment'] ?? '') === 'production' ? 'production' : 'integration';

        return [
            'environment' => $environment,
            'commerce_code' => sanitize_text_field($input['commerce_code'] ?? ''),
            'api_key' => sanitize_text_field($input['api_key'] ?? ''),
            'product_name' => sanitize_text_field($input['product_name'] ?? $current['product_name']),
            'price' => max(0, absint($input['price'] ?? 0)),
            // El PDF se sube por su propio formulario, no por esta pantalla.
            'pdf_file' => $current['pdf_file'],
            'link_hours' => max(1, absint($input['link_hours'] ?? 72)),
            'max_downloads' => max(1, absint($input['max_downloads'] ?? 5)),
            'email_subject' => sanitize_text_field($input['email_subject'] ?? $current['email_subject']),
            'email_body' => sanitize_textarea_field($input['email_body'] ?? $current['email_body']),
            'terms_text' => sanitize_textarea_field($input['terms_text'] ?? $current['terms_text']),
        ];
    }

    public function maybeShowSdkNotice(): void
    {
        if (WebpayGateway::sdkAvailable() || !current_user_can(self::CAPABILITY)) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>Venta PDF:</strong> '
            . 'falta el SDK de Transbank. Ejecuta <code>composer install</code> en el directorio del plugin '
            . '(<code>' . esc_html(AEP_DIR) . '</code>) para poder cobrar con Webpay.</p></div>';
    }

    public function renderSettingsPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Sin permisos suficientes.');
        }

        $s = self::all();
        $pdfPath = DownloadController::pdfPath();
        ?>
        <div class="wrap">
            <h1>Venta PDF — Ajustes</h1>

            <?php if (isset($_GET['aep_pdf'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <?php if ($_GET['aep_pdf'] === 'ok') : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                    <div class="notice notice-success is-dismissible"><p>PDF subido correctamente.</p></div>
                <?php else : ?>
                    <div class="notice notice-error is-dismissible"><p>No se pudo subir el PDF. Verifica que sea un archivo .pdf válido.</p></div>
                <?php endif; ?>
            <?php endif; ?>

            <p>Pon el shortcode <code>[aep_comprar]</code> en la página donde quieras el botón de compra.</p>

            <form method="post" action="options.php">
                <?php settings_fields('aep_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="aep-environment">Ambiente Webpay</label></th>
                        <td>
                            <select id="aep-environment" name="<?php echo esc_attr(self::OPTION); ?>[environment]">
                                <option value="integration" <?php selected($s['environment'], 'integration'); ?>>Integración (pruebas)</option>
                                <option value="production" <?php selected($s['environment'], 'production'); ?>>Producción</option>
                            </select>
                            <p class="description">En integración se usan las credenciales públicas de prueba de Transbank y sus tarjetas de prueba: no se cobra dinero real.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aep-commerce-code">Código de comercio (producción)</label></th>
                        <td><input type="text" id="aep-commerce-code" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[commerce_code]" value="<?php echo esc_attr($s['commerce_code']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aep-api-key">API Key Secret (producción)</label></th>
                        <td><input type="password" id="aep-api-key" class="regular-text" autocomplete="off" name="<?php echo esc_attr(self::OPTION); ?>[api_key]" value="<?php echo esc_attr($s['api_key']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aep-product-name">Nombre del producto</label></th>
                        <td><input type="text" id="aep-product-name" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[product_name]" value="<?php echo esc_attr($s['product_name']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aep-price">Precio (CLP)</label></th>
                        <td>
                            <input type="number" id="aep-price" min="0" step="1" name="<?php echo esc_attr(self::OPTION); ?>[price]" value="<?php echo esc_attr((string) $s['price']); ?>">
                            <p class="description">Pesos chilenos, sin decimales ni puntos.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aep-link-hours">Vigencia del enlace (horas)</label></th>
                        <td><input type="number" id="aep-link-hours" min="1" step="1" name="<?php echo esc_attr(self::OPTION); ?>[link_hours]" value="<?php echo esc_attr((string) $s['link_hours']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aep-max-downloads">Máximo de descargas por compra</label></th>
                        <td><input type="number" id="aep-max-downloads" min="1" step="1" name="<?php echo esc_attr(self::OPTION); ?>[max_downloads]" value="<?php echo esc_attr((string) $s['max_downloads']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aep-email-subject">Asunto del correo</label></th>
                        <td><input type="text" id="aep-email-subject" class="large-text" name="<?php echo esc_attr(self::OPTION); ?>[email_subject]" value="<?php echo esc_attr($s['email_subject']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aep-email-body">Cuerpo del correo</label></th>
                        <td>
                            <textarea id="aep-email-body" class="large-text" rows="8" name="<?php echo esc_attr(self::OPTION); ?>[email_body]"><?php echo esc_textarea($s['email_body']); ?></textarea>
                            <p class="description">Variables disponibles: <code>{nombre}</code>, <code>{producto}</code>, <code>{enlace}</code>, <code>{horas}</code>, <code>{descargas}</code>, <code>{orden}</code>. El botón de descarga se agrega solo.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aep-terms-text">Términos de compra (sin reembolso)</label></th>
                        <td>
                            <textarea id="aep-terms-text" class="large-text" rows="4" name="<?php echo esc_attr(self::OPTION); ?>[terms_text]"><?php echo esc_textarea($s['terms_text']); ?></textarea>
                            <p class="description">Se muestran junto al checkbox obligatorio del formulario de compra.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Guardar ajustes'); ?>
            </form>

            <hr>

            <h2>Archivo PDF</h2>
            <?php if ($pdfPath !== null) : ?>
                <p>Archivo actual: <code><?php echo esc_html(basename($pdfPath)); ?></code> (<?php echo esc_html(size_format((int) filesize($pdfPath))); ?>). Subir uno nuevo lo reemplaza.</p>
            <?php elseif ((string) $s['pdf_file'] !== '') : ?>
                <p><strong>Atención:</strong> el archivo configurado (<code><?php echo esc_html($s['pdf_file']); ?></code>) no se encuentra en el directorio protegido.</p>
            <?php else : ?>
                <p>Todavía no has subido el PDF que se vende. Sin PDF el formulario de compra rechaza los pedidos.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="aep_upload_pdf">
                <?php wp_nonce_field('aep_upload_pdf'); ?>
                <input type="file" name="aep_pdf" accept="application/pdf" required>
                <?php submit_button('Subir PDF', 'secondary', 'submit', false); ?>
            </form>

            <p class="description" style="margin-top:16px;">
                El PDF se guarda en <code><?php echo esc_html(aep_protected_dir()); ?></code>, bloqueado por
                <code>.htaccess</code>. Si tu servidor usa <strong>nginx</strong> (donde .htaccess no aplica), agrega a
                la configuración: <code>location ~* /uploads/aep-protected/ { deny all; }</code>
            </p>
        </div>
        <?php
    }

    public function handlePdfUpload(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Sin permisos suficientes.');
        }
        check_admin_referer('aep_upload_pdf');

        $redirect = admin_url('admin.php?page=' . self::MENU_SLUG);

        if (empty($_FILES['aep_pdf']) || !is_array($_FILES['aep_pdf'])) {
            wp_safe_redirect(add_query_arg('aep_pdf', 'error', $redirect));
            exit;
        }

        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Redirige la subida al directorio protegido en vez de uploads público.
        $toProtected = static function (array $dirs): array {
            $dirs['path'] = aep_protected_dir();
            $dirs['url'] = '';
            $dirs['subdir'] = '';

            return $dirs;
        };

        add_filter('upload_dir', $toProtected);
        $result = wp_handle_upload($_FILES['aep_pdf'], [
            'test_form' => false,
            'mimes' => ['pdf' => 'application/pdf'],
        ]);
        remove_filter('upload_dir', $toProtected);

        if (!isset($result['file']) || isset($result['error'])) {
            wp_safe_redirect(add_query_arg('aep_pdf', 'error', $redirect));
            exit;
        }

        // Borra el PDF anterior si tenía otro nombre.
        $previous = DownloadController::pdfPath();
        if ($previous !== null && $previous !== $result['file']) {
            wp_delete_file($previous);
        }

        $settings = self::all();
        $settings['pdf_file'] = basename($result['file']);
        update_option(self::OPTION, $settings);

        wp_safe_redirect(add_query_arg('aep_pdf', 'ok', $redirect));
        exit;
    }

    public function renderOrdersPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Sin permisos suficientes.');
        }

        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $total = $this->orders->countAll();
        $orders = $this->orders->latest(self::PER_PAGE, ($paged - 1) * self::PER_PAGE);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        $statusLabels = [
            'pending' => 'Pendiente',
            'processing' => 'Procesando',
            'paid' => 'Pagada',
            'failed' => 'Rechazada',
            'aborted' => 'Anulada',
        ];
        ?>
        <div class="wrap">
            <h1>Venta PDF — Ventas (<?php echo esc_html((string) $total); ?>)</h1>

            <?php if (isset($_GET['aep_resend'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <?php if ($_GET['aep_resend'] === 'ok') : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                    <div class="notice notice-success is-dismissible"><p>Correo reenviado (con un enlace nuevo).</p></div>
                <?php else : ?>
                    <div class="notice notice-error is-dismissible"><p>No se pudo reenviar el correo.</p></div>
                <?php endif; ?>
            <?php endif; ?>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Orden</th>
                        <th>Fecha (UTC)</th>
                        <th>Cliente</th>
                        <th>Correo</th>
                        <th>Monto</th>
                        <th>Estado</th>
                        <th>Autorización</th>
                        <th>Descargas</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($orders === []) : ?>
                    <tr><td colspan="9">Sin ventas todavía.</td></tr>
                <?php endif; ?>
                <?php foreach ($orders as $order) : ?>
                    <tr>
                        <td><code><?php echo esc_html($order->buy_order); ?></code></td>
                        <td><?php echo esc_html($order->created_at); ?></td>
                        <td><?php echo esc_html($order->customer_name); ?></td>
                        <td><?php echo esc_html($order->customer_email); ?></td>
                        <td>$<?php echo esc_html(number_format($order->amount, 0, ',', '.')); ?></td>
                        <td><?php echo esc_html($statusLabels[$order->status] ?? $order->status); ?></td>
                        <td>
                            <?php echo esc_html($order->authorization_code); ?>
                            <?php if ($order->card_last4 !== '') : ?>
                                <span class="description">(···<?php echo esc_html($order->card_last4); ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html((string) $order->downloads); ?></td>
                        <td>
                            <?php if ($order->status === 'paid') : ?>
                                <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(
                                    admin_url('admin-post.php?action=aep_resend_email&order=' . $order->id),
                                    'aep_resend_' . $order->id
                                )); ?>">Reenviar correo</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($pages > 1) : ?>
                <p>
                <?php for ($i = 1; $i <= $pages; $i++) : ?>
                    <?php if ($i === $paged) : ?>
                        <strong><?php echo esc_html((string) $i); ?></strong>
                    <?php else : ?>
                        <a href="<?php echo esc_url(add_query_arg(['page' => self::ORDERS_SLUG, 'paged' => $i], admin_url('admin.php'))); ?>"><?php echo esc_html((string) $i); ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Reenvía el correo de entrega generando un enlace nuevo (token y
     * expiración frescos), útil cuando al cliente se le venció el original.
     */
    public function handleResendEmail(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Sin permisos suficientes.');
        }

        $orderId = isset($_GET['order']) ? absint($_GET['order']) : 0;
        check_admin_referer('aep_resend_' . $orderId);

        $redirect = admin_url('admin.php?page=' . self::ORDERS_SLUG);
        $order = $this->orders->findById($orderId);

        if ($order === null || $order->status !== 'paid') {
            wp_safe_redirect(add_query_arg('aep_resend', 'error', $redirect));
            exit;
        }

        $hours = max(1, (int) self::get('link_hours'));
        $this->orders->update($order->id, [
            'download_token' => wp_generate_password(48, false),
            'download_expires_at' => gmdate('Y-m-d H:i:s', time() + $hours * HOUR_IN_SECONDS),
            'downloads' => 0,
        ]);

        $sent = $this->mailer->sendDownloadEmail($this->orders->findById($order->id));

        wp_safe_redirect(add_query_arg('aep_resend', $sent ? 'ok' : 'error', $redirect));
        exit;
    }
}
