<?php
/**
 * Formulario "Comprar ahora".
 *
 * Variables: array $settings, string $error_message, string $page_url
 */

if (!defined('ABSPATH')) {
    exit;
}

$aep_price = number_format((int) $settings['price'], 0, ',', '.');
?>
<div class="aep-box" id="aep-comprar">
    <h3 class="aep-title"><?php echo esc_html($settings['product_name']); ?></h3>
    <p class="aep-price">$<?php echo esc_html($aep_price); ?> <span>CLP</span></p>
    <p class="aep-delivery">Recibirás el PDF por correo inmediatamente después del pago.</p>

    <?php if ($error_message !== '') : ?>
        <p class="aep-error"><?php echo esc_html($error_message); ?></p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="aep_checkout">
        <input type="hidden" name="aep_page" value="<?php echo esc_url($page_url); ?>">
        <?php wp_nonce_field('aep_checkout', 'aep_nonce'); ?>

        <p class="aep-field">
            <label for="aep-name">Nombre</label>
            <input type="text" id="aep-name" name="aep_name" required maxlength="190" autocomplete="name">
        </p>

        <p class="aep-field">
            <label for="aep-email">Correo electrónico</label>
            <input type="email" id="aep-email" name="aep_email" required autocomplete="email">
        </p>

        <p class="aep-field">
            <label for="aep-email-confirm">Confirma tu correo</label>
            <input type="email" id="aep-email-confirm" name="aep_email_confirm" required autocomplete="email">
            <small>El PDF se envía a este correo: revísalo bien.</small>
        </p>

        <p class="aep-hp" aria-hidden="true">
            <label for="aep-website">No llenes este campo</label>
            <input type="text" id="aep-website" name="aep_website" tabindex="-1" autocomplete="off">
        </p>

        <p class="aep-terms">
            <label>
                <input type="checkbox" name="aep_terms" value="1" required>
                <span><?php echo esc_html($settings['terms_text']); ?></span>
            </label>
        </p>

        <button type="submit" class="aep-button">Comprar ahora — pagar con Webpay</button>
    </form>
</div>

<style>
    #aep-comprar.aep-box { max-width: 420px; margin: 1em auto; padding: 24px; border: 1px solid #ddd; border-radius: 10px; }
    #aep-comprar .aep-title { margin: 0 0 4px; }
    #aep-comprar .aep-price { font-size: 1.8em; font-weight: 700; margin: 0; }
    #aep-comprar .aep-price span { font-size: 0.5em; font-weight: 400; }
    #aep-comprar .aep-delivery { color: #555; font-size: 0.9em; }
    #aep-comprar .aep-error { background: #fdecea; color: #91271c; padding: 10px 14px; border-radius: 6px; }
    #aep-comprar .aep-field label { display: block; font-weight: 600; margin-bottom: 4px; }
    #aep-comprar .aep-field input { width: 100%; padding: 8px 10px; border: 1px solid #bbb; border-radius: 6px; box-sizing: border-box; }
    #aep-comprar .aep-field small { color: #666; }
    #aep-comprar .aep-hp { position: absolute; left: -9999px; }
    #aep-comprar .aep-terms { font-size: 0.85em; color: #444; }
    #aep-comprar .aep-button { width: 100%; padding: 14px; font-size: 1.05em; font-weight: 700; color: #fff; background: #1a7f37; border: 0; border-radius: 8px; cursor: pointer; }
    #aep-comprar .aep-button:hover { background: #166b2f; }
</style>
