<?php

namespace AnotherEcommerce\Payment;

use AnotherEcommerce\Admin\SettingsPage;
use RuntimeException;
use Transbank\Webpay\Options;
use Transbank\Webpay\WebpayPlus\Transaction;

/**
 * Envoltorio del SDK de Transbank para Webpay Plus (REST).
 *
 * En modo integración se usan las credenciales públicas de prueba de
 * Transbank, así que se puede probar el flujo completo sin tener aún el
 * código de comercio productivo.
 */
final class WebpayGateway
{
    private const INTEGRATION_COMMERCE_CODE = '597055555532';
    private const INTEGRATION_API_KEY = '579B532A7440BB0C9079DED94D31EA1615BACEB56610332264630D42D0A36B1C';

    public static function sdkAvailable(): bool
    {
        return class_exists(Transaction::class);
    }

    /**
     * Crea la transacción en Webpay.
     *
     * @return array{token: string, url: string}
     */
    public function createTransaction(string $buyOrder, string $sessionId, int $amount, string $returnUrl): array
    {
        $response = $this->transaction()->create($buyOrder, $sessionId, $amount, $returnUrl);

        $token = (string) $response->getToken();
        $url = (string) $response->getUrl();

        if ($token === '' || $url === '') {
            throw new RuntimeException('Webpay devolvió una respuesta vacía al crear la transacción.');
        }

        return ['token' => $token, 'url' => $url];
    }

    /**
     * Confirma (commit) la transacción y normaliza la respuesta.
     *
     * @return array{
     *     approved: bool,
     *     status: string,
     *     response_code: int,
     *     authorization_code: string,
     *     payment_type: string,
     *     card_last4: string,
     *     amount: int
     * }
     */
    public function commitTransaction(string $tokenWs): array
    {
        return $this->normalizeResult($this->transaction()->commit($tokenWs));
    }

    /**
     * Consulta el estado de la transacción en Webpay sin confirmarla. Sirve
     * para reconciliar órdenes cuyo commit nunca llegó a hacerse (el
     * navegador del cliente no volvió del retorno). Si el estado es
     * "INITIALIZED", Webpay todavía no fue confirmada por nadie.
     *
     * @return array{
     *     approved: bool,
     *     status: string,
     *     response_code: int,
     *     authorization_code: string,
     *     payment_type: string,
     *     card_last4: string,
     *     amount: int
     * }
     */
    public function statusTransaction(string $tokenWs): array
    {
        return $this->normalizeResult($this->transaction()->status($tokenWs));
    }

    /**
     * @return array{
     *     approved: bool,
     *     status: string,
     *     response_code: int,
     *     authorization_code: string,
     *     payment_type: string,
     *     card_last4: string,
     *     amount: int
     * }
     */
    private function normalizeResult(object $response): array
    {
        return [
            'approved' => $response->isApproved(),
            'status' => (string) $response->getStatus(),
            'response_code' => (int) ($response->getResponseCode() ?? -1),
            'authorization_code' => (string) ($response->getAuthorizationCode() ?? ''),
            'payment_type' => (string) ($response->getPaymentTypeCode() ?? ''),
            'card_last4' => $this->extractCardLast4($response),
            'amount' => (int) $response->getAmount(),
        ];
    }

    private function transaction(): Transaction
    {
        if (!self::sdkAvailable()) {
            throw new RuntimeException(
                'El SDK de Transbank no está instalado. Ejecuta "composer install" en el directorio del plugin.'
            );
        }

        if (SettingsPage::get('environment') === 'production') {
            $commerceCode = (string) SettingsPage::get('commerce_code');
            $apiKey = (string) SettingsPage::get('api_key');

            if ($commerceCode === '' || $apiKey === '') {
                throw new RuntimeException('Faltan las credenciales de producción de Webpay en los ajustes.');
            }

            return new Transaction(new Options($apiKey, $commerceCode, Options::ENVIRONMENT_PRODUCTION));
        }

        return new Transaction(new Options(
            self::INTEGRATION_API_KEY,
            self::INTEGRATION_COMMERCE_CODE,
            Options::ENVIRONMENT_INTEGRATION
        ));
    }

    private function extractCardLast4(object $response): string
    {
        $detail = method_exists($response, 'getCardDetail') ? $response->getCardDetail() : null;

        $number = '';
        if (is_object($detail) && method_exists($detail, 'getCardNumber')) {
            $number = (string) $detail->getCardNumber();
        } elseif (is_array($detail) && isset($detail['card_number'])) {
            $number = (string) $detail['card_number'];
        } elseif (method_exists($response, 'getCardNumber')) {
            $number = (string) $response->getCardNumber();
        }

        // Webpay entrega el número enmascarado (p. ej. XXXXXXXXXXXX6623).
        return $number !== '' ? substr($number, -4) : '';
    }
}
