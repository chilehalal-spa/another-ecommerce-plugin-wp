<?php

namespace AnotherEcommerce\Delivery;

use AnotherEcommerce\Admin\SettingsPage;
use AnotherEcommerce\Orders\Order;

/**
 * Correo de entrega: enlace de descarga temporal (no adjuntamos el PDF para
 * no disparar filtros de spam ni regalar el archivo si reenvían el correo).
 */
final class Mailer
{
    public function sendDownloadEmail(Order $order): bool
    {
        if ($order->status !== 'paid' || !is_email($order->customer_email)) {
            return false;
        }

        $subject = (string) SettingsPage::get('email_subject');
        $body = (string) SettingsPage::get('email_body');

        $replacements = [
            '{nombre}' => $order->customer_name,
            '{producto}' => $order->product_name,
            '{enlace}' => DownloadController::downloadUrl($order),
            '{horas}' => (string) max(1, (int) SettingsPage::get('link_hours')),
            '{descargas}' => (string) max(1, (int) SettingsPage::get('max_downloads')),
            '{orden}' => $order->buy_order,
        ];

        $subject = strtr($subject, $replacements);
        $body = strtr($body, $replacements);

        $html = '<div style="font-family:sans-serif;max-width:560px;margin:0 auto;line-height:1.6;">'
            . wpautop(wp_kses_post($body))
            . '<p style="margin-top:24px;"><a href="' . esc_url($replacements['{enlace}']) . '" '
            . 'style="background:#1a7f37;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;display:inline-block;">'
            . esc_html__('Descargar mi PDF', 'another-ecommerce-plugin') . '</a></p>'
            . '<p style="color:#666;font-size:13px;">' . esc_html(sprintf(
                'Si el botón no funciona, copia y pega este enlace en tu navegador: %s',
                $replacements['{enlace}']
            )) . '</p>'
            . '</div>';

        return wp_mail(
            $order->customer_email,
            $subject,
            $html,
            ['Content-Type: text/html; charset=UTF-8']
        );
    }
}
