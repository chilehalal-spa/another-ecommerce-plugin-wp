<?php

namespace AnotherEcommerce\Payment;

use AnotherEcommerce\Admin\SettingsPage;
use AnotherEcommerce\Orders\Order;
use AnotherEcommerce\Orders\OrderRepository;
use RuntimeException;

/**
 * Lemon Squeezy no tiene SDK oficial de PHP: se habla directo con su API
 * JSON:API vía wp_remote_*. El checkout se crea contra UNA variante ya
 * existente en el dashboard de Lemon Squeezy (configurada en Ajustes), y su
 * precio se sobreescribe por compra con "custom_price".
 *
 * A diferencia de Webpay/Stripe, no hay forma barata de consultar el
 * resultado de un checkout desde el servidor: la confirmación depende
 * enteramente del webhook (order_created). Por eso $order->buy_order (ya
 * único) se manda como dato personalizado del checkout, para que el webhook
 * pueda encontrar la orden de vuelta.
 */
final class LemonSqueezyGateway implements PaymentGatewayInterface
{
    private const API_BASE = 'https://api.lemonsqueezy.com/v1';

    public function __construct(private OrderRepository $orders)
    {
    }

    public function id(): string
    {
        return 'lemonsqueezy';
    }

    public function isConfigured(): bool
    {
        return !empty(SettingsPage::get('ls_enabled'))
            && (string) SettingsPage::get('ls_api_key') !== ''
            && (string) SettingsPage::get('ls_store_id') !== ''
            && (string) SettingsPage::get('ls_variant_id') !== '';
    }

    public function createCheckout(Order $order, string $returnUrl): string
    {
        $redirectUrl = add_query_arg(['aep_order' => $order->id], $returnUrl);

        $response = $this->request('POST', '/checkouts', [
            'data' => [
                'type' => 'checkouts',
                'attributes' => [
                    'custom_price' => $order->amount,
                    'product_options' => [
                        'name' => $order->product_name,
                        'redirect_url' => $redirectUrl,
                    ],
                    'checkout_data' => [
                        'email' => $order->customer_email,
                        'name' => $order->customer_name,
                        'custom' => ['aep_order_ref' => $order->buy_order],
                    ],
                ],
                'relationships' => [
                    'store' => ['data' => ['type' => 'stores', 'id' => (string) SettingsPage::get('ls_store_id')]],
                    'variant' => ['data' => ['type' => 'variants', 'id' => (string) SettingsPage::get('ls_variant_id')]],
                ],
            ],
        ]);

        $url = $response['data']['attributes']['url'] ?? null;
        if (!is_string($url) || $url === '') {
            throw new RuntimeException('Lemon Squeezy devolvió una respuesta vacía al crear el checkout.');
        }

        $this->orders->update($order->id, ['gateway_reference' => $order->buy_order]);

        return $url;
    }

    public function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        $secret = (string) SettingsPage::get('ls_webhook_secret');
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $computed = hash_hmac('sha256', $payload, $secret);

        return hash_equals($computed, $signatureHeader);
    }

    /**
     * Extrae de un payload de webhook ya decodificado los datos que importan:
     * la referencia de nuestra orden (mandada como custom data al crear el
     * checkout) y si el pago fue aprobado.
     *
     * @return array{order_ref: string, approved: bool, authorization_code: string}
     */
    public function parseOrderCreatedEvent(array $payload): array
    {
        $orderRef = (string) ($payload['meta']['custom_data']['aep_order_ref'] ?? '');
        $attributes = $payload['data']['attributes'] ?? [];
        $status = (string) ($attributes['status'] ?? '');
        $identifier = (string) ($attributes['identifier'] ?? '');

        return [
            'order_ref' => $orderRef,
            'approved' => in_array($status, ['paid', 'completed'], true),
            'authorization_code' => $identifier,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $body): array
    {
        $apiKey = (string) SettingsPage::get('ls_api_key');
        if ($apiKey === '') {
            throw new RuntimeException('Falta la API Key de Lemon Squeezy en Ajustes → Internacional.');
        }

        $response = wp_remote_request(self::API_BASE . $path, [
            'method' => $method,
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/vnd.api+json',
                'Authorization' => 'Bearer ' . $apiKey,
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException('Error de conexión con Lemon Squeezy: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($data)) {
            throw new RuntimeException('Lemon Squeezy respondió con un error (HTTP ' . $code . ').');
        }

        return $data;
    }
}
