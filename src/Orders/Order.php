<?php

namespace AnotherEcommerce\Orders;

/**
 * Objeto de datos de una orden. Estados posibles:
 *  - pending:    creada, esperando pago en Webpay
 *  - processing: retorno recibido, commit en curso (candado anti doble commit)
 *  - paid:       pago autorizado, entrega habilitada
 *  - failed:     Webpay rechazó la transacción
 *  - aborted:    el cliente anuló el pago o expiró la sesión en Webpay
 */
final class Order
{
    public int $id = 0;
    public string $buy_order = '';
    public string $token_ws = '';
    public string $status = 'pending';
    public string $customer_name = '';
    public string $customer_email = '';
    public int $product_id = 0;
    public string $product_name = '';
    public string $pdf_file = '';
    public int $amount = 0;
    public string $authorization_code = '';
    public string $payment_type = '';
    public string $card_last4 = '';
    public string $download_token = '';
    public int $downloads = 0;
    public ?string $download_expires_at = null;
    public string $page_url = '';
    public string $created_at = '';
    public ?string $paid_at = null;

    public static function fromRow(object $row): self
    {
        $order = new self();
        $order->id = (int) $row->id;
        $order->buy_order = (string) $row->buy_order;
        $order->token_ws = (string) $row->token_ws;
        $order->status = (string) $row->status;
        $order->customer_name = (string) $row->customer_name;
        $order->customer_email = (string) $row->customer_email;
        $order->product_id = (int) $row->product_id;
        $order->product_name = (string) $row->product_name;
        $order->pdf_file = (string) $row->pdf_file;
        $order->amount = (int) $row->amount;
        $order->authorization_code = (string) $row->authorization_code;
        $order->payment_type = (string) $row->payment_type;
        $order->card_last4 = (string) $row->card_last4;
        $order->download_token = (string) $row->download_token;
        $order->downloads = (int) $row->downloads;
        $order->download_expires_at = $row->download_expires_at !== null ? (string) $row->download_expires_at : null;
        $order->page_url = (string) $row->page_url;
        $order->created_at = (string) $row->created_at;
        $order->paid_at = $row->paid_at !== null ? (string) $row->paid_at : null;

        return $order;
    }

    public function downloadExpired(): bool
    {
        if ($this->download_expires_at === null) {
            return true;
        }

        return strtotime($this->download_expires_at . ' UTC') < time();
    }
}
