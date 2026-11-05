<?php

namespace AnotherEcommerce\Payment;

use AnotherEcommerce\Admin\SettingsPage;
use AnotherEcommerce\Delivery\Mailer;
use AnotherEcommerce\Orders\OrderRepository;
use Throwable;

/**
 * Reconcilia órdenes 'pending'/'processing' que se quedaron sin resolver
 * porque el navegador del cliente nunca volvió del retorno de Webpay/Stripe
 * (cerró la pestaña, se cortó la conexión, etc.). Sin esto, esas órdenes
 * quedan pendientes para siempre aunque el gateway sí haya autorizado el
 * cobro: el cliente pagó y no recibe el PDF ni queda registro de la venta.
 *
 * Lemon Squeezy no participa aquí: depende enteramente de su webhook (ver
 * OrderRepository::staleUnresolved).
 *
 * Corre por WP-Cron cada 15 minutos (ver aep_reconcile_orders).
 */
final class OrderReconciler
{
    private const MIN_AGE_MINUTES = 15;
    private const BATCH_LIMIT = 25;

    public function __construct(
        private OrderRepository $orders,
        private WebpayGateway $webpay,
        private StripeGateway $stripe,
        private Mailer $mailer,
    ) {
    }

    public function reconcile(): void
    {
        foreach ($this->orders->staleUnresolved(self::MIN_AGE_MINUTES, self::BATCH_LIMIT) as $order) {
            // Si el candado falla es porque un retorno real del cliente está
            // resolviendo esta misma orden ahora mismo: la dejamos tranquila.
            if (!$this->orders->claimForProcessing($order->id)) {
                continue;
            }

            if ($order->gateway === 'stripe') {
                $this->resolveStripe($order->id, $order->gateway_reference);
            } else {
                $this->resolveWebpay($order->id, $order->token_ws);
            }
        }
    }

    private function resolveWebpay(int $orderId, string $tokenWs): void
    {
        try {
            $status = $this->webpay->statusTransaction($tokenWs);
        } catch (Throwable $e) {
            // No sabemos qué pasó: soltamos el candado para reintentar en la
            // próxima pasada en vez de darla por perdida.
            $this->orders->release($orderId);
            error_log('[AEP] Reconciliación: error consultando estado Webpay (orden ' . $orderId . '): ' . $e->getMessage());

            return;
        }

        if ($status['approved']) {
            $this->markPaid($orderId, [
                'authorization_code' => $status['authorization_code'],
                'payment_type' => $status['payment_type'],
                'card_last4' => $status['card_last4'],
            ]);

            return;
        }

        if ($status['status'] !== 'INITIALIZED') {
            // Transbank ya resolvió la transacción (rechazada, anulada, etc.)
            // y nuestro servidor nunca llegó a confirmarla.
            $this->orders->update($orderId, [
                'status' => 'failed',
                'authorization_code' => $status['authorization_code'],
                'payment_type' => $status['payment_type'],
            ]);

            return;
        }

        // Sigue "INITIALIZED": nadie la confirmó todavía. Hacemos ahora el
        // commit que el navegador del cliente no alcanzó a disparar.
        try {
            $result = $this->webpay->commitTransaction($tokenWs);
        } catch (Throwable $e) {
            $this->orders->update($orderId, ['status' => 'failed']);
            error_log('[AEP] Reconciliación: error en commit tardío (orden ' . $orderId . '): ' . $e->getMessage());

            return;
        }

        if (!$result['approved']) {
            $this->orders->update($orderId, [
                'status' => 'failed',
                'authorization_code' => $result['authorization_code'],
                'payment_type' => $result['payment_type'],
            ]);

            return;
        }

        $this->markPaid($orderId, [
            'authorization_code' => $result['authorization_code'],
            'payment_type' => $result['payment_type'],
            'card_last4' => $result['card_last4'],
        ]);
    }

    private function resolveStripe(int $orderId, string $sessionId): void
    {
        try {
            $status = $this->stripe->retrieveSession($sessionId);
        } catch (Throwable $e) {
            $this->orders->release($orderId);
            error_log('[AEP] Reconciliación: error consultando sesión Stripe (orden ' . $orderId . '): ' . $e->getMessage());

            return;
        }

        if ($status['approved']) {
            $this->markPaid($orderId, [
                'authorization_code' => $status['payment_intent'],
                'payment_type' => 'stripe',
                'card_last4' => '',
            ]);

            return;
        }

        if ($status['status'] === 'expired') {
            $this->orders->update($orderId, [
                'status' => 'failed',
                'authorization_code' => $status['payment_intent'],
            ]);

            return;
        }

        // Sigue "open": el cliente todavía no completa (o abandonó) el
        // checkout. Soltamos el candado para reintentar en la próxima pasada.
        $this->orders->release($orderId);
    }

    /**
     * @param array{authorization_code: string, payment_type: string, card_last4: string} $result
     */
    private function markPaid(int $orderId, array $result): void
    {
        $hours = max(1, (int) SettingsPage::get('link_hours'));
        $this->orders->markPaid($orderId, $result, $hours);

        $order = $this->orders->findById($orderId);
        if ($order !== null && !$this->mailer->sendDownloadEmail($order)) {
            error_log('[AEP] Reconciliación: no se pudo enviar el correo de entrega (orden ' . $order->id . ').');
        }
    }
}
