<?php

namespace AnotherEcommerce\Admin;

use AnotherEcommerce\Delivery\DownloadController;
use AnotherEcommerce\Products\ProductRepository;

/**
 * Administración de productos: cada uno es un PDF independiente con su
 * propio precio, vendido con su propio botón [aep_comprar id="N"].
 */
final class ProductsPage
{
    public const CAPABILITY = SettingsPage::CAPABILITY;
    public const SLUG = 'aep-products';

    public function __construct(private ProductRepository $products)
    {
    }

    public function registerMenu(): void
    {
        add_submenu_page(SettingsPage::MENU_SLUG, 'Productos', 'Productos', self::CAPABILITY, self::SLUG, [$this, 'render']);
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Sin permisos suficientes.');
        }

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($action === 'new' || $action === 'edit') {
            $id = $action === 'edit' ? absint($_GET['id'] ?? 0) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $this->renderForm($id);

            return;
        }

        $this->renderList();
    }

    private function renderList(): void
    {
        $products = $this->products->all();
        ?>
        <div class="wrap">
            <h1>
                Venta PDF — Productos
                <a href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'action' => 'new'], admin_url('admin.php'))); ?>" class="page-title-action">Añadir nuevo</a>
            </h1>

            <?php if (isset($_GET['aep_saved'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p>Producto guardado.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['aep_deleted'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p>Producto eliminado. El PDF se conserva en el servidor para que las compras ya pagadas sigan pudiendo descargarlo.</p></div>
            <?php endif; ?>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Precio</th>
                        <th>Shortcode</th>
                        <th>PDF</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($products === []) : ?>
                    <tr><td colspan="6">Todavía no has creado ningún producto. Usa «Añadir nuevo» para subir tu primer PDF.</td></tr>
                <?php endif; ?>
                <?php foreach ($products as $product) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($product->name); ?></strong></td>
                        <td>$<?php echo esc_html(number_format($product->price, 0, ',', '.')); ?></td>
                        <td><code>[aep_comprar id="<?php echo esc_html((string) $product->id); ?>"]</code></td>
                        <td>
                            <?php if (DownloadController::resolveFile($product->pdf_file) !== null) : ?>
                                <?php echo esc_html($product->pdf_file); ?>
                            <?php else : ?>
                                <span style="color:#b32d2e;">falta el archivo</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $product->active ? 'Activo' : 'Inactivo'; ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'action' => 'edit', 'id' => $product->id], admin_url('admin.php'))); ?>">Editar</a>
                            <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(
                                admin_url('admin-post.php?action=aep_toggle_product&id=' . $product->id),
                                'aep_toggle_product_' . $product->id
                            )); ?>"><?php echo $product->active ? 'Desactivar' : 'Activar'; ?></a>
                            <a class="button button-small" style="color:#b32d2e;" href="<?php echo esc_url(wp_nonce_url(
                                admin_url('admin-post.php?action=aep_delete_product&id=' . $product->id),
                                'aep_delete_product_' . $product->id
                            )); ?>" onclick="return confirm('¿Eliminar este producto? Las compras ya realizadas seguirán funcionando, pero el producto dejará de venderse.');">Eliminar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function renderForm(int $id): void
    {
        $product = $id > 0 ? $this->products->findById($id) : null;
        if ($id > 0 && $product === null) {
            wp_die('Producto no encontrado.');
        }

        $error = isset($_GET['aep_error']) ? sanitize_key(wp_unslash($_GET['aep_error'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $errorMessage = match ($error) {
            'invalid' => 'Ingresa un nombre y un precio mayor a 0.',
            'pdf' => 'No se pudo subir el PDF. Verifica que sea un archivo .pdf válido.',
            'nopdf' => 'Debes subir un PDF para este producto.',
            default => '',
        };

        $currentFile = $product !== null ? DownloadController::resolveFile($product->pdf_file) : null;
        ?>
        <div class="wrap">
            <h1><?php echo $product !== null ? 'Editar producto' : 'Nuevo producto'; ?></h1>

            <?php if ($errorMessage !== '') : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($errorMessage); ?></p></div>
            <?php endif; ?>

            <?php if ($product !== null) : ?>
                <p>Shortcode: <code>[aep_comprar id="<?php echo esc_html((string) $product->id); ?>"]</code></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="aep_save_product">
                <input type="hidden" name="aep_id" value="<?php echo esc_attr((string) ($product->id ?? 0)); ?>">
                <?php wp_nonce_field('aep_save_product'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="aep-name">Nombre del producto</label></th>
                        <td><input type="text" id="aep-name" class="regular-text" name="aep_name" required maxlength="190" value="<?php echo esc_attr($product->name ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aep-price">Precio (CLP)</label></th>
                        <td>
                            <input type="number" id="aep-price" min="1" step="1" name="aep_price" required value="<?php echo esc_attr((string) ($product->price ?? '')); ?>">
                            <p class="description">Pesos chilenos, sin decimales ni puntos.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aep-active">Estado</label></th>
                        <td><label><input type="checkbox" id="aep-active" name="aep_active" value="1" <?php checked($product === null || $product->active); ?>> Activo (visible y comprable)</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aep-pdf">Archivo PDF</label></th>
                        <td>
                            <?php if ($currentFile !== null) : ?>
                                <p>Archivo actual: <code><?php echo esc_html(basename($currentFile)); ?></code> (<?php echo esc_html(size_format((int) filesize($currentFile))); ?>).</p>
                            <?php elseif ($product !== null) : ?>
                                <p style="color:#b32d2e;">El archivo configurado no se encuentra en el directorio protegido.</p>
                            <?php endif; ?>
                            <input type="file" id="aep-pdf" name="aep_pdf" accept="application/pdf" <?php echo $product === null ? 'required' : ''; ?>>
                            <p class="description">
                                <?php echo $product !== null ? 'Sube un archivo solo si quieres reemplazar el actual.' : ''; ?>
                                Se guarda en el directorio protegido; nunca queda accesible por una URL directa.
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button($product !== null ? 'Guardar cambios' : 'Crear producto'); ?>
            </form>
        </div>
        <?php
    }

    public function handleSave(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Sin permisos suficientes.');
        }
        check_admin_referer('aep_save_product');

        $id = isset($_POST['aep_id']) ? absint($_POST['aep_id']) : 0;
        $name = isset($_POST['aep_name']) ? sanitize_text_field(wp_unslash($_POST['aep_name'])) : '';
        $price = isset($_POST['aep_price']) ? max(0, absint($_POST['aep_price'])) : 0;
        $active = !empty($_POST['aep_active']);

        $formUrl = add_query_arg(
            ['page' => self::SLUG, 'action' => $id > 0 ? 'edit' : 'new', 'id' => $id],
            admin_url('admin.php')
        );

        if ($name === '' || $price <= 0) {
            wp_safe_redirect(add_query_arg('aep_error', 'invalid', $formUrl));
            exit;
        }

        $existing = $id > 0 ? $this->products->findById($id) : null;
        if ($id > 0 && $existing === null) {
            wp_die('Producto no encontrado.');
        }

        $pdfFile = $existing->pdf_file ?? '';

        if (!empty($_FILES['aep_pdf']['name'])) {
            if (!function_exists('wp_handle_upload')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            // Redirige la subida al directorio protegido en vez de uploads público.
            $toProtected = static function (array $dirs): array {
                $dirs['path'] = aep_protected_dir();
                $dirs['url'] = '';
                $dirs['subdir'] = '';

                return $dirs;
            };

            add_filter('upload_dir', $toProtected);
            $result = wp_handle_upload($_FILES['aep_pdf'], [
                'test_form' => false,
                'mimes' => ['pdf' => 'application/pdf'],
            ]);
            remove_filter('upload_dir', $toProtected);

            if (!isset($result['file']) || isset($result['error'])) {
                wp_safe_redirect(add_query_arg('aep_error', 'pdf', $formUrl));
                exit;
            }

            // OJO: no se borra el PDF anterior. Las órdenes ya pagadas de este
            // producto guardan el nombre de archivo con el que compraron
            // (snapshot) y deben seguir pudiendo descargarlo.
            $pdfFile = basename($result['file']);
        }

        if ($pdfFile === '') {
            wp_safe_redirect(add_query_arg('aep_error', 'nopdf', $formUrl));
            exit;
        }

        if ($id > 0) {
            $this->products->update($id, [
                'name' => $name,
                'price' => $price,
                'pdf_file' => $pdfFile,
                'active' => $active ? 1 : 0,
            ]);
        } else {
            $id = $this->products->create($name, $price, $pdfFile, $active);
        }

        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'aep_saved' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handleToggle(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Sin permisos suficientes.');
        }

        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer('aep_toggle_product_' . $id);

        $product = $this->products->findById($id);
        if ($product !== null) {
            $this->products->update($id, ['active' => $product->active ? 0 : 1]);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    public function handleDelete(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Sin permisos suficientes.');
        }

        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer('aep_delete_product_' . $id);

        $this->products->delete($id);

        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'aep_deleted' => '1'], admin_url('admin.php')));
        exit;
    }
}
