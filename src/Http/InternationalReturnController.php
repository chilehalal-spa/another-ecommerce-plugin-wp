<?php

namespace AnotherEcommerce\Http;

use AnotherEcommerce\Admin\SettingsPage;
use AnotherEcommerce\Delivery\Mailer;
use AnotherEcommerce\Orders\Order;
use AnotherEcommerce\Orders\OrderRepository;
use AnotherEcommerce\Payment\StripeGateway;
use AnotherEcommerce\Plugin;
use Throwable;

/**
 * Retorno del navegador desde Stripe y Lemon Squeezy.
 *
 * Stripe permite confirmar de inmediato consultando la Checkout Session por
 * su id. Lemon Squeezy no manda nada verificable en la URL de retorno: su
 * único dato confiable llega por PaymentWebhookController, así que aquí solo
 * se revisa el estado que la orden ya tenga guardado.
 *
 * Mientras el pago sigue sin confirmarse se muestra una página de espera
 * (templates/processing.php) que se refresca sola: cada recarga vuelve a
 * pasar por este controlador hasta que el webhook (o, para Stripe, el propio
 * retorno) marque la orden como pagada.
 */
final class InternationalReturnController
{
    use RedirectsToProductPage;

    public function __construct(
        private OrderRepository $orders,
        private StripeGateway $stripe,
        private Mailer $mailer,
    ) {
    }

    public function handleStripe(): void
    {
        $order = $this->loadOrder();
        if ($order === null) {
            $this->redirectFailure(null, 'notfound');

            return;
        }

        if (!empty($_GET['aep_cancelled'])) {
            $this->abort($order);

            return;
        }

        $sessionId = isset($_GET['session_id']) ? sanitize_text_field(wp_unslash($_GET['session_id'])) : '';
        if ($sessionId === '' || $order->gateway_reference === '' || !hash_equals($order->gateway_reference, $sessionId)) {
            $this->redirectFailure($order, 'notfound');

            return;
        }

        if ($order->status === 'paid') {
            $this->redirectSuccess($order);

            return;
        }

        if (in_array($order->status, ['failed', 'aborted'], true)) {
            $this->redirectFailure($order, 'rejected');

            return;
        }

        // Mismo candado anti doble-procesamiento que usa Webpay: evita
        // pisarse con el webhook si llega justo en este momento.
        if (!$this->orders->claimForProcessing($order->id)) {
            $this->renderProcessing();

            return;
        }

        try {
            $status = $this->stripe->retrieveSession($sessionId);
        } catch (Throwable $e) {
            error_log('[AEP] Error consultando sesión Stripe en el retorno (orden ' . $order->id . '): ' . $e->getMessage());
            $this->orders->release($order->id);
            $this->renderProcessing();

            return;
        }

        if ($status['approved']) {
            $this->markPaidAndRedirect($order->id, [
                'authorization_code' => $status['payment_intent'],
                'payment_type' => 'stripe',
                'card_last4' => '',
            ]);

            return;
        }

        if ($status['status'] === 'expired') {
            $this->orders->update($order->id, ['status' => 'failed']);
            $this->redirectFailure($order, 'rejected');

            return;
        }

        // Sigue sin confirmarse (medio de pago asíncrono, ej. transferencia):
        // soltamos el candado y mostramos la espera; el webhook o el cron de
        // reconciliación la resuelven en breve.
        $this->orders->release($order->id);
        $this->renderProcessing();
    }

    public function handleLemonSqueezy(): void
    {
        $order = $this->loadOrder();
        if ($order === null) {
            $this->redirectFailure(null, 'notfound');

            return;
        }

        if ($order->status === 'paid') {
            $this->redirectSuccess($order);

            return;
        }

        if (in_array($order->status, ['failed', 'aborted'], true)) {
            $this->redirectFailure($order, 'rejected');

            return;
        }

        $this->renderProcessing();
    }

    private function loadOrder(): ?Order
    {
        $orderId = isset($_GET['aep_order']) ? absint($_GET['aep_order']) : 0;

        return $orderId > 0 ? $this->orders->findById($orderId) : null;
    }

    private function abort(Order $order): void
    {
        if (in_array($order->status, ['pending', 'processing'], true)) {
            $this->orders->update($order->id, ['status' => 'aborted']);
        }

        $this->redirectFailure($order, 'aborted');
    }

    /**
     * @param array{authorization_code: string, payment_type: string, card_last4: string} $result
     */
    private function markPaidAndRedirect(int $orderId, array $result): void
    {
        $hours = max(1, (int) SettingsPage::get('link_hours'));
        $this->orders->markPaid($orderId, $result, $hours);

        $paid = $this->orders->findById($orderId);
        if ($paid === null) {
            $this->redirectFailure(null, 'notfound');

            return;
        }

        if (!$this->mailer->sendDownloadEmail($paid)) {
            error_log('[AEP] No se pudo enviar el correo de entrega (orden ' . $paid->id . ').');
        }

        $this->redirectSuccess($paid);
    }

    private function renderProcessing(): void
    {
        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo Plugin::renderTemplate('processing'); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML propio del plugin, no incluye datos del usuario.
        exit;
    }
}
