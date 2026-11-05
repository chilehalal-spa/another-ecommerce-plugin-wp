<?php

namespace AnotherEcommerce\Products;

/**
 * Un artículo en venta: nombre, precio propio y su PDF. Cada producto tiene
 * su propio botón "Comprar ahora" ([aep_comprar id="N"]); no hay carrito ni
 * catálogo compartido, cada compra es independiente.
 */
final class Product
{
    public int $id = 0;
    public string $name = '';
    public int $price = 0;
    /** Precio de venta internacional, en centavos de USD (999 = US$9.99). 0 = no se vende fuera de Chile. */
    public int $price_usd = 0;
    public string $pdf_file = '';
    public string $image_url = '';
    public bool $active = true;
    public string $created_at = '';

    public static function fromRow(object $row): self
    {
        $product = new self();
        $product->id = (int) $row->id;
        $product->name = (string) $row->name;
        $product->price = (int) $row->price;
        $product->price_usd = (int) ($row->price_usd ?? 0);
        $product->pdf_file = (string) $row->pdf_file;
        $product->image_url = (string) ($row->image_url ?? '');
        $product->active = ((int) $row->active) === 1;
        $product->created_at = (string) $row->created_at;

        return $product;
    }
}
