<?php

namespace AnotherEcommerce\Http;

use AnotherEcommerce\Admin\SettingsPage;
use AnotherEcommerce\Orders\OrderRepository;
use AnotherEcommerce\Payment\WebpayGateway;
use Throwable;

/**
 * Recibe el POST del formulario de compra, crea la orden y redirige a Webpay.
 * El precio SIEMPRE sale de los ajustes del servidor, nunca del formulario.
 */
final class CheckoutController
{
    public function __construct(
        private OrderRepository $orders,
        private WebpayGateway $gateway,
    ) {
    }

    public function handle(): void
    {
        $pageUrl = $this->pageUrl();

        if (!isset($_POST['aep_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['aep_nonce'])), 'aep_checkout')) {
            $this->backToForm($pageUrl, 'nonce');
        }

        // Honeypot: un humano nunca llena este campo oculto.
        if (!empty($_POST['aep_website'])) {
            $this->backToForm($pageUrl, 'bot');
        }

        $name = isset($_POST['aep_name']) ? sanitize_text_field(wp_unslash($_POST['aep_name'])) : '';
        $email = isset($_POST['aep_email']) ? sanitize_email(wp_unslash($_POST['aep_email'])) : '';
        $emailConfirm = isset($_POST['aep_email_confirm']) ? sanitize_email(wp_unslash($_POST['aep_email_confirm'])) : '';

        if ($name === '' || mb_strlen($name) > 190) {
            $this->backToForm($pageUrl, 'name');
        }

        if ($email === '' || !is_email($email)) {
            $this->backToForm($pageUrl, 'email');
        }

        if (strcasecmp($email, $emailConfirm) !== 0) {
            $this->backToForm($pageUrl, 'email_mismatch');
        }

        if (empty($_POST['aep_terms'])) {
            $this->backToForm($pageUrl, 'terms');
        }

        $amount = (int) SettingsPage::get('price');
        if ($amount <= 0 || (string) SettingsPage::get('pdf_file') === '') {
            $this->backToForm($pageUrl, 'unconfigured');
        }

        $order = $this->orders->create($name, $email, $amount, $pageUrl);
        if ($order === null) {
            $this->backToForm($pageUrl, 'internal');
        }

        try {
            $result = $this->gateway->createTransaction(
                $order->buy_order,
                'S-' . $order->id,
                $amount,
                add_query_arg('aep-action', 'webpay-return', home_url('/'))
            );
        } catch (Throwable $e) {
            $this->orders->update($order->id, ['status' => 'failed']);
            error_log('[AEP] Error creando transacción Webpay: ' . $e->getMessage());
            $this->backToForm($pageUrl, 'webpay');

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
            'unconfigured' => 'La tienda no está configurada todavía. Intenta más tarde.',
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

    private function backToForm(string $pageUrl, string $error): void
    {
        wp_safe_redirect(add_query_arg('aep_error', $error, $pageUrl));
        exit;
    }
}
