<?php

namespace AnotherEcommerce\Payment;

use AnotherEcommerce\Admin\SettingsPage;
use AnotherEcommerce\Orders\Order;
use AnotherEcommerce\Orders\OrderRepository;
use RuntimeException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Envoltorio del SDK oficial de Stripe (Checkout Sessions) para vender en
 * USD a clientes internacionales. $order->amount ya viene en centavos
 * (convención de Stripe), así que se manda tal cual como unit_amount.
 */
final class StripeGateway implements PaymentGatewayInterface
{
    public function __construct(private OrderRepository $orders)
    {
    }

    public function id(): string
    {
        return 'stripe';
    }

    public static function sdkAvailable(): bool
    {
        return class_exists(StripeClient::class);
    }

    public function isConfigured(): bool
    {
        return self::sdkAvailable()
            && !empty(SettingsPage::get('stripe_enabled'))
            && (string) SettingsPage::get('stripe_secret_key') !== '';
    }

    /**
     * Crea una Checkout Session hospedada por Stripe y guarda su id como
     * gateway_reference (usado por el retorno y por el webhook para
     * encontrar la orden). success_url lleva el placeholder literal
     * {CHECKOUT_SESSION_ID} que Stripe reemplaza al redirigir de vuelta;
     * no se puede pasar por add_query_arg() porque eso lo urlencodearía.
     */
    public function createCheckout(Order $order, string $returnUrl): string
    {
        $successUrl = add_query_arg(['aep_order' => $order->id], $returnUrl) . '&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = add_query_arg(['aep_order' => $order->id, 'aep_cancelled' => '1'], $returnUrl);

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $order->customer_email,
            'client_reference_id' => (string) $order->id,
            'metadata' => ['aep_order_id' => (string) $order->id],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => $order->amount,
                    'product_data' => ['name' => $order->product_name],
                ],
            ]],
        ]);

        $this->orders->update($order->id, ['gateway_reference' => (string) $session->id]);

        return (string) $session->url;
    }

    /**
     * @return array{approved: bool, status: string, payment_intent: string}
     */
    public function retrieveSession(string $sessionId): array
    {
        $session = $this->client()->checkout->sessions->retrieve($sessionId);

        return [
            'approved' => $session->payment_status === 'paid',
            'status' => (string) $session->status,
            'payment_intent' => is_string($session->payment_intent) ? $session->payment_intent : (string) ($session->payment_intent->id ?? ''),
        ];
    }

    /**
     * Verifica la firma del webhook con el secreto configurado. Devuelve el
     * evento decodificado, o null si la firma no es válida.
     */
    public function verifyWebhookEvent(string $payload, string $signatureHeader): ?\Stripe\Event
    {
        $secret = (string) SettingsPage::get('stripe_webhook_secret');
        if ($secret === '') {
            return null;
        }

        try {
            return Webhook::constructEvent($payload, $signatureHeader, $secret);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function client(): StripeClient
    {
        if (!self::sdkAvailable()) {
            throw new RuntimeException('El SDK de Stripe no está instalado. Ejecuta "composer install" en el directorio del plugin.');
        }

        $secretKey = (string) SettingsPage::get('stripe_secret_key');
        if ($secretKey === '') {
            throw new RuntimeException('Falta la Secret Key de Stripe en Ajustes → Internacional.');
        }

        return new StripeClient($secretKey);
    }
}
