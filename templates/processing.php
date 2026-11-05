<?php
/**
 * Página de espera standalone (fuera del tema, sin variables) mientras se
 * confirma un pago internacional cuya fuente de verdad es un webhook (Lemon
 * Squeezy) o que Stripe todavía no confirmó de forma síncrona. Se refresca
 * sola sin JavaScript: cada recarga vuelve a pasar por
 * InternationalReturnController, que redirige al éxito en cuanto el webhook
 * marque la orden como pagada.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="4">
<meta name="robots" content="noindex">
<title>Confirmando tu pago…</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; background: #f7f7f7; }
    .aep-wait { max-width: 420px; margin: 1em; padding: 28px; border: 1px solid #ddd; border-radius: 10px; background: #fff; text-align: center; }
    .aep-spinner { width: 28px; height: 28px; margin: 0 auto 16px; border: 3px solid #ddd; border-top-color: #1a7f37; border-radius: 50%; animation: aep-spin 0.8s linear infinite; }
    @keyframes aep-spin { to { transform: rotate(360deg); } }
    .aep-wait p { color: #333; }
    .aep-wait small { color: #777; }
</style>
</head>
<body>
    <div class="aep-wait">
        <div class="aep-spinner" aria-hidden="true"></div>
        <p>Estamos confirmando tu pago. Esta página se actualiza sola cada pocos segundos — no la cierres.</p>
        <p><small>Si el pago fue aprobado, de todas formas recibirás el enlace de descarga por correo aunque cierres esta pestaña.</small></p>
    </div>
</body>
</html>
