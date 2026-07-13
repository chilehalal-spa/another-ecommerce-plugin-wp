<?php
/**
 * Tarjeta "Comprar ahora" de un producto: portada + nombre + precio + botón
 * visibles siempre, formulario completo oculto dentro de un <details> nativo
 * que el navegador expande al hacer clic (sin JavaScript).
 *
 * Variables: \AnotherEcommerce\Products\Product $product, array $settings,
 * string $error_message, string $page_url
 */

if (!defined('ABSPATH')) {
    exit;
}

$aep_price = number_format($product->price, 0, ',', '.');
// Sufijo único por producto: una misma página puede tener varios botones
// "Comprar ahora", uno por producto, y los ids de los campos no pueden repetirse.
$aep_uid = (string) $product->id;
// Si venimos de un error de este mismo producto, abrimos la tarjeta de una
// vez para que el cliente vea el mensaje sin tener que hacer clic primero.
$aep_open = $error_message !== '';
?>
<details class="aep-box" id="aep-comprar-<?php echo esc_attr($aep_uid); ?>" <?php echo $aep_open ? 'open' : ''; ?>>
    <summary class="aep-summary">
        <span class="aep-header">
            <span class="aep-title"><?php echo esc_html($product->name); ?></span>
            <span class="aep-price">$<?php echo esc_html($aep_price); ?> <small>CLP</small></span>
        </span>
        <?php if ($product->image_url !== '') : ?>
            <span class="aep-cover">
                <img src="<?php echo esc_url($product->image_url); ?>" alt="">
            </span>
        <?php endif; ?>
        <span class="aep-cta">
            Comprar ahora
            <svg class="aep-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </span>
    </summary>

    <div class="aep-body">
        <p class="aep-delivery">Recibirás el PDF por correo inmediatamente después del pago.</p>

        <?php if ($error_message !== '') : ?>
            <p class="aep-error"><?php echo esc_html($error_message); ?></p>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="aep_checkout">
            <input type="hidden" name="aep_product_id" value="<?php echo esc_attr($aep_uid); ?>">
            <input type="hidden" name="aep_page" value="<?php echo esc_url($page_url); ?>">
            <?php wp_nonce_field('aep_checkout', 'aep_nonce'); ?>

            <p class="aep-field">
                <label for="aep-name-<?php echo esc_attr($aep_uid); ?>">Nombre</label>
                <input type="text" id="aep-name-<?php echo esc_attr($aep_uid); ?>" name="aep_name" required maxlength="190" autocomplete="name">
            </p>

            <p class="aep-field">
                <label for="aep-email-<?php echo esc_attr($aep_uid); ?>">Correo electrónico</label>
                <input type="email" id="aep-email-<?php echo esc_attr($aep_uid); ?>" name="aep_email" required autocomplete="email">
            </p>

            <p class="aep-field">
                <label for="aep-email-confirm-<?php echo esc_attr($aep_uid); ?>">Confirma tu correo</label>
                <input type="email" id="aep-email-confirm-<?php echo esc_attr($aep_uid); ?>" name="aep_email_confirm" required autocomplete="email">
                <small>El PDF se envía a este correo: revísalo bien.</small>
            </p>

            <p class="aep-hp" aria-hidden="true">
                <label for="aep-website-<?php echo esc_attr($aep_uid); ?>">No llenes este campo</label>
                <input type="text" id="aep-website-<?php echo esc_attr($aep_uid); ?>" name="aep_website" tabindex="-1" autocomplete="off">
            </p>

            <p class="aep-terms">
                <label>
                    <input type="checkbox" name="aep_terms" value="1" required>
                    <span class="aep-terms-text"><?php echo wp_kses_post($settings['terms_text']); ?></span>
                </label>
            </p>

            <button type="submit" class="aep-button">Pagar con Webpay</button>
        </form>
    </div>
</details>

<style>
    .aep-box {
        max-width: 420px; margin: 1em auto; border: 1px solid #ddd; border-radius: 12px;
        overflow: hidden; background: #fff;
    }
    .aep-box summary.aep-summary { list-style: none; cursor: pointer; display: block; }
    .aep-box summary.aep-summary::-webkit-details-marker,
    .aep-box summary.aep-summary::marker { display: none; content: ""; }
    .aep-box .aep-header { display: block; padding: 16px 18px 12px; }
    .aep-box .aep-title { display: block; font-weight: 700; font-size: 1.1em; line-height: 1.3; }
    .aep-box .aep-price { display: block; margin-top: 4px; font-weight: 700; color: #1a7f37; }
    .aep-box .aep-price small { font-weight: 400; color: #555; }
    .aep-box .aep-cover { display: block; width: 100%; background: #f2f2f2; }
    .aep-box .aep-cover img { display: block; width: 100%; height: auto; }
    .aep-box .aep-cta {
        display: flex; align-items: center; justify-content: center; gap: 6px;
        padding: 13px 16px; background: #1a7f37; color: #fff; font-weight: 700; text-align: center;
    }
    .aep-box .aep-chevron { transition: transform 0.15s ease; }
    .aep-box[open] .aep-chevron { transform: rotate(180deg); }
    .aep-box summary.aep-summary:hover .aep-cta { background: #166b2f; }
    .aep-box .aep-body { padding: 18px; border-top: 1px solid #eee; }
    .aep-box .aep-delivery { color: #555; font-size: 0.9em; margin-top: 0; }
    .aep-box .aep-error { background: #fdecea; color: #91271c; padding: 10px 14px; border-radius: 6px; }
    .aep-box .aep-field label { display: block; font-weight: 600; margin-bottom: 4px; }
    .aep-box .aep-field input { width: 100%; padding: 8px 10px; border: 1px solid #bbb; border-radius: 6px; box-sizing: border-box; }
    .aep-box .aep-field small { color: #666; }
    .aep-box .aep-hp { position: absolute; left: -9999px; }
    .aep-box .aep-terms { font-size: 0.72em; line-height: 1.4; color: #777; }
    .aep-box .aep-terms-text p { display: inline; margin: 0; }
    .aep-box .aep-button { width: 100%; padding: 14px; font-size: 1.05em; font-weight: 700; color: #fff; background: #1a7f37; border: 0; border-radius: 8px; cursor: pointer; }
    .aep-box .aep-button:hover { background: #166b2f; }
</style>
