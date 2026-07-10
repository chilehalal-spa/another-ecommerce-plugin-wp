<?php

namespace AnotherEcommerce;

use AnotherEcommerce\Admin\SettingsPage;
use AnotherEcommerce\Delivery\DownloadController;
use AnotherEcommerce\Delivery\Mailer;
use AnotherEcommerce\Http\CheckoutController;
use AnotherEcommerce\Http\ReturnController;
use AnotherEcommerce\Orders\OrderRepository;
use AnotherEcommerce\Payment\WebpayGateway;

/**
 * Orquestador del plugin: registra hooks y arma las dependencias.
 *
 * Flujo de compra:
 *  1. Shortcode [aep_comprar] muestra el formulario (templates/form.php).
 *  2. El POST llega a admin-post.php (action aep_checkout) -> CheckoutController.
 *  3. Webpay redirige de vuelta a ?aep-action=webpay-return -> ReturnController.
 *  4. La descarga se sirve en ?aep-action=download -> DownloadController.
 */
final class Plugin
{
    private static ?self $instance = null;

    private OrderRepository $orders;
    private WebpayGateway $gateway;
    private Mailer $mailer;

    public static function boot(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->orders = new OrderRepository();
        $this->gateway = new WebpayGateway();
        $this->mailer = new Mailer();

        add_shortcode('aep_comprar', [$this, 'renderShortcode']);

        $checkout = new CheckoutController($this->orders, $this->gateway);
        add_action('admin_post_nopriv_aep_checkout', [$checkout, 'handle']);
        add_action('admin_post_aep_checkout', [$checkout, 'handle']);

        add_action('template_redirect', [$this, 'routeFrontActions']);

        if (is_admin()) {
            $settings = new SettingsPage($this->orders, $this->mailer);
            add_action('admin_menu', [$settings, 'registerMenu']);
            add_action('admin_init', [$settings, 'registerSettings']);
            add_action('admin_post_aep_upload_pdf', [$settings, 'handlePdfUpload']);
            add_action('admin_post_aep_resend_email', [$settings, 'handleResendEmail']);
            add_action('admin_notices', [$settings, 'maybeShowSdkNotice']);
        }
    }

    /**
     * Rutas de frontend sin rewrite rules: se resuelven por query arg.
     */
    public function routeFrontActions(): void
    {
        $action = isset($_GET['aep-action']) ? sanitize_key(wp_unslash($_GET['aep-action'])) : '';
        if ($action === '') {
            return;
        }

        if ($action === 'webpay-return') {
            (new ReturnController($this->orders, $this->gateway, $this->mailer))->handle();
            exit;
        }

        if ($action === 'download') {
            (new DownloadController($this->orders))->handle();
            exit;
        }
    }

    /**
     * El shortcode muestra el formulario de compra o, si venimos de vuelta
     * de Webpay (aep_status en la URL), la página de éxito/fracaso.
     */
    public function renderShortcode(): string
    {
        $status = isset($_GET['aep_status']) ? sanitize_key(wp_unslash($_GET['aep_status'])) : '';

        if ($status === 'success') {
            return $this->renderSuccess();
        }

        if ($status === 'failure') {
            $reason = isset($_GET['aep_reason']) ? sanitize_key(wp_unslash($_GET['aep_reason'])) : '';

            return self::renderTemplate('failure', [
                'message' => ReturnController::failureMessage($reason),
            ]);
        }

        $error = isset($_GET['aep_error']) ? sanitize_key(wp_unslash($_GET['aep_error'])) : '';

        return self::renderTemplate('form', [
            'settings' => SettingsPage::all(),
            'error_message' => CheckoutController::errorMessage($error),
            'page_url' => get_permalink() ?: home_url('/'),
        ]);
    }

    private function renderSuccess(): string
    {
        $orderId = isset($_GET['aep_order']) ? absint($_GET['aep_order']) : 0;
        $key = isset($_GET['aep_key']) ? sanitize_text_field(wp_unslash($_GET['aep_key'])) : '';

        $order = $orderId > 0 ? $this->orders->findById($orderId) : null;

        // La clave de la URL debe coincidir con el token de descarga de la orden
        // pagada; si no, alguien está adivinando URLs y solo ve el formulario.
        if ($order === null || $order->status !== 'paid' || !hash_equals($order->download_token, $key)) {
            return $this->renderFormFallback();
        }

        return self::renderTemplate('success', [
            'order' => $order,
            'settings' => SettingsPage::all(),
            'download_url' => DownloadController::downloadUrl($order),
        ]);
    }

    private function renderFormFallback(): string
    {
        return self::renderTemplate('form', [
            'settings' => SettingsPage::all(),
            'error_message' => '',
            'page_url' => get_permalink() ?: home_url('/'),
        ]);
    }

    public static function renderTemplate(string $template, array $vars = []): string
    {
        $file = AEP_DIR . 'templates/' . $template . '.php';
        if (!file_exists($file)) {
            return '';
        }

        extract($vars, EXTR_SKIP);
        ob_start();
        include $file;

        return (string) ob_get_clean();
    }
}
