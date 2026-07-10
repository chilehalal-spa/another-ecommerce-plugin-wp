# Another Ecommerce Plugin

Plugin de WordPress para vender **un único PDF** con botón **"Comprar ahora"**:
sin carrito, sin registro de usuarios, pago con **Webpay Plus (Transbank)** y
entrega por correo con un enlace de descarga temporal. Sin reembolsos (el
cliente acepta términos con renuncia expresa al retracto antes de pagar).

## Instalación

1. Copia el plugin a `wp-content/plugins/another-ecommerce-plugin/`.
2. Dentro del directorio del plugin ejecuta:

   ```bash
   composer install --no-dev
   ```

   Esto instala el SDK oficial de Transbank. Si falta, el admin muestra un aviso.
3. Activa el plugin en **Plugins** (crea la tabla `wp_aep_orders` y el
   directorio protegido `wp-content/uploads/aep-protected/`).
4. En **Venta PDF → Ajustes**: define nombre del producto, precio en CLP,
   sube el PDF y revisa los textos del correo y de los términos.
5. Pon el shortcode `[aep_comprar]` en la página donde quieras vender.

## Probar sin tener aún el contrato de Webpay

Con el ambiente en **Integración** (valor por defecto) se usan las credenciales
públicas de prueba de Transbank: el flujo completo funciona sin cobrar dinero
real. Tarjeta de prueba: VISA `4051 8856 0044 6623`, CVV `123`, cualquier
fecha de expiración; en el paso de autenticación usa RUT `11.111.111-1` y
clave `123`.

Cuando Transbank te entregue el código de comercio productivo, ingrésalo junto
a la API Key en los ajustes y cambia el ambiente a **Producción**.

## Flujo

1. El cliente completa nombre + correo (con confirmación) y acepta los términos.
2. Se crea la orden (`pending`) y se le redirige a Webpay.
3. Al volver de Webpay se confirma el pago (`commit`, idempotente ante
   refresh/doble clic) y la orden queda `paid`, `failed` o `aborted`.
4. Si el pago fue aprobado: se genera un enlace de descarga con token,
   vencimiento (72 h por defecto) y límite de descargas (5 por defecto), se
   envía por correo y se muestra también en la página de éxito.
5. El PDF se sirve por streaming desde el directorio protegido; nunca tiene
   URL pública.

En **Venta PDF → Ventas** se listan las órdenes (con código de autorización y
últimos 4 dígitos de la tarjeta) y se puede **reenviar el correo** con un
enlace nuevo si al cliente se le venció.

## Notas de despliegue

- **Correo**: configura un plugin SMTP (p. ej. WP Mail SMTP); `wp_mail()` con
  PHP `mail()` suele caer en spam y el correo es tu canal de entrega.
- **nginx**: el bloqueo del directorio protegido usa `.htaccess` (Apache). En
  nginx agrega a tu server block:

  ```nginx
  location ~* /uploads/aep-protected/ { deny all; }
  ```

- **Desinstalar** (no desactivar) borra opciones, tabla de órdenes y el PDF.
