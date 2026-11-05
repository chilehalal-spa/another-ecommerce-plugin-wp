<?php

namespace AnotherEcommerce\Http;

use AnotherEcommerce\Admin\SettingsPage;
use AnotherEcommerce\Delivery\Mailer;
use AnotherEcommerce\Orders\Order;
use AnotherEcommerce\Orders\OrderRepository;
use AnotherEcommerce\Payment\LemonSqueezyGateway;
use AnotherEcommerce\Payment\StripeGateway;

/**
 * Webhooks de Stripe y Lemon Squeezy: la fuente de verdad para confirmar un
 * pago cuando el navegador del cliente nunca vuelve (o, en el caso de Lemon
 * Squeezy, la única fuente de verdad — ver InternationalReturnController).
 *
 * Ambos responden siempre 200 salvo firma inválida, para que el proveedor no
 * siga reintentando algo que ya procesamos o que no nos corresponde.
 */
final class PaymentWebhookController
{
    public function __construct(
        private OrderRepository $orders,
        private StripeGateway $stripe,
        private LemonSqueezyGateway $lemonSqueezy,
        private Mailer $mailer,
    ) {
    }

    public function handleStripe(): void
    {
        $payload = (string) file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? (string) $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';

        $event = $this->stripe->verifyWebhookEvent($payload, $signature);
        if ($event === null) {
            $this->respond(400, 'invalid signature');

            return;
        }

        $type = (string) $event->type;
        if (!in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed'], true)) {
            $this->respond(200, 'ignored');

            return;
        }

        $session = $event->data->object;
        $sessionId = (string) ($session->id ?? '');
        $order = $this->orders->findByGatewayReference('stripe', $sessionId);

        if ($order === null) {
            error_log('[AEP] Webhook Stripe: orden no encontrada para session ' . $sessionId);
            $this->respond(200, 'order not found');

            return;
        }

        if ($type === 'checkout.session.async_payment_failed') {
            $this->markFailedIfPending($order);
            $this->respond(200, 'ok');

            return;
        }

        if (($session->payment_status ?? '') === 'paid') {
            $this->markPaidIfPending($order, [
                'authorization_code' => is_string($session->payment_intent ?? null) ? $session->payment_intent : '',
                'payment_type' => 'stripe',
                'card_last4' => '',
            ]);
        }

        $this->respond(200, 'ok');
    }

    public function handleLemonSqueezy(): void
    {
        $payload = (string) file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_X_SIGNATURE']) ? (string) $_SERVER['HTTP_X_SIGNATURE'] : '';

        if (!$this->lemonSqueezy->verifyWebhookSignature($payload, $signature)) {
            $this->respond(400, 'invalid signature');

            return;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            $this->respond(400, 'invalid payload');

            return;
        }

        $eventName = isset($_SERVER['HTTP_X_EVENT_NAME'])
            ? (string) $_SERVER['HTTP_X_EVENT_NAME']
            : (string) ($decoded['meta']['event_name'] ?? '');

        if ($eventName !== 'order_created') {
            $this->respond(200, 'ignored');

            return;
        }

        $parsed = $this->lemonSqueezy->parseOrderCreatedEvent($decoded);
        $order = $this->orders->findByGatewayReference('lemonsqueezy', $parsed['order_ref']);

        if ($order === null) {
            error_log('[AEP] Webhook Lemon Squeezy: orden no encontrada para referencia ' . $parsed['order_ref']);
            $this->respond(200, 'order not found');

            return;
        }

        if ($parsed['approved']) {
            $this->markPaidIfPending($order, [
                'authorization_code' => $parsed['authorization_code'],
                'payment_type' => 'lemonsqueezy',
                'card_last4' => '',
            ]);
        } else {
            $this->markFailedIfPending($order);
        }

        $this->respond(200, 'ok');
    }

    /**
     * @param array{authorization_code: string, payment_type: string, card_last4: string} $result
     */
    private function markPaidIfPending(Order $order, array $result): void
    {
        if (!in_array($order->status, ['pending', 'processing'], true)) {
            // Ya resuelta (por el retorno del navegador, o un reintento del
            // mismo webhook): no hay nada que hacer, evita un segundo correo.
            return;
        }

        if (!$this->orders->claimForProcessing($order->id)) {
            // Alguien más la está resolviendo justo ahora (el retorno del
            // navegador). La dejamos tranquila.
            return;
        }

        $hours = max(1, (int) SettingsPage::get('link_hours'));
        $this->orders->markPaid($order->id, $result, $hours);

        $paid = $this->orders->findById($order->id);
        if ($paid !== null && !$this->mailer->sendDownloadEmail($paid)) {
            error_log('[AEP] Webhook: no se pudo enviar el correo de entrega (orden ' . $paid->id . ').');
        }
    }

    private function markFailedIfPending(Order $order): void
    {
        if (in_array($order->status, ['pending', 'processing'], true)) {
            $this->orders->update($order->id, ['status' => 'failed']);
        }
    }

    private function respond(int $code, string $message): void
    {
        status_header($code);
        header('Content-Type: text/plain');
        echo esc_html($message);
        exit;
    }
}
