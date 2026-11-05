<?php

namespace AnotherEcommerce\Admin;

use AnotherEcommerce\Delivery\Mailer;
use AnotherEcommerce\Orders\OrderRepository;
use AnotherEcommerce\Payment\WebpayGateway;

/**
 * Administración: ajustes por pestañas (Webpay, entrega, SMTP, correo,
 * términos) y lista de ventas. El guardado NO usa la Settings API de
 * WordPress: cada pestaña es su propio formulario/nonce/handler que solo
 * toca sus propios campos y conserva el resto de la configuración intacta
 * (así enviar una pestaña nunca borra los datos de otra).
 */
final class SettingsPage
{
    public const OPTION = 'aep_settings';
    public const CAPABILITY = 'manage_options';
    public const MENU_SLUG = 'aep-settings';

    private const ORDERS_SLUG = 'aep-orders';
    private const PER_PAGE = 25;

    private const TABS = [
        'payments' => 'Medios de pago',
        'delivery' => 'Entrega',
        'smtp' => 'SMTP',
        'email' => 'Correo',
        'terms' => 'Términos',
    ];

    private string $settingsHook = '';

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
            'link_hours' => 72,
            'max_downloads' => 5,
            'smtp_enabled' => '',
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_from_email' => '',
            'smtp_from_name' => '',
            'email_subject' => 'Tu compra: {producto}',
            'email_body' => '<p>Hola {nombre}:</p><p>¡Gracias por tu compra! (orden {orden}).</p><p>Descarga tu copia de &#8220;{producto}&#8221; con el botón de abajo. El enlace es personal, vence en {horas} horas y admite hasta {descargas} descargas.</p><p>Si tienes cualquier problema, responde este correo.</p>',
            'terms_text' => '<p>Al comprar aceptas la entrega inmediata del contenido digital y renuncias expresamente al derecho de retracto (art. 3 bis letra b, Ley 19.496). Esta compra no admite reembolsos.</p>',
            'stripe_enabled' => '',
            'stripe_secret_key' => '',
            'stripe_webhook_secret' => '',
            'ls_enabled' => '',
            'ls_api_key' => '',
            'ls_store_id' => '',
            'ls_variant_id' => '',
            'ls_webhook_secret' => '',
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

    /**
     * El menú de nivel superior lo crea ProductsPage (Productos es la
     * primera pantalla); aquí solo agregamos Ventas y Ajustes como
     * submenús de ese mismo padre, en ese orden.
     */
    public function registerMenu(): void
    {
        add_submenu_page(ProductsPage::SLUG, 'Ventas', 'Ventas', self::CAPABILITY, self::ORDERS_SLUG, [$this, 'renderOrdersPage']);

        $hook = add_submenu_page(ProductsPage::SLUG, 'Ajustes', 'Ajustes', self::CAPABILITY, self::MENU_SLUG, [$this, 'renderSettingsPage']);
        $this->settingsHook = $hook !== false ? $hook : '';
    }

    /**
     * El editor de texto enriquecido (pestañas Correo y Términos) necesita
     * los scripts de TinyMCE, que no se cargan solos en una página de admin
     * fuera de la pantalla de edición de entradas.
     */
    public function enqueueEditorAssets(string $hook): void
    {
        if ($hook === $this->settingsHook) {
            wp_enqueue_editor();
        }
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

    private function currentTab(): string
    {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'payments'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return isset(self::TABS[$tab]) ? $tab : 'payments';
    }

    public function renderSettingsPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Sin permisos suficientes.');
        }

        $tab = $this->currentTab();
        $s = self::all();
        ?>
        <div class="wrap">
            <h1>Venta PDF — Ajustes</h1>
            <p>Cada producto tiene su propio precio y PDF: se administran en <a href="<?php echo esc_url(admin_url('admin.php?page=' . ProductsPage::SLUG)); ?>">Venta PDF → Productos</a>. Aquí van los ajustes que aplican a toda la tienda.</p>

