<?php

namespace AnotherEcommerce\Payment;

use AnotherEcommerce\Products\Product;

/**
 * Punto único para resolver qué gateway usar. Centraliza la regla de
 * disponibilidad: Webpay siempre que esté configurado (vende en CLP);
 * Stripe/Lemon Squeezy solo si están configurados Y el producto tiene un
 * precio en USD (sin precio USD, no hay nada que cobrarles a esos gateways).
 */
final class GatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways;

    public function __construct(WebpayGateway $webpay, StripeGateway $stripe, LemonSqueezyGateway $lemonSqueezy)
    {
        $this->gateways = [
            $webpay->id() => $webpay,
            $stripe->id() => $stripe,
            $lemonSqueezy->id() => $lemonSqueezy,
        ];
    }

    public function get(string $id): ?PaymentGatewayInterface
    {
        return $this->gateways[$id] ?? null;
    }

    /** @return PaymentGatewayInterface[] */
    public function availableFor(Product $product): array
    {
        return array_values(array_filter(
            $this->gateways,
            static fn (PaymentGatewayInterface $gateway): bool => $gateway->isConfigured()
                && ($gateway->id() === 'webpay' || $product->price_usd > 0)
        ));
    }

    public static function currencyFor(string $gatewayId): string
    {
        return $gatewayId === 'webpay' ? 'CLP' : 'USD';
    }
}
