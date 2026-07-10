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
    public string $pdf_file = '';
    public bool $active = true;
    public string $created_at = '';

    public static function fromRow(object $row): self
    {
        $product = new self();
        $product->id = (int) $row->id;
        $product->name = (string) $row->name;
        $product->price = (int) $row->price;
        $product->pdf_file = (string) $row->pdf_file;
        $product->active = ((int) $row->active) === 1;
        $product->created_at = (string) $row->created_at;

        return $product;
    }
}
