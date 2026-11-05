<?php

namespace AnotherEcommerce\Http;

use AnotherEcommerce\Orders\Order;

/**
 * Redirección de vuelta a la página de origen (con el shortcode del
 * producto) tras resolver un pago, compartida por los distintos
 * controladores de retorno (Webpay, Stripe, Lemon Squeezy).
 */
trait RedirectsToProductPage
{
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
