<?php

namespace AnotherEcommerce\Payment;

use AnotherEcommerce\Orders\Order;

/**
 * Contrato mínimo común a los tres medios de pago (Webpay, Stripe, Lemon
 * Squeezy) para que CheckoutController pueda iniciar un cobro sin conocer
 * los detalles de cada proveedor. La confirmación del pago (commit, retorno,
 * webhook) es específica de cada uno y no forma parte de esta interfaz.
 */
interface PaymentGatewayInterface
{
    /** 'webpay' | 'stripe' | 'lemonsqueezy' */
    public function id(): string;

    /** Si el admin cargó las credenciales necesarias en Ajustes. */
    public function isConfigured(): bool;

    /**
     * Inicia el cobro y devuelve la URL a la que hay que redirigir al
     * cliente para completarlo (checkout hospedado por el proveedor).
     */
    public function createCheckout(Order $order, string $returnUrl): string;
}
