<?php

namespace AnotherEcommerce\Products;

/**
 * Persistencia de productos en la tabla propia {prefix}aep_products.
 */
final class ProductRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aep_products';
    }

    public function create(string $name, int $price, string $pdfFile, bool $active, string $imageUrl = ''): int
    {
        global $wpdb;

        $wpdb->insert($this->table, [
            'name' => $name,
            'price' => $price,
            'pdf_file' => $pdfFile,
            'image_url' => $imageUrl,
            'active' => $active ? 1 : 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $fields): void
    {
        global $wpdb;

        $wpdb->update($this->table, $fields, ['id' => $id]);
    }

    /**
     * Elimina solo la fila del producto. El PDF en disco NO se borra: las
     * órdenes ya pagadas guardan su propio nombre de archivo (snapshot) y
     * deben poder seguir descargándose aunque el producto se elimine.
     */
    public function delete(int $id): void
    {
        global $wpdb;

        $wpdb->delete($this->table, ['id' => $id]);
    }

    public function findById(int $id): ?Product
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id));

        return $row ? Product::fromRow($row) : null;
    }

    /** @return Product[] */
    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY id ASC");

        return array_map([Product::class, 'fromRow'], $rows ?: []);
    }

    public function countAll(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
    }
}
