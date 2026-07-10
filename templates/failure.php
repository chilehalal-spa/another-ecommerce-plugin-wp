<?php
/**
 * Página de pago fallido/anulado.
 *
 * Variables: string $message
 */

if (!defined('ABSPATH')) {
    exit;
}

$aep_retry = remove_query_arg(['aep_status', 'aep_reason', 'aep_order', 'aep_key']);
?>
<div class="aep-box aep-failure" id="aep-comprar">
    <h3>El pago no se completó</h3>
    <p><?php echo esc_html($message); ?></p>
    <p>
        <a class="aep-button" href="<?php echo esc_url($aep_retry); ?>">Intentar nuevamente</a>
    </p>
</div>

<style>
    #aep-comprar.aep-box { max-width: 480px; margin: 1em auto; padding: 24px; border: 1px solid #f0c4bd; background: #fdf4f3; border-radius: 10px; }
    #aep-comprar .aep-button { display: inline-block; padding: 12px 20px; font-weight: 700; color: #fff !important; background: #444; border-radius: 8px; text-decoration: none; }
    #aep-comprar .aep-button:hover { background: #222; }
</style>
