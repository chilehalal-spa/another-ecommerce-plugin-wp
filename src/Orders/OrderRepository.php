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
     */
    public function create(string $name, string $email, Product $product, string $pageUrl): ?Order
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
            'amount' => $product->price,
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

    public function update(int $id, array $fields): void
    {
        global $wpdb;

        $wpdb->update($this->table, $fields, ['id' => $id]);
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
