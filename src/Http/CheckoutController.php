<?php

namespace AnotherEcommerce\Http;

use AnotherEcommerce\Admin\SettingsPage;
use AnotherEcommerce\Delivery\Mailer;
use AnotherEcommerce\Orders\Order;
use AnotherEcommerce\Orders\OrderRepository;
use AnotherEcommerce\Payment\GatewayRegistry;
use AnotherEcommerce\Products\ProductRepository;
use Throwable;

/**
 * Recibe el POST del formulario de compra, crea la orden y redirige al medio
 * de pago elegido (Webpay en CLP, o Stripe/Lemon Squeezy en USD). El precio
 * SIEMPRE sale del producto guardado en el servidor, nunca del formulario
 * (el formulario solo manda el id del producto y el gateway elegido).
 *
 * Los productos a $0 no pasan por ningún gateway (Webpay no procesa cobros
 * de $0, y los internacionales ni siquiera aparecen como opción si el
 * producto no tiene precio en USD): la orden se marca pagada y se entrega
 * de inmediato.
 */
final class CheckoutController
{
    public function __construct(
        private OrderRepository $orders,
        private GatewayRegistry $gateways,
        private ProductRepository $products,
        private Mailer $mailer,
    ) {
    }

    public function handle(): void
    {
        $pageUrl = $this->pageUrl();
        $productId = isset($_POST['aep_product_id']) ? absint($_POST['aep_product_id']) : 0;

        if (!isset($_POST['aep_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['aep_nonce'])), 'aep_checkout')) {
            $this->backToForm($pageUrl, $productId, 'nonce');

            return;
        }

        // Honeypot: un humano nunca llena este campo oculto.
        if (!empty($_POST['aep_website'])) {
            $this->backToForm($pageUrl, $productId, 'bot');

            return;
        }

        $name = isset($_POST['aep_name']) ? sanitize_text_field(wp_unslash($_POST['aep_name'])) : '';
        $email = isset($_POST['aep_email']) ? sanitize_email(wp_unslash($_POST['aep_email'])) : '';
        $emailConfirm = isset($_POST['aep_email_confirm']) ? sanitize_email(wp_unslash($_POST['aep_email_confirm'])) : '';

        if ($name === '' || mb_strlen($name) > 190) {
            $this->backToForm($pageUrl, $productId, 'name');

            return;
        }

        if ($email === '' || !is_email($email)) {
            $this->backToForm($pageUrl, $productId, 'email');

            return;
        }

        if (strcasecmp($email, $emailConfirm) !== 0) {
            $this->backToForm($pageUrl, $productId, 'email_mismatch');

            return;
        }

        if (empty($_POST['aep_terms'])) {
            $this->backToForm($pageUrl, $productId, 'terms');

            return;
        }

        $product = $productId > 0 ? $this->products->findById($productId) : null;
        if ($product === null || !$product->active || $product->pdf_file === '') {
            $this->backToForm($pageUrl, $productId, 'unconfigured');

            return;
        }

        $gatewayId = isset($_POST['aep_gateway']) ? sanitize_key(wp_unslash($_POST['aep_gateway'])) : 'webpay';
        $gateway = $this->gateways->get($gatewayId);

        // Defensa contra un POST manipulado: solo se acepta un gateway que
        // realmente esté disponible para este producto (configurado, y con
        // precio USD fijado si es uno de los internacionales).
        if ($gateway === null || !in_array($gateway, $this->gateways->availableFor($product), true)) {
            $this->backToForm($pageUrl, $productId, 'invalid_gateway');

            return;
        }

        $currency = GatewayRegistry::currencyFor($gatewayId);
        $amount = $currency === 'CLP' ? $product->price : $product->price_usd;

        $order = $this->orders->create($name, $email, $product, $pageUrl, $amount, $currency, $gatewayId);
        if ($order === null) {
            $this->backToForm($pageUrl, $productId, 'internal');

            return;
        }

        if ($amount === 0) {
            $this->deliverFree($order, $pageUrl);

            return;
        }

        try {
            $checkoutUrl = $gateway->createCheckout(
                $order,
                add_query_arg('aep-action', $gatewayId . '-return', home_url('/'))
            );
        } catch (Throwable $e) {
            $this->orders->update($order->id, ['status' => 'failed']);
            error_log('[AEP] Error creando transacción (' . $gatewayId . '): ' . $e->getMessage());
            $this->backToForm($pageUrl, $productId, 'gateway');

            return;
        }

        wp_redirect($checkoutUrl);
        exit;
    }

    public static function errorMessage(string $code): string
    {
        return match ($code) {
            'name' => 'Ingresa tu nombre.',
            'email' => 'El correo ingresado no es válido.',
            'email_mismatch' => 'Los correos no coinciden. Revísalos: el PDF se envía a ese correo.',
            'terms' => 'Debes aceptar los términos de compra para continuar.',
            'unconfigured' => 'Este producto no está disponible en este momento. Intenta más tarde.',
            'invalid_gateway' => 'Ese medio de pago no está disponible para este producto. Elige otro.',
            'gateway' => 'No pudimos conectar con el medio de pago. Intenta nuevamente en unos minutos.',
            'internal' => 'Ocurrió un error interno. Intenta nuevamente.',
            'nonce' => 'La sesión del formulario expiró. Vuelve a intentarlo.',
            'bot' => 'No pudimos validar el envío del formulario.',
            default => '',
        };
    }

    private function pageUrl(): string
    {
        $raw = isset($_POST['aep_page']) ? esc_url_raw(wp_unslash($_POST['aep_page'])) : '';
        $validated = wp_validate_redirect($raw, home_url('/'));

        return $validated !== '' ? $validated : home_url('/');
    }

    private function backToForm(string $pageUrl, int $productId, string $error): void
    {
        wp_safe_redirect(add_query_arg([
            'aep_error' => $error,
            'aep_product_id' => $productId,
        ], $pageUrl));
        exit;
    }

    /**
     * Producto a $0: no hay nada que cobrar en Webpay, así que la orden
     * queda pagada de inmediato y se entrega igual que un pago aprobado.
     */
    private function deliverFree(Order $order, string $pageUrl): void
    {
        $hours = max(1, (int) SettingsPage::get('link_hours'));
        $this->orders->markPaid($order->id, [
            'authorization_code' => '',
            'payment_type' => '',
            'card_last4' => '',
        ], $hours);

        $paid = $this->orders->findById($order->id);

        if ($paid !== null && !$this->mailer->sendDownloadEmail($paid)) {
            error_log('[AEP] No se pudo enviar el correo de entrega (orden gratuita ' . $paid->id . ').');
        }

        $url = add_query_arg([
            'aep_status' => 'success',
            'aep_order' => $order->id,
            'aep_key' => ($paid ?? $order)->download_token,
        ], $pageUrl);

        wp_safe_redirect($url);
        exit;
    }
}