            <?php if (isset($_GET['aep_saved'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p>Ajustes guardados.</p></div>
            <?php endif; ?>

            <?php if (isset($_GET['aep_test'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <?php if ($_GET['aep_test'] === 'ok') : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                    <div class="notice notice-success is-dismissible"><p>Correo de prueba enviado.</p></div>
                <?php else : ?>
                    <div class="notice notice-error is-dismissible"><p>No se pudo enviar el correo de prueba. Revisa los datos SMTP.</p></div>
                <?php endif; ?>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach (self::TABS as $key => $label) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => self::MENU_SLUG, 'tab' => $key], admin_url('admin.php'))); ?>"
                       class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <div style="margin-top:20px;max-width:720px;">
                <?php
                match ($tab) {
                    'delivery' => $this->renderDeliveryTab($s),
                    'smtp' => $this->renderSmtpTab($s),
                    'email' => $this->renderEmailTab($s),
                    'terms' => $this->renderTermsTab($s),
                    default => $this->renderPaymentsTab($s),
                };
                ?>
            </div>
        </div>
        <?php
    }

    private function tabFormStart(string $tab): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="aep_save_settings">
            <input type="hidden" name="aep_tab" value="<?php echo esc_attr($tab); ?>">
            <?php wp_nonce_field('aep_save_settings_' . $tab); ?>
            <table class="form-table" role="presentation">
        <?php
    }

    private function tabFormEnd(): void
    {
        ?>
            </table>
            <?php submit_button('Guardar ajustes'); ?>
        </form>
        <?php
    }

    /**
     * Editor enriquecido (negrita, cursiva, tamaño de fuente) para los
     * campos de texto que se muestran al cliente. El contenido se guarda
     * como HTML y se sanitiza con wp_kses_post(), igual que el contenido
     * de una entrada normal de WordPress.
     */
    private function richTextEditor(string $fieldName, string $content): void
    {
        wp_editor($content, 'aep_' . $fieldName . '_editor', [
            'textarea_name' => $fieldName,
            'textarea_rows' => 8,
            'media_buttons' => false,
            'quicktags' => true,
            'tinymce' => [
                'toolbar1' => 'bold,italic,underline,forecolor,fontsizeselect,bullist,numlist,alignleft,aligncenter,alignright,link,unlink,removeformat,undo,redo',
                'toolbar2' => '',
                'fontsize_formats' => '12px 14px 16px 18px 24px 32px',
            ],
        ]);
    }

    /**
     * Los tres medios de pago juntos en una sola pantalla: Webpay (CLP,
     * siempre disponible) y Stripe/Lemon Squeezy (USD, opcionales — solo se
     * ofrecen en productos con precio USD configurado en Venta PDF →
     * Productos).
     */
    private function renderPaymentsTab(array $s): void
    {
        $this->tabFormStart('payments');
        ?>
        <tr><th colspan="2"><h3 style="margin-bottom:0;">Webpay (CLP)</h3></th></tr>
        <tr>
            <th scope="row"><label for="aep-environment">Ambiente Webpay</label></th>
            <td>
                <select id="aep-environment" name="environment">
                    <option value="integration" <?php selected($s['environment'], 'integration'); ?>>Integración (pruebas)</option>
                    <option value="production" <?php selected($s['environment'], 'production'); ?>>Producción</option>
                </select>
                <p class="description">En integración se usan las credenciales públicas de prueba de Transbank y sus tarjetas de prueba: no se cobra dinero real.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-commerce-code">Código de comercio (producción)</label></th>
            <td><input type="text" id="aep-commerce-code" class="regular-text" name="commerce_code" value="<?php echo esc_attr($s['commerce_code']); ?>"></td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-api-key">API Key Secret (producción)</label></th>
            <td><input type="password" id="aep-api-key" class="regular-text" autocomplete="off" name="api_key" value="<?php echo esc_attr($s['api_key']); ?>"></td>
        </tr>

        <tr><th colspan="2"><h3 style="margin-bottom:0;">Stripe (USD)</h3></th></tr>
        <tr>
            <th scope="row"><label for="aep-stripe-enabled">Habilitar Stripe</label></th>
            <td><label><input type="checkbox" id="aep-stripe-enabled" name="stripe_enabled" value="1" <?php checked(!empty($s['stripe_enabled'])); ?>> Ofrecer Stripe como medio de pago en USD</label></td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-stripe-secret-key">Secret Key</label></th>
            <td>
                <input type="password" id="aep-stripe-secret-key" class="regular-text" autocomplete="off" name="stripe_secret_key" value="<?php echo esc_attr($s['stripe_secret_key']); ?>" placeholder="sk_test_… o sk_live_…">
                <p class="description">En modo de pruebas usa la Secret Key que empieza con <code>sk_test_</code> del <a href="https://dashboard.stripe.com/test/apikeys" target="_blank" rel="noopener">dashboard de Stripe</a>.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-stripe-webhook-secret">Webhook signing secret</label></th>
            <td>
                <input type="password" id="aep-stripe-webhook-secret" class="regular-text" autocomplete="off" name="stripe_webhook_secret" value="<?php echo esc_attr($s['stripe_webhook_secret']); ?>" placeholder="whsec_…">
                <p class="description">
                    Crea un endpoint de webhook en Stripe apuntando a:<br>
                    <code><?php echo esc_html(add_query_arg('aep-action', 'stripe-webhook', home_url('/'))); ?></code><br>
                    escuchando el evento <code>checkout.session.completed</code> (y opcionalmente <code>checkout.session.async_payment_succeeded</code>/<code>_failed</code>), y pega aquí el "Signing secret" que te muestra.
                </p>
            </td>
        </tr>

        <tr><th colspan="2"><h3 style="margin-bottom:0;">Lemon Squeezy (USD)</h3></th></tr>
        <tr>
            <th scope="row"><label for="aep-ls-enabled">Habilitar Lemon Squeezy</label></th>
            <td><label><input type="checkbox" id="aep-ls-enabled" name="ls_enabled" value="1" <?php checked(!empty($s['ls_enabled'])); ?>> Ofrecer Lemon Squeezy como medio de pago en USD</label></td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-ls-api-key">API Key</label></th>
            <td><input type="password" id="aep-ls-api-key" class="regular-text" autocomplete="off" name="ls_api_key" value="<?php echo esc_attr($s['ls_api_key']); ?>"></td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-ls-store-id">Store ID</label></th>
            <td><input type="text" id="aep-ls-store-id" class="regular-text" name="ls_store_id" value="<?php echo esc_attr($s['ls_store_id']); ?>"></td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-ls-variant-id">Variant ID</label></th>
            <td>
                <input type="text" id="aep-ls-variant-id" class="regular-text" name="ls_variant_id" value="<?php echo esc_attr($s['ls_variant_id']); ?>">
                <p class="description">Crea de antemano UN producto/variante cualquiera en tu tienda de Lemon Squeezy (ej. "Descarga digital"): el checkout se genera contra esa variante y el precio se sobreescribe con el precio USD de cada producto de este plugin.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-ls-webhook-secret">Webhook signing secret</label></th>
            <td>
                <input type="password" id="aep-ls-webhook-secret" class="regular-text" autocomplete="off" name="ls_webhook_secret" value="<?php echo esc_attr($s['ls_webhook_secret']); ?>">
                <p class="description">
                    Crea un webhook en Lemon Squeezy apuntando a:<br>
                    <code><?php echo esc_html(add_query_arg('aep-action', 'lemonsqueezy-webhook', home_url('/'))); ?></code><br>
                    escuchando el evento <code>order_created</code>, y pega aquí el "Signing secret" que definiste.
                </p>
            </td>
        </tr>
        <?php
        $this->tabFormEnd();
    }

    private function renderDeliveryTab(array $s): void
    {
        $this->tabFormStart('delivery');
        ?>
        <tr>
            <th scope="row"><label for="aep-link-hours">Vigencia del enlace (horas)</label></th>
            <td><input type="number" id="aep-link-hours" min="1" step="1" name="link_hours" value="<?php echo esc_attr((string) $s['link_hours']); ?>"></td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-max-downloads">Máximo de descargas por compra</label></th>
            <td><input type="number" id="aep-max-downloads" min="1" step="1" name="max_downloads" value="<?php echo esc_attr((string) $s['max_downloads']); ?>"></td>
        </tr>
        <?php
        $this->tabFormEnd();
    }

    private function renderSmtpTab(array $s): void
    {
        $this->tabFormStart('smtp');
        ?>
        <tr>
            <th scope="row"><label for="aep-smtp-enabled">Usar SMTP</label></th>
            <td>
                <label><input type="checkbox" id="aep-smtp-enabled" name="smtp_enabled" value="1" <?php checked(!empty($s['smtp_enabled'])); ?>> Enviar los correos por SMTP en vez de la función de correo de PHP</label>
                <p class="description">Recomendado: sin SMTP los correos de entrega suelen caer en spam o no llegar.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-smtp-host">Servidor SMTP</label></th>
            <td><input type="text" id="aep-smtp-host" class="regular-text" name="smtp_host" value="<?php echo esc_attr($s['smtp_host']); ?>" placeholder="smtp.tuproveedor.com"></td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-smtp-port">Puerto</label></th>
            <td><input type="number" id="aep-smtp-port" min="1" max="65535" step="1" name="smtp_port" value="<?php echo esc_attr((string) $s['smtp_port']); ?>"></td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-smtp-encryption">Cifrado</label></th>
            <td>
                <select id="aep-smtp-encryption" name="smtp_encryption">
                    <option value="tls" <?php selected($s['smtp_encryption'], 'tls'); ?>>TLS (puerto usual 587)</option>
                    <option value="ssl" <?php selected($s['smtp_encryption'], 'ssl'); ?>>SSL (puerto usual 465)</option>
                    <option value="none" <?php selected($s['smtp_encryption'], 'none'); ?>>Ninguno</option>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-smtp-username">Usuario</label></th>
            <td><input type="text" id="aep-smtp-username" class="regular-text" autocomplete="off" name="smtp_username" value="<?php echo esc_attr($s['smtp_username']); ?>"></td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-smtp-password">Contraseña</label></th>
            <td>
                <input type="password" id="aep-smtp-password" class="regular-text" autocomplete="off" name="smtp_password" value="" placeholder="<?php echo $s['smtp_password'] !== '' ? esc_attr('(sin cambios)') : ''; ?>">
                <p class="description">Déjala en blanco para conservar la contraseña guardada.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-smtp-from-email">Correo remitente</label></th>
            <td><input type="email" id="aep-smtp-from-email" class="regular-text" name="smtp_from_email" value="<?php echo esc_attr($s['smtp_from_email']); ?>" placeholder="<?php echo esc_attr(get_bloginfo('admin_email')); ?>"></td>
        </tr>
        <tr>
            <th scope="row"><label for="aep-smtp-from-name">Nombre remitente</label></th>
            <td><input type="text" id="aep-smtp-from-name" class="regular-text" name="smtp_from_name" value="<?php echo esc_attr($s['smtp_from_name']); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></td>
        </tr>
        <?php
        $this->tabFormEnd();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:24px;">
            <input type="hidden" name="action" value="aep_send_test_email">
            <?php wp_nonce_field('aep_send_test_email'); ?>
            <p>
                <label for="aep-test-email">Enviar un correo de prueba a:</label><br>
                <input type="email" id="aep-test-email" name="aep_test_email" class="regular-text" value="<?php echo esc_attr(get_option('admin_email')); ?>" required>
            </p>
            <?php submit_button('Enviar correo de prueba', 'secondary', 'submit', false); ?>
            <p class="description">Guarda los ajustes SMTP antes de probar: el botón usa la configuración ya guardada.</p>
        </form>
        <?php
    }

    private function renderEmailTab(array $s): void
    {
        $this->tabFormStart('email');
        ?>
        <tr>
            <th scope="row"><label for="aep-email-subject">Asunto del correo</label></th>
            <td><input type="text" id="aep-email-subject" class="large-text" name="email_subject" value="<?php echo esc_attr($s['email_subject']); ?>"></td>
        </tr>
        <tr>
            <th scope="row"><label for="aep_email_body_editor">Cuerpo del correo</label></th>
            <td>
                <?php $this->richTextEditor('email_body', $s['email_body']); ?>
                <p class="description">Variables disponibles: <code>{nombre}</code>, <code>{producto}</code>, <code>{enlace}</code>, <code>{horas}</code>, <code>{descargas}</code>, <code>{orden}</code>. El botón de descarga se agrega solo.</p>
            </td>
        </tr>
        <?php
        $this->tabFormEnd();
    }

    private function renderTermsTab(array $s): void
    {
        $this->tabFormStart('terms');
        ?>
        <tr>
            <th scope="row"><label for="aep_terms_text_editor">Términos de compra (sin reembolso)</label></th>
            <td>
                <?php $this->richTextEditor('terms_text', $s['terms_text']); ?>
                <p class="description">Se muestran junto al checkbox obligatorio en el formulario de compra de cada producto.</p>
            </td>
        </tr>
        <?php
        $this->tabFormEnd();
    }

    /**
     * Fusiona el POST de una pestaña sobre la configuración actual. Solo
     * toca las claves de esa pestaña; el resto de la configuración (de
     * otras pestañas) queda intacto. Lógica pura, sin tocar la base de
     * datos ni la petición, para poder probarla de forma aislada.
     */
    public static function sanitizeTab(string $tab, array $current, array $post): array
    {
        return match ($tab) {
            'payments' => array_merge($current, [
                'environment' => ($post['environment'] ?? '') === 'production' ? 'production' : 'integration',
                'commerce_code' => sanitize_text_field($post['commerce_code'] ?? ''),
                'api_key' => sanitize_text_field($post['api_key'] ?? ''),
                'stripe_enabled' => !empty($post['stripe_enabled']) ? '1' : '',
                'stripe_secret_key' => sanitize_text_field($post['stripe_secret_key'] ?? ''),
                'stripe_webhook_secret' => sanitize_text_field($post['stripe_webhook_secret'] ?? ''),
                'ls_enabled' => !empty($post['ls_enabled']) ? '1' : '',
                'ls_api_key' => sanitize_text_field($post['ls_api_key'] ?? ''),
                'ls_store_id' => sanitize_text_field($post['ls_store_id'] ?? ''),
                'ls_variant_id' => sanitize_text_field($post['ls_variant_id'] ?? ''),
                'ls_webhook_secret' => sanitize_text_field($post['ls_webhook_secret'] ?? ''),
            ]),
            'delivery' => array_merge($current, [
                'link_hours' => max(1, absint($post['link_hours'] ?? 72)),
                'max_downloads' => max(1, absint($post['max_downloads'] ?? 5)),
            ]),
            'smtp' => array_merge($current, [
                'smtp_enabled' => !empty($post['smtp_enabled']) ? '1' : '',
                'smtp_host' => sanitize_text_field($post['smtp_host'] ?? ''),
                'smtp_port' => max(1, absint($post['smtp_port'] ?? 587)),
                'smtp_encryption' => in_array($post['smtp_encryption'] ?? '', ['none', 'ssl', 'tls'], true)
                    ? sanitize_key($post['smtp_encryption'])
                    : 'tls',
                'smtp_username' => sanitize_text_field($post['smtp_username'] ?? ''),
                // Campo vacío = conservar la contraseña guardada (no la mostramos en el HTML).
                'smtp_password' => isset($post['smtp_password']) && $post['smtp_password'] !== ''
                    ? sanitize_text_field($post['smtp_password'])
                    : $current['smtp_password'],
                'smtp_from_email' => sanitize_email($post['smtp_from_email'] ?? ''),
                'smtp_from_name' => sanitize_text_field($post['smtp_from_name'] ?? ''),
            ]),
            'email' => array_merge($current, [
                'email_subject' => sanitize_text_field($post['email_subject'] ?? $current['email_subject']),
                // wp_kses_post (no sanitize_textarea_field): estos campos ahora
                // se editan con un editor enriquecido y guardan HTML seguro
                // (negrita, tamaño de fuente, etc.), igual que el contenido de una entrada.
                'email_body' => wp_kses_post($post['email_body'] ?? $current['email_body']),
            ]),
            'terms' => array_merge($current, [
                'terms_text' => wp_kses_post($post['terms_text'] ?? $current['terms_text']),
            ]),
            default => $current,
        };
    }

    public function handleSaveSettings(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Sin permisos suficientes.');
        }

        $tab = isset($_POST['aep_tab']) ? sanitize_key(wp_unslash($_POST['aep_tab'])) : 'payments';
        if (!isset(self::TABS[$tab])) {
            $tab = 'payments';
        }
        check_admin_referer('aep_save_settings_' . $tab);

        $updated = self::sanitizeTab($tab, self::all(), wp_unslash($_POST));
        update_option(self::OPTION, $updated);

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'tab' => $tab, 'aep_saved' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Configura PHPMailer con los datos SMTP guardados. Se engancha en
     * phpmailer_init, así que corre para CUALQUIER wp_mail() del sitio
     * (no solo los de este plugin) mientras esté activado en Ajustes → SMTP.
     */
    public static function configurePhpMailer(\PHPMailer\PHPMailer\PHPMailer $phpmailer): void
    {
        $s = self::all();

        if (empty($s['smtp_enabled']) || (string) $s['smtp_host'] === '') {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = $s['smtp_host'];
        $phpmailer->Port = (int) $s['smtp_port'];

        $phpmailer->SMTPAuth = (string) $s['smtp_username'] !== '';
        if ($phpmailer->SMTPAuth) {
            $phpmailer->Username = $s['smtp_username'];
            $phpmailer->Password = $s['smtp_password'];
        }

        if ($s['smtp_encryption'] === 'none') {
            $phpmailer->SMTPSecure = '';
            $phpmailer->SMTPAutoTLS = false;
        } else {
            $phpmailer->SMTPSecure = $s['smtp_encryption'];
        }

        if ((string) $s['smtp_from_email'] !== '' && is_email($s['smtp_from_email'])) {
            $phpmailer->setFrom(
                $s['smtp_from_email'],
                (string) $s['smtp_from_name'] !== '' ? $s['smtp_from_name'] : get_bloginfo('name')
            );
        }
    }

    public function handleSendTestEmail(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Sin permisos suficientes.');
        }
        check_admin_referer('aep_send_test_email');

        $to = isset($_POST['aep_test_email']) ? sanitize_email(wp_unslash($_POST['aep_test_email'])) : '';
        $redirect = add_query_arg(['page' => self::MENU_SLUG, 'tab' => 'smtp'], admin_url('admin.php'));

        if ($to === '' || !is_email($to)) {
            wp_safe_redirect(add_query_arg('aep_test', 'error', $redirect));
            exit;
        }

        $sent = wp_mail(
            $to,
            'Correo de prueba — Venta PDF',
            'Si recibiste este correo, la configuración SMTP de Venta PDF funciona correctamente.'
        );

        wp_safe_redirect(add_query_arg('aep_test', $sent ? 'ok' : 'error', $redirect));
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
        $gatewayLabels = [
            'webpay' => 'Webpay',
            'stripe' => 'Stripe',
            'lemonsqueezy' => 'Lemon Squeezy',
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
                        <th>Producto</th>
                        <th>Cliente</th>
                        <th>Correo</th>
                        <th>Medio de pago</th>
                        <th>Monto</th>
                        <th>Estado</th>
                        <th>Autorización</th>
                        <th>Referencia</th>
                        <th>Descargas</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($orders === []) : ?>
                    <tr><td colspan="12">Sin ventas todavía.</td></tr>
                <?php endif; ?>
                <?php foreach ($orders as $order) : ?>
                    <tr>
                        <td><code><?php echo esc_html($order->buy_order); ?></code></td>
                        <td><?php echo esc_html($order->created_at); ?></td>
                        <td><?php echo esc_html($order->product_name); ?></td>
                        <td><?php echo esc_html($order->customer_name); ?></td>
                        <td><?php echo esc_html($order->customer_email); ?></td>
                        <td><?php echo esc_html($gatewayLabels[$order->gateway] ?? $order->gateway); ?></td>
                        <td><?php echo esc_html($order->formattedAmount()); ?></td>
                        <td><?php echo esc_html($statusLabels[$order->status] ?? $order->status); ?></td>
                        <td>
                            <?php echo esc_html($order->authorization_code); ?>
                            <?php if ($order->card_last4 !== '') : ?>
                                <span class="description">(···<?php echo esc_html($order->card_last4); ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html($order->token_ws !== '' ? $order->token_ws : $order->gateway_reference); ?></code></td>
                        <td><?php echo esc_html((string) $order->downloads); ?></td>
                        <td>
                            <?php if ($order->status === 'paid') : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="aep_resend_email">
                                    <input type="hidden" name="order" value="<?php echo esc_attr((string) $order->id); ?>">
                                    <?php wp_nonce_field('aep_resend_' . $order->id); ?>
                                    <button type="submit" class="button button-small">Reenviar correo</button>
                                </form>
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

        $orderId = isset($_POST['order']) ? absint($_POST['order']) : 0;
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
