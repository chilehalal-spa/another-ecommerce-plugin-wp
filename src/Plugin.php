<?php

namespace AnotherEcommerce;

use AnotherEcommerce\Admin\ProductsPage;
use AnotherEcommerce\Admin\SettingsPage;
use AnotherEcommerce\Delivery\DownloadController;
use AnotherEcommerce\Delivery\Mailer;
use AnotherEcommerce\Http\CheckoutController;
use AnotherEcommerce\Http\ReturnController;
use AnotherEcommerce\Orders\OrderRepository;
use AnotherEcommerce\Payment\OrderReconciler;
use AnotherEcommerce\Payment\WebpayGateway;
use AnotherEcommerce\Products\ProductRepository;

/**
 * Orquestador del plugin: registra hooks y arma las dependencias.
 *
 * Flujo de compra:
 *  1. Shortcode [aep_comprar id="N"] muestra el formulario de ese producto
 *     (templates/form.php). Cada producto tiene su propio botón "Comprar
 *     ahora"; no hay carrito ni catálogo compartido.
 *  2. El POST llega a admin-post.php (action aep_checkout) -> CheckoutController.
 *  3. Webpay redirige de vuelta a ?aep-action=webpay-return -> ReturnController.
 *  4. La descarga se sirve en ?aep-action=download -> DownloadController.
 */
final class Plugin
{
    private static ?self $instance = null;

    private OrderRepository $orders;
    private ProductRepository $products;
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
        $this->products = new ProductRepository();
        $this->gateway = new WebpayGateway();
        $this->mailer = new Mailer();

        // Aplica cambios de esquema (columnas/tablas nuevas) en sitios donde
        // el plugin ya estaba activo, sin que haga falta desactivarlo y
        // reactivarlo. Barato: en el caso normal es solo un get_option().
        add_action('init', [$this, 'maybeUpgrade']);

        // Registrado siempre (no solo en admin): los correos de compra se
        // envían desde el frontend y deben pasar por el SMTP configurado.
        add_action('phpmailer_init', [SettingsPage::class, 'configurePhpMailer']);

        add_shortcode('aep_comprar', [$this, 'renderShortcode']);

        $checkout = new CheckoutController($this->orders, $this->gateway, $this->products, $this->mailer);
        add_action('admin_post_nopriv_aep_checkout', [$checkout, 'handle']);
        add_action('admin_post_aep_checkout', [$checkout, 'handle']);

        add_action('template_redirect', [$this, 'routeFrontActions']);

        // Reconcilia (vía WP-Cron) órdenes cuyo navegador nunca volvió del
        // retorno de Webpay, para no dejar cobros sin entrega ni registro.
        $reconciler = new OrderReconciler($this->orders, $this->gateway, $this->mailer);
        add_action('aep_reconcile_orders', [$reconciler, 'reconcile']);

        if (is_admin()) {
            // ProductsPage se registra primero: es dueña del menú de nivel
            // superior y del primer submenú (Productos). El orden en que se
            // registran los hooks admin_menu es el orden en que aparecen los
            // submenús, así que SettingsPage (Ventas, Ajustes) va después.
            $productsPage = new ProductsPage($this->products);
            add_action('admin_menu', [$productsPage, 'registerMenu']);
            add_action('admin_post_aep_save_product', [$productsPage, 'handleSave']);
            add_action('admin_post_aep_toggle_product', [$productsPage, 'handleToggle']);
            add_action('admin_post_aep_delete_product', [$productsPage, 'handleDelete']);

            $settings = new SettingsPage($this->orders, $this->mailer);
            add_action('admin_menu', [$settings, 'registerMenu']);
            add_action('admin_enqueue_scripts', [$settings, 'enqueueEditorAssets']);
            add_action('admin_post_aep_save_settings', [$settings, 'handleSaveSettings']);
            add_action('admin_post_aep_send_test_email', [$settings, 'handleSendTestEmail']);
            add_action('admin_post_aep_resend_email', [$settings, 'handleResendEmail']);
            add_action('admin_notices', [$settings, 'maybeShowSdkNotice']);
        }
    }

    /**
     * Vuelve a correr las rutinas de activación (crear/alterar tablas,
     * columnas nuevas, directorio protegido) si la versión del plugin
     * avanzó desde la última vez. Activator::activate() es idempotente:
     * dbDelta solo agrega lo que falta, y el resto de pasos se saltan si
     * ya existen. Así un sitio ya desplegado recibe los cambios de esquema
     * en el primer request tras actualizar los archivos del plugin.
     */
    public function maybeUpgrade(): void
    {
        if (get_option('aep_db_version') !== AEP_VERSION) {
            \AnotherEcommerce\Install\Activator::activate();
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
     * El shortcode muestra el formulario de compra de un producto, o si
     * venimos de vuelta de Webpay para ESE producto (aep_status en la URL),
     * la página de éxito/fracaso. Una misma página puede tener varios
     * shortcodes (uno por producto); el estado de retorno solo se muestra
     * en el que corresponde, los demás siguen mostrando su formulario.
     */
    public function renderShortcode($atts = []): string
    {
        $atts = shortcode_atts(['id' => 0], is_array($atts) ? $atts : [], 'aep_comprar');
        $productId = absint($atts['id']);

        if ($productId <= 0) {
            return current_user_can(SettingsPage::CAPABILITY)
                ? '<p style="color:#b00;">[aep_comprar] necesita el id del producto, por ejemplo: [aep_comprar id="1"]</p>'
                : '';
        }

        $product = $this->products->findById($productId);
        if ($product === null || !$product->active) {
            return current_user_can(SettingsPage::CAPABILITY)
                ? '<p style="color:#b00;">Producto no disponible (id ' . esc_html((string) $productId) . ').</p>'
                : '';
        }

        $status = isset($_GET['aep_status']) ? sanitize_key(wp_unslash($_GET['aep_status'])) : '';
        $orderId = isset($_GET['aep_order']) ? absint($_GET['aep_order']) : 0;
        $order = $orderId > 0 ? $this->orders->findById($orderId) : null;

        if ($status === 'success' && $order !== null && $order->product_id === $productId) {
            $key = isset($_GET['aep_key']) ? sanitize_text_field(wp_unslash($_GET['aep_key'])) : '';
            if ($order->status === 'paid' && hash_equals($order->download_token, $key)) {
                return self::renderTemplate('success', [
                    'order' => $order,
                    'settings' => SettingsPage::all(),
                    'download_url' => DownloadController::downloadUrl($order),
                ]);
            }
        }

        if ($status === 'failure' && ($orderId === 0 || ($order !== null && $order->product_id === $productId))) {
            $reason = isset($_GET['aep_reason']) ? sanitize_key(wp_unslash($_GET['aep_reason'])) : '';

            return self::renderTemplate('failure', [
                'message' => ReturnController::failureMessage($reason),
            ]);
        }

        // El error del checkout (nombre inválido, etc.) también viaja atado
        // al producto para no mostrarlo en el formulario equivocado.
        $requestedProductId = isset($_GET['aep_product_id']) ? absint($_GET['aep_product_id']) : 0;
        $error = isset($_GET['aep_error']) && $requestedProductId === $productId
            ? sanitize_key(wp_unslash($_GET['aep_error']))
            : '';

        return self::renderTemplate('form', [
            'product' => $product,
            'settings' => SettingsPage::all(),
            'error_message' => CheckoutController::errorMessage($error),
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
