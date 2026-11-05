<?php

namespace AnotherEcommerce\Http;

use AnotherEcommerce\Admin\SettingsPage;
use AnotherEcommerce\Delivery\Mailer;
use AnotherEcommerce\Orders\Order;
use AnotherEcommerce\Orders\OrderRepository;
use AnotherEcommerce\Payment\WebpayGateway;
use Throwable;

/**
 * Procesa el retorno desde Webpay. Transbank puede volver por GET o POST y
 * distingue cuatro escenarios según los parámetros presentes:
 *
 *  - token_ws                            -> flujo normal, hay que hacer commit
 *  - TBK_TOKEN (+ TBK_ORDEN_COMPRA)      -> el cliente anuló el pago
 *  - token_ws + TBK_TOKEN                -> error en el formulario de pago
 *  - TBK_ORDEN_COMPRA + TBK_ID_SESION    -> timeout (10 min sin actividad)
 */
final class ReturnController
{
    use RedirectsToProductPage;

    public function __construct(
        private OrderRepository $orders,
        private WebpayGateway $gateway,
        private Mailer $mailer,
    ) {
    }

    public function handle(): void
    {
        $tokenWs = $this->param('token_ws');
        $tbkToken = $this->param('TBK_TOKEN');
        $tbkBuyOrder = $this->param('TBK_ORDEN_COMPRA');

        // Anulación por el cliente o error en el formulario de pago.
        if ($tbkToken !== '') {
            $order = $tokenWs !== ''
                ? $this->orders->findByToken($tokenWs)
                : ($tbkBuyOrder !== '' ? $this->orders->findByBuyOrder($tbkBuyOrder) : null);

            if ($order !== null && in_array($order->status, ['pending', 'processing'], true)) {
                $this->orders->update($order->id, ['status' => 'aborted']);
            }

            $this->redirectFailure($order, 'aborted');

            return;
        }

        // Timeout en Webpay: nunca llegó a pagar.
        if ($tokenWs === '') {
            $order = $tbkBuyOrder !== '' ? $this->orders->findByBuyOrder($tbkBuyOrder) : null;
            if ($order !== null && $order->status === 'pending') {
                $this->orders->update($order->id, ['status' => 'aborted']);
            }

            $this->redirectFailure($order, 'timeout');

            return;
        }

        $order = $this->orders->findByToken($tokenWs);
        if ($order === null) {
            $this->redirectFailure(null, 'notfound');

            return;
        }

        // Idempotencia: si el cliente refresca la página de retorno, la orden
        // ya quedó pagada y solo lo mandamos de vuelta al éxito, sin re-commit
        // ni segundo correo.
        if ($order->status === 'paid') {
            $this->redirectSuccess($order);

            return;
        }

        if (in_array($order->status, ['failed', 'aborted'], true)) {
            $this->redirectFailure($order, 'rejected');

            return;
        }

        // Candado atómico contra commits simultáneos (doble clic / refresh, o
        // el cron de reconciliación tomando la orden justo ahora). Si no lo
        // conseguimos, esa otra ejecución normalmente termina en milisegundos:
        // esperamos un poco en vez de decirle al cliente "rechazado" cuando en
        // realidad puede terminar aprobado.
        if (!$this->orders->claimForProcessing($order->id)) {
            $resolved = $this->waitForResolution($order->id) ?? $order;

            if ($resolved->status === 'paid') {
                $this->redirectSuccess($resolved);

                return;
            }

            if (in_array($resolved->status, ['failed', 'aborted'], true)) {
                $this->redirectFailure($resolved, 'rejected');

                return;
            }

            // Sigue 'processing': la otra ejecución no terminó a tiempo. No
            // sabemos el resultado todavía, así que no decimos "rechazado".
            $this->redirectFailure($resolved, 'processing');

            return;
        }

        try {
            $result = $this->gateway->commitTransaction($tokenWs);
        } catch (Throwable $e) {
            error_log('[AEP] Error en commit Webpay (orden ' . $order->id . '): ' . $e->getMessage());
            $this->orders->update($order->id, ['status' => 'failed']);
            $this->redirectFailure($order, 'commit_error');

            return;
        }

        if (!$result['approved']) {
            $this->orders->update($order->id, [
                'status' => 'failed',
                'authorization_code' => $result['authorization_code'],
                'payment_type' => $result['payment_type'],
            ]);
            $this->redirectFailure($order, 'rejected');

            return;
        }

        $hours = max(1, (int) SettingsPage::get('link_hours'));
        $this->orders->markPaid($order->id, $result, $hours);

        $paid = $this->orders->findById($order->id);

        if (!$this->mailer->sendDownloadEmail($paid)) {
            // No cortamos el flujo: la página de éxito muestra el mismo enlace
            // y desde el admin se puede reenviar el correo.
            error_log('[AEP] No se pudo enviar el correo de entrega (orden ' . $paid->id . ').');
        }

        $this->redirectSuccess($paid);
    }

    public static function failureMessage(string $reason): string
    {
        return match ($reason) {
            'aborted' => 'Anulaste el pago. No se realizó ningún cargo.',
            'timeout' => 'La sesión de pago expiró. No se realizó ningún cargo.',
            'rejected' => 'El pago fue rechazado. No se realizó ningún cargo; puedes intentar con otro medio de pago.',
            'commit_error' => 'No pudimos confirmar el pago con Webpay. Si ves un cargo en tu tarjeta, contáctanos.',
            'notfound' => 'No encontramos la orden asociada a este pago.',
            'processing' => 'Todavía estamos confirmando tu pago. Espera un momento y vuelve a intentar; si el cargo aparece en tu tarjeta y no recibes el PDF, contáctanos.',
            default => 'El pago no se completó.',
        };
    }

    private function param(string $key): string
    {
        // Webpay puede volver por GET o por POST según el escenario.
        return isset($_REQUEST[$key]) ? sanitize_text_field(wp_unslash($_REQUEST[$key])) : '';
    }

    /**
     * Espera a que otra ejecución que ya tiene el candado 'processing'
     * (doble clic, refresh, o el cron de reconciliación) termine de resolver
     * la orden. El commit contra Transbank normalmente tarda menos de un
     * segundo; 3 intentos de ~0.7s son de sobra para el caso normal sin
     * bloquear el request más de ~2 segundos en el caso raro de que tarde más.
     */
    private function waitForResolution(int $orderId): ?Order
    {
        for ($i = 0; $i < 3; $i++) {
            usleep(700_000);
            $order = $this->orders->findById($orderId);
            if ($order === null || $order->status !== 'processing') {
                return $order;
            }
        }

        return $this->orders->findById($orderId);
    }
}
