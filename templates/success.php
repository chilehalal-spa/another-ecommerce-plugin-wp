<?php
/**
 * Página de compra exitosa.
 *
 * Variables: \AnotherEcommerce\Orders\Order $order, array $settings, string $download_url
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="aep-box aep-success" id="aep-comprar-<?php echo esc_attr((string) $order->product_id); ?>">
    <h3>¡Gracias por tu compra, <?php echo esc_html($order->customer_name); ?>!</h3>
    <p>
        Tu pago fue aprobado (orden <code><?php echo esc_html($order->buy_order); ?></code>).
        Te enviamos el enlace de descarga a <strong><?php echo esc_html($order->customer_email); ?></strong>;
        si no llega en unos minutos, revisa la carpeta de spam.
    </p>
    <p>También puedes descargarlo ahora mismo:</p>
    <p>
        <a class="aep-button" href="<?php echo esc_url($download_url); ?>">
            Descargar <?php echo esc_html($order->product_name); ?>
        </a>
    </p>
    <p class="aep-note">
        El enlace es personal, vence en <?php echo esc_html((string) $settings['link_hours']); ?> horas
        y admite hasta <?php echo esc_html((string) $settings['max_downloads']); ?> descargas.
        Guarda el archivo en tu dispositivo.
    </p>
</div>

<style>
    .aep-box.aep-success { max-width: 480px; margin: 1em auto; padding: 24px; border: 1px solid #b7e0c0; background: #f2fbf4; border-radius: 10px; }
    .aep-box.aep-success .aep-button { display: inline-block; padding: 14px 22px; font-weight: 700; color: #fff !important; background: #1a7f37; border-radius: 8px; text-decoration: none; }
    .aep-box.aep-success .aep-button:hover { background: #166b2f; }
    .aep-box.aep-success .aep-note { color: #555; font-size: 0.85em; }
</style>
