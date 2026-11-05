# Another Ecommerce Plugin

Plugin de WordPress para vender **PDFs** con botón **"Comprar ahora"**: sin
carrito, sin registro de usuarios, pago con **Webpay Plus (Transbank)** en
CLP y, opcionalmente, **Stripe**/**Lemon Squeezy** en USD para clientes
internacionales, con entrega por correo mediante un enlace de descarga
temporal. Sin reembolsos (el cliente acepta términos con renuncia expresa al
retracto antes de pagar).

Puedes vender **varios productos**: cada uno es un PDF independiente, con su
propio nombre, precio y botón de compra propio (no hay carrito ni catálogo
compartido — cada shortcode vende un único artículo).

## Instalación

1. Copia el plugin a `wp-content/plugins/another-ecommerce-plugin/`.
2. Dentro del directorio del plugin ejecuta:

   ```bash
   composer install --no-dev
   ```

   Esto instala el SDK oficial de Transbank. Si falta, el admin muestra un aviso.
3. Activa el plugin en **Plugins** (crea las tablas `wp_aep_orders` y
   `wp_aep_products`, y el directorio protegido `wp-content/uploads/aep-protected/`).
4. En **Venta PDF → Productos**: crea un producto (nombre, precio en CLP,
   sube el PDF y, si quieres, una imagen de portada). Repite por cada PDF
   que quieras vender.
5. En **Venta PDF → Ajustes** revisa el ambiente de Webpay, el SMTP, los
   textos del correo y los términos (aplican a todos los productos).
6. Pon el shortcode que aparece junto a cada producto —
   `[aep_comprar id="N"]` — en la página donde quieras ese botón de compra.
   Puedes poner varios shortcodes (uno por producto) en la misma página.

## Ajustes por pestañas

La pantalla **Venta PDF → Ajustes** se organiza en pestañas, cada una con su
propio botón "Guardar" (guardar una pestaña no afecta a las demás):

- **Medios de pago**: Webpay (ambiente integración/producción, código de
  comercio y API Key), y Stripe/Lemon Squeezy (ver
  [Venta internacional](#venta-internacional-usd-stripe-y-lemon-squeezy) más
  abajo) — los tres juntos en una sola pantalla.
- **Entrega**: vigencia del enlace de descarga (horas) y máximo de descargas
  por compra.
- **SMTP**: servidor, puerto, cifrado, usuario/contraseña y remitente para
  enviar los correos por SMTP en vez de la función `mail()` de PHP (más
  confiable, menos spam). Incluye un botón para enviar un correo de prueba.
- **Correo**: asunto y cuerpo del correo de entrega (con variables como
  `{producto}`, `{enlace}`, `{nombre}`). El cuerpo se edita con el mismo
  editor enriquecido de WordPress (negrita, cursiva, tamaño de fuente, etc.).
- **Términos**: el texto de renuncia al retracto que el cliente acepta antes
  de pagar, con el mismo editor enriquecido.

## Probar sin tener aún el contrato de Webpay

Con el ambiente en **Integración** (valor por defecto) se usan las credenciales
públicas de prueba de Transbank: el flujo completo funciona sin cobrar dinero
real. Tarjeta de prueba: VISA `4051 8856 0044 6623`, CVV `123`, cualquier
fecha de expiración; en el paso de autenticación usa RUT `11.111.111-1` y
clave `123`.

Cuando Transbank te entregue el código de comercio productivo, ingrésalo junto
a la API Key en **Ajustes → Medios de pago** y cambia el ambiente a **Producción**.

## La tarjeta de compra

Cada shortcode se ve como una tarjeta compacta (portada + nombre + precio +
botón "Comprar ahora") pensada para no ocupar espacio de más en la página:
al hacer clic se despliega el formulario completo (nombre, correo,
términos). No usa JavaScript — es un `<details>` nativo del navegador, así
que funciona igual en cualquier tema. Si el producto no tiene imagen, se
muestra un ícono de documento como marcador de posición. Si el cliente
comete un error al enviar el formulario (correo inválido, etc.), la tarjeta
se abre sola mostrando el mensaje, en vez de dejarlo cerrado y oculto.

## Flujo

1. El cliente completa nombre + correo (con confirmación) y acepta los términos
   en el formulario del producto elegido.
2. Se crea la orden (`pending`) copiando el nombre, precio y PDF del producto
   *en ese momento* (si luego editas el producto, las órdenes ya creadas no
   cambian), y se redirige al cliente a Webpay.
3. Al volver de Webpay se confirma el pago (`commit`, idempotente ante
   refresh/doble clic) y la orden queda `paid`, `failed` o `aborted`.
4. Si el pago fue aprobado: se genera un enlace de descarga con token,
   vencimiento (72 h por defecto) y límite de descargas (5 por defecto), se
   envía por correo y se muestra también en la página de éxito.
5. El PDF se sirve por streaming desde el directorio protegido; nunca tiene
   URL pública.

En **Venta PDF → Ventas** se listan las órdenes (producto comprado, código de
autorización y últimos 4 dígitos de la tarjeta) y se puede **reenviar el
correo** con un enlace nuevo si al cliente se le venció.

### Editar o eliminar un producto no rompe compras anteriores

Cada orden guarda su propia copia del nombre, precio y nombre de archivo del
producto al momento de la compra. Por eso:

- Subir un PDF nuevo para reemplazar el de un producto **no borra el
  archivo anterior**: los compradores previos siguen pudiendo descargar la
  versión que compraron.
- **Eliminar** un producto solo quita la fila de la lista (y evita nuevas
  compras); el PDF permanece en el servidor para no romper enlaces de
  descarga ya entregados. Usa **Desactivar** si solo quieres dejar de vender
  el producto sin eliminarlo.

## Venta internacional (USD): Stripe y Lemon Squeezy

Webpay Plus solo liquida en CLP y es exclusivo del mercado chileno: no sirve
para cobrarle a clientes internacionales en dólares. Para eso el plugin
también puede cobrar con **Stripe** y/o **Lemon Squeezy**, ofrecidos junto a
Webpay (no en reemplazo) — el cliente elige el medio de pago en el
formulario de compra si el producto tiene precio en USD.

1. En cada producto (**Venta PDF → Productos**) fija, además del precio en
   CLP, un **Precio USD** (ej. `9.99`). Sin precio USD, ese producto no
   ofrece Stripe ni Lemon Squeezy como opción, solo Webpay.
2. En **Venta PDF → Ajustes → Medios de pago** activa el/los proveedores que
   quieras usar y completa sus credenciales:
   - **Stripe**: Secret Key (`sk_test_…` para pruebas, `sk_live_…` en
     producción) y el "Signing secret" del webhook que crees apuntando a la
     URL que la propia pestaña de ajustes te muestra, escuchando
     `checkout.session.completed`.
   - **Lemon Squeezy**: API Key, Store ID, y el ID de **una** variante que
     debes crear de antemano en tu tienda de Lemon Squeezy (el checkout se
     genera contra esa variante y el precio se sobreescribe por compra). El
     "Signing secret" del webhook que crees apuntando a la URL que te
     muestra la pestaña, escuchando `order_created`.
3. Con eso, el formulario de compra de cualquier producto con precio USD
   muestra un selector de medio de pago (Webpay CLP / Stripe USD / Lemon
   Squeezy USD).

**Confirmación de pago**: Stripe se confirma de inmediato al volver del
checkout (y su webhook es la red de seguridad si el navegador nunca vuelve).
Lemon Squeezy no manda nada verificable en el retorno del navegador: depende
enteramente de su webhook, así que el cliente ve una pantalla de "confirmando
tu pago" que se refresca sola (sin JavaScript) hasta que el webhook llega —
normalmente unos segundos.

**Probar sin tener las cuentas de producción**: usa el modo de pruebas de
ambos proveedores (Stripe test mode con tarjeta `4242 4242 4242 4242`, Lemon
Squeezy test mode) — funciona igual que el ambiente de Integración de
Webpay, sin cobrar dinero real.

**Nota para probar en local**: Stripe y Lemon Squeezy necesitan una URL
pública para mandar sus webhooks; `localhost`/`*.local` no sirve. Para
probar el flujo completo en un sitio local hace falta un túnel (ngrok,
Cloudflare Tunnel, etc.) apuntando al sitio, y configurar esa URL pública
como endpoint del webhook en ambos dashboards (en modo test). El retorno
normal del navegador sí funciona sin túnel.

## Notas de despliegue

- **Correo**: configura la pestaña **Ajustes → SMTP** con los datos de tu
  proveedor (o un plugin SMTP externo si prefieres); `wp_mail()` con la
  función `mail()` de PHP suele caer en spam y el correo es tu único canal
  de entrega.
- **nginx**: el bloqueo del directorio protegido usa `.htaccess` (Apache). En
  nginx agrega a tu server block:

  ```nginx
  location ~* /uploads/aep-protected/ { deny all; }
  ```

- **Desinstalar** (no desactivar) borra opciones, ambas tablas y los PDF.
- **Actualizar el plugin**: al reemplazar los archivos, el propio plugin
  detecta en la siguiente visita si la versión instalada cambió y aplica
  los ajustes de base de datos que falten (columnas o tablas nuevas) sin
  necesitar desactivar/reactivar.
