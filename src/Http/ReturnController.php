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
        }

        // Timeout en Webpay: nunca llegó a pagar.
        if ($tokenWs === '') {
            $order = $tbkBuyOrder !== '' ? $this->orders->findByBuyOrder($tbkBuyOrder) : null;
            if ($order !== null && $order->status === 'pending') {
                $this->orders->update($order->id, ['status' => 'aborted']);
            }

            $this->redirectFailure($order, 'timeout');
        }

        $order = $this->orders->findByToken($tokenWs);
        if ($order === null) {
            $this->redirectFailure(null, 'notfound');
        }

        // Idempotencia: si el cliente refresca la página de retorno, la orden
        // ya quedó pagada y solo lo mandamos de vuelta al éxito, sin re-commit
        // ni segundo correo.
        if ($order->status === 'paid') {
            $this->redirectSuccess($order);
        }

        if (in_array($order->status, ['failed', 'aborted'], true)) {
            $this->redirectFailure($order, 'rejected');
        }

        // Candado atómico contra commits simultáneos (doble clic / refresh).
        if (!$this->orders->claimForProcessing($order->id)) {
            $fresh = $this->orders->findById($order->id);
            if ($fresh !== null && $fresh->status === 'paid') {
                $this->redirectSuccess($fresh);
            }
            $this->redirectFailure($order, 'rejected');
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
            default => 'El pago no se completó.',
        };
    }

    private function param(string $key): string
    {
        // Webpay puede volver por GET o por POST según el escenario.
        return isset($_REQUEST[$key]) ? sanitize_text_field(wp_unslash($_REQUEST[$key])) : '';
    }

    private function redirectSuccess(Order $order): void
    {
        $url = add_query_arg([
            'aep_status' => 'success',
            'aep_order' => $order->id,
            'aep_key' => $order->download_token,
        ], $this->basePage($order));

        wp_safe_redirect($url);
        exit;
    }

    private function redirectFailure(?Order $order, string $reason): void
    {
        $args = [
            'aep_status' => 'failure',
            'aep_reason' => $reason,
        ];

        // Si conocemos la orden, incluimos su id para que la página sepa a
        // qué producto pertenece este fracaso (una misma página puede tener
        // varios botones "Comprar ahora", uno por producto).
        if ($order !== null) {
            $args['aep_order'] = $order->id;
        }

        $url = add_query_arg($args, $this->basePage($order));

        wp_safe_redirect($url);
        exit;
    }

    private function basePage(?Order $order): string
    {
        if ($order !== null && $order->page_url !== '') {
            $validated = wp_validate_redirect($order->page_url, '');
            if ($validated !== '') {
                return $validated;
            }
        }

        return home_url('/');
    }
}
