<?php

namespace AnotherEcommerce\Delivery;

use AnotherEcommerce\Admin\SettingsPage;
use AnotherEcommerce\Orders\Order;
use AnotherEcommerce\Orders\OrderRepository;

/**
 * Sirve el PDF desde el directorio protegido validando el token temporal.
 * El archivo nunca tiene URL pública: solo sale por aquí, por streaming.
 */
final class DownloadController
{
    public function __construct(private OrderRepository $orders)
    {
    }

    public static function downloadUrl(Order $order): string
    {
        return add_query_arg([
            'aep-action' => 'download',
            'aep_order' => $order->id,
            'aep_token' => $order->download_token,
        ], home_url('/'));
    }

    public function handle(): void
    {
        $orderId = isset($_GET['aep_order']) ? absint($_GET['aep_order']) : 0;
        $token = isset($_GET['aep_token']) ? sanitize_text_field(wp_unslash($_GET['aep_token'])) : '';

        if ($orderId === 0 || $token === '') {
            $this->deny(404, 'Enlace de descarga inválido.');
        }

        $order = $this->orders->findById($orderId);

        if (
            $order === null
            || $order->status !== 'paid'
            || $order->download_token === ''
            || !hash_equals($order->download_token, $token)
        ) {
            $this->deny(403, 'Enlace de descarga inválido.');
        }

        if ($order->downloadExpired()) {
            $this->deny(410, 'Este enlace de descarga expiró. Escríbenos para reenviarte uno nuevo.');
        }

        $maxDownloads = max(1, (int) SettingsPage::get('max_downloads'));
        if ($order->downloads >= $maxDownloads) {
            $this->deny(410, 'Alcanzaste el límite de descargas de este enlace. Escríbenos si necesitas ayuda.');
        }

        $file = self::pdfPath($order);
        if ($file === null) {
            $this->deny(500, 'El archivo no está disponible en este momento. Escríbenos para solucionarlo.');
        }

        $this->orders->incrementDownloads($order->id);
        $this->stream($file, $order);
    }

    /**
     * Ruta absoluta del PDF de la orden (el nombre de archivo que quedó
     * guardado en la orden al comprar, no el del producto en vivo) dentro
     * del directorio protegido, o null si no existe. basename() evita
     * cualquier path traversal.
     */
    public static function pdfPath(Order $order): ?string
    {
        return self::resolveFile($order->pdf_file);
    }

    public static function resolveFile(string $filename): ?string
    {
        $filename = basename($filename);
        if ($filename === '') {
            return null;
        }

        $path = trailingslashit(aep_protected_dir()) . $filename;

        return is_readable($path) ? $path : null;
    }

    private function stream(string $file, Order $order): void
    {
        $downloadName = sanitize_file_name($order->product_name);
        $downloadName = ($downloadName !== '' ? $downloadName : 'documento') . '.pdf';

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string) filesize($file));
        header('X-Robots-Tag: noindex, nofollow');

        // Streaming en trozos para no cargar el PDF completo en memoria.
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            $this->deny(500, 'No se pudo leer el archivo.');
        }

        while (!feof($handle)) {
            $chunk = fread($handle, 1024 * 512);
            if ($chunk === false) {
                break;
            }
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput -- binario, no HTML.
            flush();
        }

        fclose($handle);
        exit;
    }

    private function deny(int $code, string $message): void
    {
        status_header($code);
        wp_die(esc_html($message), esc_html__('Descarga no disponible', 'another-ecommerce-plugin'), ['response' => $code]);
    }
}
