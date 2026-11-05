<?php

namespace AnotherEcommerce\Orders;

use AnotherEcommerce\Products\Product;

/**
 * Persistencia de órdenes en la tabla propia {prefix}aep_orders.
 */
final class OrderRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aep_orders';
    }

    /**
     * Crea la orden copiando nombre, precio y archivo del producto tal como
     * estaban al momento de la compra (snapshot): si luego el producto se
     * edita, renombra o elimina, esta orden y su descarga siguen intactas.
     *
     * $amount/$currency se pasan aparte (no siempre son $product->price/CLP):
     * una compra en Stripe o Lemon Squeezy usa $product->price_usd en USD.
     */
    public function create(string $name, string $email, Product $product, string $pageUrl, int $amount, string $currency, string $gateway): ?Order
    {
        global $wpdb;

        $inserted = $wpdb->insert($this->table, [
            // Placeholder único; el buy_order definitivo se fija con el id.
            'buy_order' => 'TMP-' . strtoupper(wp_generate_password(20, false)),
            'status' => 'pending',
            'customer_name' => $name,
            'customer_email' => $email,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'pdf_file' => $product->pdf_file,
            'amount' => $amount,
            'gateway' => $gateway,
            'currency' => $currency,
            'page_url' => $pageUrl,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        if ($inserted === false) {
            return null;
        }

        $id = (int) $wpdb->insert_id;

        // buy_order legible, único y dentro de los 26 caracteres que admite Transbank.
        $buyOrder = 'AEP-' . $id . '-' . strtoupper(wp_generate_password(8, false));
        $wpdb->update($this->table, ['buy_order' => $buyOrder], ['id' => $id]);

        return $this->findById($id);
    }

    public function findById(int $id): ?Order
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id));

        return $row ? Order::fromRow($row) : null;
    }

    public function findByToken(string $tokenWs): ?Order
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE token_ws = %s", $tokenWs));

        return $row ? Order::fromRow($row) : null;
    }

    public function findByBuyOrder(string $buyOrder): ?Order
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE buy_order = %s", $buyOrder));

        return $row ? Order::fromRow($row) : null;
    }

    /**
     * Busca por la referencia externa del gateway (Checkout Session de
     * Stripe, referencia propia enviada como custom_data a Lemon Squeezy).
     * Se filtra también por $gateway porque esa referencia solo es única
     * dentro de cada proveedor, no globalmente.
     */
    public function findByGatewayReference(string $gateway, string $reference): ?Order
    {
        global $wpdb;

        if ($reference === '') {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE gateway = %s AND gateway_reference = %s",
            $gateway,
            $reference
        ));

        return $row ? Order::fromRow($row) : null;
    }

    public function update(int $id, array $fields): void
    {
        global $wpdb;

        $wpdb->update($this->table, $fields, ['id' => $id]);
    }

    /**
     * Transición a 'paid' compartida por el retorno normal (ReturnController)
     * y por la reconciliación en segundo plano (OrderReconciler): genera el
     * token de descarga y fija su vencimiento.
     *
     * @param array{authorization_code: string, payment_type: string, card_last4: string} $webpayResult
     */
    public function markPaid(int $id, array $webpayResult, int $linkHours): void
    {
        $this->update($id, [
            'status' => 'paid',
            'authorization_code' => $webpayResult['authorization_code'],
            'payment_type' => $webpayResult['payment_type'],
            'card_last4' => $webpayResult['card_last4'],
            'download_token' => wp_generate_password(48, false),
            'download_expires_at' => gmdate('Y-m-d H:i:s', time() + $linkHours * HOUR_IN_SECONDS),
            'paid_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Candado anti doble procesamiento: pasa la orden de pending a processing
     * de forma atómica. Si otro request (un refresh del retorno) llegó antes,
     * el UPDATE afecta 0 filas y devolvemos false.
     */
    public function claimForProcessing(int $id): bool
    {
        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET status = 'processing' WHERE id = %d AND status = 'pending'",
            $id
        ));

        return $updated === 1;
    }

    /**
     * Suelta el candado de 'processing' cuando la reconciliación no pudo
     * determinar qué pasó con la transacción (p. ej. Webpay no respondió),
     * para reintentarla en la siguiente pasada del cron.
     */
    public function release(int $id): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET status = 'pending' WHERE id = %d AND status = 'processing'",
            $id
        ));
    }

    /**
     * Órdenes 'pending'/'processing' con una transacción creada (token_ws
     * para Webpay, gateway_reference para Stripe) que llevan más de
     * $minutesOld sin resolverse: el navegador del cliente probablemente
     * nunca volvió del retorno. Candidatas para que OrderReconciler consulte
     * su estado real en el gateway correspondiente.
     *
     * Lemon Squeezy queda fuera a propósito: no hay una forma barata de
     * consultar el estado de un checkout sin conocer ya su order id, así que
     * depende enteramente de su webhook (ver OrderReconciler).
     *
     * @return Order[]
     */
    public function staleUnresolved(int $minutesOld, int $limit): array
    {
        global $wpdb;

        $threshold = gmdate('Y-m-d H:i:s', time() - $minutesOld * MINUTE_IN_SECONDS);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status IN ('pending', 'processing')
               AND ((gateway = 'webpay' AND token_ws != '') OR (gateway = 'stripe' AND gateway_reference != ''))
               AND created_at <= %s
             ORDER BY id ASC
             LIMIT %d",
            $threshold,
            $limit
        ));

        return array_map([Order::class, 'fromRow'], $rows ?: []);
    }

    public function incrementDownloads(int $id): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET downloads = downloads + 1 WHERE id = %d",
            $id
        ));
    }

    /** @return Order[] */
    public function latest(int $limit = 50, int $offset = 0): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));

        return array_map([Order::class, 'fromRow'], $rows ?: []);
    }

    public function countAll(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
    }
}
