# PowerTranz para WooCommerce

Pasarela de pagos **First Atlantic Commerce (PowerTranz)** para WooCommerce con
autenticación **3-D Secure EMV 2.x** mediante integración simplificada (SPI),
**Apple Pay en la Web** por método directo de integración y **cuotas BAC
Credomatic**.

- **Autor:** Jonathan Diaz — [www.soydiaz.com](https://www.soydiaz.com)
- **Versión:** 1.0.0
- **Requiere:** WordPress 6.0+, WooCommerce 7.0+, PHP 7.4+ con OpenSSL
- **Licencia:** GPL-2.0-or-later

---

## Qué incluye

Dos pasarelas independientes que se activan por separado:

| Pasarela | Id | Endpoints usados |
|---|---|---|
| PowerTranz (tarjetas) | `powertranz_cc` | `/api/spi/auth`, `/api/spi/sale`, `/api/spi/payment`, `/api/auth`, `/api/sale`, `/api/capture`, `/api/void`, `/api/refund` |
| PowerTranz Apple Pay | `powertranz_applepay` | `/api/sale`, `/api/auth`, `/api/capture`, `/api/void`, `/api/refund` |

Entornos:

| Entorno | API root |
|---|---|
| Pruebas | `https://staging.ptranz.com/api` |
| Producción | `https://gateway.ptranz.com/api` |

Se puede sobrescribir con el filtro `powertranz_api_base_url`.

---

## Flujo 3-D Secure (SPI)

El módulo implementa exactamente el flujo de dos llamadas descrito por PowerTranz:

1. **`/api/spi/sale`** (o `/api/spi/auth` en modo preautorización) con
   `ThreeDSecure: true`, los datos de tarjeta, el domicilio de facturación y
   `ExtendedData.MerchantResponseUrl`.
   → PowerTranz responde `IsoResponseCode: SP4` con `RedirectData` y `SpiToken`.
2. El `RedirectData` se entrega al navegador en un **iFrame con `srcdoc`** desde
   una página intermedia propia. PowerTranz resuelve internamente la huella
   digital del dispositivo y el desafío del emisor.
3. PowerTranz hace **POST al `MerchantResponseUrl`** con `SpiToken` y `Response`
   (el resultado de autenticación, normalmente `3D0`).
4. Según la política configurada, el módulo llama a **`/api/spi/payment`** con el
   token SPI entre comillas y cierra el pedido.

### Escenarios cubiertos

| Escenario | Respuesta | Comportamiento |
|---|---|---|
| Sin fricción, autenticado | `3D0` + estatus `Y` | Se finaliza el cobro |
| Sin fricción con huella digital | `3D0` + estatus `Y`/`A` | Se finaliza el cobro |
| Con desafío del emisor | `3D0` tras el reto | Se finaliza el cobro |
| Autenticación intentada | `3D0` + estatus `A` | Se finaliza; conserva protección |
| No autenticado | `3D0` + estatus `N` | No se finaliza; pedido fallido |
| Rechazada por el emisor | `3D0` + estatus `R` | No se finaliza; pedido fallido |
| Autenticación no disponible | `3D0` + estatus `U` | Bloqueado por defecto; se puede permitir sin protección |
| Tarjeta sin soporte 3DS 2.x | `3D1` | Se cobra sin autenticación (configurable) |
| Error de 3DS | `3D3` | Pedido fallido con el detalle en la nota |
| 3-D Secure desactivado | — | Va directo a `/api/sale` o `/api/auth` |
| Token SPI expirado (>5 min) | — | Pedido fallido y aviso al cliente para reintentar |
| Timeout o caída de red | — | Se registra el error y el pedido no se marca pagado |
| Respuesta duplicada | — | Idempotente: si el pedido ya se resolvió, solo redirige |

### Política de finalización del cobro

FAC define qué permite la pasarela y qué protege al comercio, y es explícito en
que **`AuthenticationStatus` tiene prioridad sobre el campo ECI**:

| Estatus | Significado | PowerTranz permite cobrar | Protección vs. contracargos |
|---|---|---|---|
| `Y` | Autenticación exitosa | Sí | **Sí** |
| `A` | Intento de autenticación | Sí | **Sí** |
| `U` | Falló por problema técnico | Sí | No |
| `N` | Autenticación fallida, cuenta no verificada | No | No |
| `R` | El emisor rechazó el intento | No | No |

El ajuste ofrece tres políticas:

- **Solo con protección de contracargos: `Y` o `A`** (por defecto y
  recomendado). Es la única que nunca deja al comercio expuesto.
- **Todo lo que PowerTranz permite: `Y`, `A` o `U`.** Cobra más, pero con `U` el
  comercio asume el riesgo. FAC lo dice literalmente: quien completa el pago con
  `U` lo hace bajo su responsabilidad. Revíselo con BAC antes de activarlo.
- **Intentar siempre**, incluso `N` y `R`. Solo para diagnóstico: PowerTranz los
  rechazará de todos modos.

Con `N` y `R` el módulo **no llama a `/spi/payment`**, porque la pasarela no lo
permite y sería una llamada inútil.

El módulo deja constancia en el pedido de dos situaciones que conviene vigilar:
cuando se cobra sin protección (estatus `U`), y cuando el estatus dice
autenticado pero el ECI o el CAVV no lo respaldan — señal de un posible problema
de configuración del adquirente.

Si el emisor devuelve `CardholderInfo`, ese mensaje se muestra al cliente y se
guarda como nota del pedido, tal como exige la documentación.

---

## Registro de lo realizado

**En el pedido**, una nota por cada llamada al API con código ISO, mensaje,
identificador de transacción, código de autorización, RRN, estatus 3DS, ECI,
versión del protocolo, traslado de responsabilidad, AVS, CVV y entorno.

**Caja «PowerTranz: registro de transacciones»** en la pantalla del pedido, con
un resumen y una tabla del historial completo de pasos.

**Metadatos** del pedido: `_powertranz_transaction_id`, `_powertranz_auth_code`,
`_powertranz_rrn`, `_powertranz_iso_code`, `_powertranz_card_brand`,
`_powertranz_card_last4`, `_powertranz_3ds_status`, `_powertranz_3ds_eci`,
`_powertranz_3ds_version`, `_powertranz_3ds_ds_trans_id`,
`_powertranz_3ds_liability_shift`, `_powertranz_pan_token`,
`_powertranz_captured`, `_powertranz_installments`, `_powertranz_audit`, …

**Logs de WooCommerce** en *WooCommerce → Estado → Logs*, archivo de origen
`powertranz`. Cada transacción se registra siempre; con el modo depuración
activo se añaden los cuerpos JSON completos. El PAN se enmascara (6+4), el CVV,
las contraseñas y las claves se sustituyen, y los tokens, criptogramas y datos
cifrados se truncan. **El PAN completo y el CVV nunca se guardan** en la base de
datos ni en los logs.

---

## Cuotas BAC Credomatic

La API de PowerTranz no expone un campo de cuotas. En BAC Credomatic cada plan
se transacciona con un **PowerTranz Id y contraseña distintos** asignados por el
adquirente, que es exactamente lo que implementa el módulo.

En *Cuotas BAC Credomatic* se configura una tabla con una fila por plan:

| Columna | Uso |
|---|---|
| Cuotas | Número de cuotas (2 o más). Define el id interno del plan |
| Etiqueta visible | Texto del selector en el checkout |
| PowerTranz Id / Password (pruebas) | Credenciales del plan en staging |
| PowerTranz Id / Password (producción) | Credenciales del plan en producción |
| Importe mínimo | El plan se oculta por debajo de ese total |
| BINs permitidos | Prefijos separados por comas; vacío acepta cualquier tarjeta |
| Campos extra (JSON) | Se fusiona en la solicitud si el adquirente pide datos adicionales |

El **pago único** usa siempre las credenciales principales de la pasarela.

El plan elegido se guarda en el pedido (`_powertranz_installment_plan_id`) y las
**capturas, anulaciones y reembolsos se envían con las credenciales de ese mismo
plan**, no con las principales.

Si su adquirente además requiere un campo en la solicitud, se añade por plan sin
tocar código; por ejemplo:

```json
{"ExtendedData":{"Installments":3}}
```

---

## Apple Pay en la Web

Se implementa el **método directo de integración**: el comercio usa su propia
cuenta de desarrollador Apple, su Merchant ID y sus certificados.

### Quién descifra el token (ajuste decisivo)

El certificado de procesamiento de cobros solo lo puede usar quien tenga su
clave privada, y eso depende de **quién generó el CSR**:

| Ajuste | Cuándo usarlo | Qué se envía a PowerTranz |
|---|---|---|
| **PowerTranz / BAC** (por defecto) | BAC generó el CSR y subió usted el certificado a Apple. La clave privada la tiene PowerTranz | Solo `Source.Wallet.ApplePay.Payment`, el token cifrado tal cual |
| **Este servidor** | Usted generó el CSR con `openssl` y conserva la clave privada | `Payment` + `Source.Wallet.ApplePay.DecryptedData` |

Si elige mal esta opción el pago falla, así que confírmelo con BAC. En el modo
por defecto **no hace falta el certificado de procesamiento**: solo el de
identidad del comercio, que se usa para validar la sesión con Apple.

El modo usado queda registrado en cada pedido, lo que ahorra ida y vuelta con
soporte cuando hay que diagnosticar un rechazo.

### Requisitos previos en Apple

1. Registrar un **Merchant ID** (estilo `merchant.com.midominio.tienda`).
2. Añadir el dominio al Merchant ID y **verificarlo**.
3. Generar el **certificado de identidad del comercio** (siempre necesario).
4. Generar el **certificado de procesamiento de cobros** (clave EC `prime256v1`).
   Si BAC le entrega el CSR, súbalo a Apple y descargue el certificado; la clave
   privada se queda en PowerTranz y no necesita los comandos siguientes.

```bash
openssl ecparam -genkey -name prime256v1 -out paymentProcessing.key
```

```bash
openssl req -new -sha256 -key paymentProcessing.key -out paymentProcessingCertReq.csr -subj /CN=www.midominio.com
```

Suba el CSR a Apple, descargue el `.cer` y conviértalo a PEM:

```bash
openssl x509 -inform DER -in paymentProcessingCert.cer -out paymentProcessingCert.pem
```

### Verificación de dominio

Pegue el contenido del archivo que descarga de Apple en el campo *Archivo de
asociación de dominio* y el módulo lo sirve en:

```
https://sudominio.com/.well-known/apple-developer-merchantid-domain-association
```

Si su servidor web ya publica ese archivo físicamente, deje el campo vacío.

### Flujo implementado

1. El botón solo se muestra si existe `window.ApplePaySession`, soporta la
   versión 3 y `canMakePayments()` devuelve `true`.
2. Al pulsarlo se piden los importes actuales por AJAX y se abre la hoja de pago.
3. `onvalidatemerchant` → el **servidor** hace la petición TLS mutua a la
   `validationURL` con el certificado de identidad del comercio. Se rechaza
   cualquier URL que no sea de `*.apple.com`.
4. `onpaymentauthorized` → en modo *Este servidor*, el token se descifra:
   ECDH P-256 contra la clave efímera, KDF de un paso de NIST SP 800-56A
   (`id-aes256-GCM` / `Apple` / SHA-256 del Merchant ID) y AES-256-GCM con IV de
   16 bytes en cero. Se acepta la clave efímera tanto en formato
   SubjectPublicKeyInfo como en punto de curva sin comprimir de 65 bytes.
   Además se comprueba que `publicKeyHash` corresponde al certificado
   configurado y que `transactionAmount` coincide con el total del pedido.
5. Se envía a `/api/sale` (o `/api/auth`) con `Source.Wallet.ApplePay.Payment`,
   `DecryptedData` solo si se descifró aquí, y `ThreeDSecure: false`: el
   criptograma del dispositivo cumple esa función.

Solo se soportan tokens **EC_v1**. `RSA_v1` (China UnionPay) devuelve un error
explícito.

El botón se dibuja con el elemento `<apple-pay-button>` del SDK oficial de Apple
(`applepay.cdn-apple.com/jsapi/v1/apple-pay-sdk.js`), con un respaldo en CSS si
el SDK no llega a cargar.

> Guarde los certificados **fuera de la raíz pública** del sitio, con permisos
> restringidos al usuario de PHP.

---

## Preautorización, captura, anulación y reembolso

Las tres operaciones se ejecutan desde la pantalla del pedido, y son idénticas
para tarjetas y para Apple Pay. La caja **«PowerTranz: registro de
transacciones»** indica en cada pedido qué operaciones admite en su estado
actual y por dónde se hacen, así que no hace falta recordarlo.

### Modo venta vs. preautorización

- **Venta** (`/api/sale`): autoriza y marca la transacción para captura en un
  solo paso. El pedido pasa a pagado. No hay nada más que hacer.
- **Preautorización** (`/api/auth`): reserva fondos en la tarjeta. El pedido
  queda *En espera* (o *Procesando*) y el stock se reduce. **Sin captura no hay
  cobro**: la reserva caduca sola a los pocos días y el dinero nunca llega al
  comercio.

Úselo en modo preautorización si vende producto físico y quiere cobrar al
despachar; en modo venta si entrega al instante.

### Capturar

Cobra los fondos reservados. Solo aplica en modo preautorización.

1. Abra el pedido.
2. En el recuadro **Acciones del pedido** de la columna derecha, elija
   **«PowerTranz: capturar la autorización»**.
3. Pulse el botón de flecha.

El pedido pasa a pagado y la nota registra el importe capturado. PowerTranz
admite **capturas parciales**; para algunos adquirentes hay que anular después
de una captura parcial para cerrar la transacción — confírmelo con BAC.

También puede automatizarlo con el ajuste *Captura automática*, que captura en
el momento en que marca el pedido como **Completado**.

### Anular

Libera la reserva sin cobrar nada. Solo tiene sentido **antes** de la captura.

1. Abra el pedido.
2. En **Acciones del pedido**, elija **«PowerTranz: anular la autorización»**.
3. Pulse el botón de flecha.

El pedido pasa a cancelado. PowerTranz **no admite anulaciones parciales**: se
anula el importe completo.

### Reembolsar

Devuelve dinero ya cobrado. Se usa la pantalla nativa de WooCommerce.

1. Abra el pedido y baje a la lista de artículos.
2. Pulse **Reembolsar**.
3. Indique cantidades por línea o un importe en *Reembolso manual*.
4. Pulse **«Reembolsar … vía PowerTranz»** (no *Reembolso manual*, que solo
   anota el reembolso sin enviarlo a la pasarela).

Admite reembolsos parciales y varios sucesivos. Si la transacción **todavía no
se ha capturado** y pide un reembolso total, el módulo hace una **anulación** en
su lugar, que es lo correcto en ese estado.

### Cuotas

Las tres operaciones se envían con **las credenciales del plan con el que se
cobró el pedido**, no con las principales. El módulo lo resuelve solo a partir
del plan guardado en el pedido.

## Puesta en marcha con FAC y BAC

FAC exige un proceso de certificación en dos fases antes de producción:

1. **Pruebas en el entorno de desarrollo.** Solo con las tarjetas virtuales de
   FAC, incluyendo transacciones **aprobadas y denegadas de cada marca y en cada
   moneda** que maneje la tienda. El catálogo completo está en la pantalla de
   ajustes cuando el entorno es *Pruebas*.
2. **Pruebas en producción**, restringidas al horario laboral de Bermuda
   (lunes a viernes, 8:30–17:30 hora del Atlántico) y también con aprobadas y
   denegadas.

FAC no hace pasos a producción los viernes, fines de semana ni festivos de
Bermuda. Al terminar, FAC notifica a BAC Credomatic para el visto bueno final.

Notas de configuración de la cuenta:

- El `PowerTranz ID` y la contraseña van en los ajustes de la pasarela, en los
  campos del entorno correspondiente. **No se escriben en el código.**
- La cuenta se habilita para monedas concretas. Si la tienda cobra en una moneda
  que la cuenta no tiene habilitada, PowerTranz responderá con un error de
  moneda inválida (ISO 12 / código 315).
- La autenticación es 3DS versión 2 en Visa y Mastercard, y **SafeKey** en
  American Express. Confirme con BAC que su adquirente soporta SafeKey antes de
  ofrecer Amex.
- Solicite acceso al Portal del Comercio escribiendo a soporte de PowerTranz con
  dos cuentas de correo; ese portal permite verificar las transacciones de
  prueba contra lo que registra el módulo.

## Instalación

1. Copie la carpeta `powertranz-woocommerce` en `wp-content/plugins/`.
2. Actívela en *Plugins*.
3. Vaya a *WooCommerce → Ajustes → Pagos → PowerTranz (tarjetas)*.
4. Elija el entorno e introduzca el PowerTranz Id y la contraseña que le entregó
   el soporte de First Atlantic Commerce.
5. Para Apple Pay, active *PowerTranz Apple Pay* y complete los certificados.

Compatible con **HPOS** (tablas de pedidos personalizadas) y con el **checkout
por bloques**, además del checkout clásico.

---

## Tarjetas de prueba

El catálogo completo (32 tarjetas, con el escenario de cada una y **qué debe
ocurrir en el módulo**) se muestra dentro de *WooCommerce → Ajustes → Pagos →
PowerTranz (tarjetas)* siempre que el entorno sea *Pruebas*, y una versión
reducida aparece en el checkout. Así no hace falta salir de la pantalla donde se
está probando.

Notas comunes:

- La contraseña del desafío es `3ds2`.
- Cualquier caducidad futura y CVV de 3 dígitos (4 en American Express).
- **Varias de estas tarjetas no cumplen Luhn**, así que el módulo omite esa
  validación en pruebas y la aplica en producción.
- La marca debe estar marcada en *Tarjetas aceptadas* o el módulo la rechaza
  antes de llamar a PowerTranz.
- Para cuotas se usan estas mismas tarjetas: lo que cambia es el PowerTranz Id
  del plan, no la tarjeta.
- **Apple Pay no se prueba con estas tarjetas**: requiere una cuenta Sandbox
  Tester de Apple con una tarjeta de prueba en el Wallet, en un dispositivo
  Apple real. Apple no exige verificación de dominio en sandbox.

Aprobadas:

| Tarjeta | 3DS | Clave | Caso |
|---|---|---|---|
| 4012000000020071 | 2.x | — | Sin desafío, estatus Y |
| 4012000000020089 | 2.x | — | Sin desafío, estatus A |
| 4012000000020006 | 2.x | `3ds2` | Con desafío, estatus Y |
| 4012010000020070 | 2.x | — | Sin desafío, con huella digital, estatus Y |
| 5100270000000023 | 2.x | — | Mastercard sin desafío, estatus Y |
| 5100270000000031 | 2.x | `3ds2` | Mastercard con desafío, estatus Y |
| 341111000000009 | 2.x | — | Amex sin desafío, estatus Y |
| 4333333333332222 | no-3DS | — | Visa sin 3DS |
| 5333333333332222 | no-3DS | — | Mastercard sin 3DS |
| 6011111111111111 | no-3DS | — | Discover |
| 3528111111111108 | no-3DS | — | JCB |

Declinadas:

| Tarjeta | Caso |
|---|---|
| 4012000000020121 | Estatus N, no permite completar el pago |
| 5100270000000098 | Estatus N, no permite completar el pago |
| 5100270000000056 | Con desafío, estatus N |
| 5100270000000072 | Estatus R |
| 4666666666662222 | Estatus A, ISO 05, CVV = N |
| 5555666666662222 | Estatus U, ISO 05 |
| 4111111111119999 | Estatus A, ISO 98 |
| 5111111111110000 | Con desafío, estatus Y, ISO 91 |
| 341111000000029 | Amex, estatus N |
| 6011111111111152 | Discover rechazada |
| 3528111111111157 | JCB rechazada |

Los dos casos más instructivos: `4666666666662222` autentica bien (estatus A) y
**aun así el emisor rechaza** con ISO 05 y CVV = N; `4111111111110000` supera el
desafío y falla con ISO 91. Sirven para comprobar que el módulo distingue un
fallo de autenticación de un rechazo de autorización.

Confirme con PowerTranz si su adquirente soporta autenticación de American
Express: algunos solo tienen 3DS2 para Visa y Mastercard.

---

## Alcance PCI

El formulario de tarjeta se envía al servidor de la tienda, de modo que el
entorno entra en el **alcance SAQ D** de PCI DSS. Consulte
[Understanding SAQs for PCI DSS](https://www.pcisecuritystandards.org/documents/Understanding_SAQs_PCI_DSS_v3.pdf)
y confirme el cuestionario aplicable con su adquirente.

El módulo, por su parte:

- Exige TLS y avisa si el sitio no sirve HTTPS en producción.
- No persiste el PAN completo ni el CVV en ningún sitio.
- Enmascara y trunca todo dato sensible antes de escribir en los logs.
- No guarda los datos de tarjeta en la sesión ni en transients.

Descifrar tokens de Apple Pay también exige cumplimiento PCI. Ese es un motivo
más para dejar el descifrado en PowerTranz salvo que su adquirente le exija lo
contrario: en el modo por defecto la clave privada del certificado de
procesamiento nunca toca su servidor.

Si prefiere reducir el alcance a SAQ A, PowerTranz ofrece **SPI-HPP (página
alojada)**, que este módulo no implementa.

---

## Requisitos web de FAC

| Requisito | Cómo lo cumple el módulo |
|---|---|
| Logos de las marcas y de FAC en el formulario de pago | Debajo de los campos: los logos de las marcas activadas en los ajustes, más los sellos de BAC Credomatic, «Powered by First Atlantic Commerce» y 3-D Secure. Admite sustituirlo todo por la banda oficial de BAC |
| El CVV debe ser obligatorio | `required` en el campo y validación en el servidor, que es la que cuenta |
| No aceptar secuencias de ceros o nueves en el CVV | Rechazado en navegador y en servidor: `000`, `999`, `0000`, `9999` |
| El CVV debe mostrarse enmascarado | `type="password"`, en checkout clásico y en bloques |
| Contactos para los clientes dentro de la página | Teléfono, correo y horario configurables; se muestran en el formulario de pago, en la página de verificación 3DS y en la de confirmación |
| Redirección al validador 3-D Secure | `RedirectData` entregado en iFrame con `srcdoc` sobre una página intermedia (o a página completa), con salida del iFrame al volver |
| TLS 1.2 como mínimo | Se fija `CURL_SSLVERSION_TLSv1_2` en las llamadas a PowerTranz y a Apple. La versión de cURL y OpenSSL del servidor se muestra en los ajustes |
| Mensaje de Aprobado o Denegado al cliente | Panel de resultado en la página de confirmación con el estado, la autorización, la referencia y el importe. En el checkout, los rechazos se avisan con «Transacción denegada» |

### Sobre los logos

Solo se muestran las marcas marcadas en **Tarjetas aceptadas**; por defecto son
Visa, Mastercard y American Express. Si su cuenta de BAC está habilitada solo
para esas tres, el formulario muestra esas tres y nada más.

La lista de marcas seleccionables se limita a las cinco redes que **PowerTranz
enruta**: Visa, Mastercard, American Express, Discover y JCB. Diners Club,
UnionPay y Maestro no se ofrecen porque la pasarela no los procesa; si un
cliente introduce una de ellas, se le indica la marca concreta en lugar de un
error genérico.

Los logos incluidos de las marcas son reproducciones limpias y reconocibles.
Los de **BAC Credomatic** y **First Atlantic Commerce** son aproximaciones para
que el módulo funcione desde el primer momento. BAC y FAC entregan a cada
comercio una **banda única** con todas las marcas y los avales: cuando la tenga,
súbala a la Biblioteca de medios y pegue su URL en *Banda de logos del
adquirente*. Esa imagen sustituye a los logos individuales y a los sellos, y es
la opción recomendada para la certificación. También se pueden sustituir solo
los sellos, con *Logo del adquirente* y *Logo de la pasarela*.

Quedan fuera del módulo, porque son del sitio y no del método de pago: política
de devoluciones y de privacidad, términos y condiciones, moneda de cobro
visible, descripción de los productos y condiciones de entrega. Revíselos en el
documento *Website Requirements* de First Atlantic Commerce antes de pasar a
producción.

---

## Ganchos para desarrolladores

**Filtros**

| Filtro | Uso |
|---|---|
| `powertranz_api_base_url` | Cambiar el API root |
| `powertranz_api_request_args` | Modificar los argumentos HTTP |
| `powertranz_credentials` | Sobrescribir el Id y la contraseña de una transacción |
| `powertranz_numeric_currency` | Añadir o corregir el mapeo ISO 4217 |
| `powertranz_merchant_response_url` | Cambiar el `MerchantResponseUrl` |
| `powertranz_3ds_template` | Sustituir la página de desafío |
| `powertranz_available_installment_plans` | Filtrar los planes de cuotas ofrecidos |
| `powertranz_applepay_source` | Ajustar el objeto `Source` de Apple Pay |
| `powertranz_applepay_transaction_identifier` | Enviar un GUID propio en la llamada de billetera en lugar de dejar que PowerTranz lo asigne |
| `powertranz_skip_luhn_check` | Forzar o desactivar la validación Luhn |
| `powertranz_cc_icon` | Icono de la pasarela en el checkout |
| `powertranz_brand_logo_url` | Sustituir el logo de una marca de tarjeta |
| `powertranz_payment_seals` | Añadir, quitar o reordenar los sellos del formulario |
| `powertranz_supported_brands` | Ajustar las marcas ofrecidas si su cuenta enruta otras redes |

---

## Estructura

```
powertranz-woocommerce/
├── powertranz-woocommerce.php
├── uninstall.php
├── includes/
│   ├── class-wc-powertranz.php                      cargador
│   ├── class-wc-powertranz-api.php                  cliente HTTP
│   ├── class-wc-powertranz-response.php             lectura de respuestas
│   ├── class-wc-powertranz-helper.php               monedas, Luhn, ISO 8859, ECI
│   ├── class-wc-powertranz-logger.php               logs con redacción
│   ├── class-wc-powertranz-installments.php         cuotas BAC
│   ├── class-wc-powertranz-test-cards.php           catálogo de pruebas
│   ├── class-wc-powertranz-branding.php             logos, sellos y contacto
│   ├── abstract-class-wc-powertranz-gateway.php     base común
│   ├── class-wc-gateway-powertranz-cc.php           tarjetas + 3DS
│   ├── class-wc-gateway-powertranz-applepay.php     Apple Pay
│   ├── class-wc-powertranz-applepay-decryptor.php   descifrado EC_v1
│   ├── class-wc-powertranz-applepay-service.php     validación y dominio
│   ├── class-wc-powertranz-3ds-handler.php          desafío y respuesta
│   ├── class-wc-powertranz-order-admin.php          registro en el pedido
│   └── class-wc-powertranz-blocks-support.php       checkout por bloques
├── templates/3ds-challenge.php
└── assets/
    ├── images/cards/*.svg (visa, mastercard, amex, discover, jcb)
    ├── images/seals/*.svg (bac-credomatic, first-atlantic-commerce, threeds)
    ├── css/powertranz.css, powertranz-admin.css
    └── js/powertranz-checkout.js, powertranz-applepay-core.js,
           powertranz-applepay.js, powertranz-blocks.js, powertranz-admin.js
```
