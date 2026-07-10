<?php

namespace AnotherEcommerce\Http;

use AnotherEcommerce\Orders\OrderRepository;
use AnotherEcommerce\Payment\WebpayGateway;
use AnotherEcommerce\Products\ProductRepository;
use Throwable;

/**
 * Recibe el POST del formulario de compra, crea la orden y redirige a Webpay.
 * El precio SIEMPRE sale del producto guardado en el servidor, nunca del
 * formulario (el formulario solo manda el id del producto).
 */
final class CheckoutController
{
    public function __construct(
        private OrderRepository $orders,
        private WebpayGateway $gateway,
        private ProductRepository $products,
    ) {
    }

    public function handle(): void
    {
        $pageUrl = $this->pageUrl();
        $productId = isset($_POST['aep_product_id']) ? absint($_POST['aep_product_id']) : 0;

        if (!isset($_POST['aep_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['aep_nonce'])), 'aep_checkout')) {
            $this->backToForm($pageUrl, $productId, 'nonce');
        }

        // Honeypot: un humano nunca llena este campo oculto.
        if (!empty($_POST['aep_website'])) {
            $this->backToForm($pageUrl, $productId, 'bot');
        }

        $name = isset($_POST['aep_name']) ? sanitize_text_field(wp_unslash($_POST['aep_name'])) : '';
        $email = isset($_POST['aep_email']) ? sanitize_email(wp_unslash($_POST['aep_email'])) : '';
        $emailConfirm = isset($_POST['aep_email_confirm']) ? sanitize_email(wp_unslash($_POST['aep_email_confirm'])) : '';

        if ($name === '' || mb_strlen($name) > 190) {
            $this->backToForm($pageUrl, $productId, 'name');
        }

        if ($email === '' || !is_email($email)) {
            $this->backToForm($pageUrl, $productId, 'email');
        }

        if (strcasecmp($email, $emailConfirm) !== 0) {
            $this->backToForm($pageUrl, $productId, 'email_mismatch');
        }

        if (empty($_POST['aep_terms'])) {
            $this->backToForm($pageUrl, $productId, 'terms');
        }

        $product = $productId > 0 ? $this->products->findById($productId) : null;
        if ($product === null || !$product->active || $product->price <= 0 || $product->pdf_file === '') {
            $this->backToForm($pageUrl, $productId, 'unconfigured');
        }

        $order = $this->orders->create($name, $email, $product, $pageUrl);
        if ($order === null) {
            $this->backToForm($pageUrl, $productId, 'internal');
        }

        try {
            $result = $this->gateway->createTransaction(
                $order->buy_order,
                'S-' . $order->id,
                $product->price,
                add_query_arg('aep-action', 'webpay-return', home_url('/'))
            );
        } catch (Throwable $e) {
            $this->orders->update($order->id, ['status' => 'failed']);
            error_log('[AEP] Error creando transacción Webpay: ' . $e->getMessage());
            $this->backToForm($pageUrl, $productId, 'webpay');

            return;
        }

        $this->orders->update($order->id, ['token_ws' => $result['token']]);

        // Redirección por GET al formulario de pago de Webpay.
        wp_redirect($result['url'] . '?token_ws=' . rawurlencode($result['token']));
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
            'webpay' => 'No pudimos conectar con Webpay. Intenta nuevamente en unos minutos.',
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
}
