=== PowerTranz para WooCommerce ===
Contributors: jonathandiaz
Author: Jonathan Diaz
Author URI: https://www.soydiaz.com
Tags: woocommerce, powertranz, first atlantic commerce, 3d secure, apple pay, cuotas, bac credomatic
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.6
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pasarela First Atlantic Commerce (PowerTranz) con 3-D Secure EMV 2.x, Apple Pay y cuotas BAC Credomatic.

== Description ==

Acepta tarjetas de credito y debito a traves de la pasarela PowerTranz de First
Atlantic Commerce, con autenticacion 3-D Secure EMV 2.x mediante integracion
simplificada (SPI).

Caracteristicas:

* Dos metodos de pago independientes: tarjetas y Apple Pay.
* 3-D Secure 2.x completo: flujo sin friccion, con huella digital del
  dispositivo, con desafio del emisor y tarjetas sin soporte 3DS.
* Politica configurable de finalizacion del cobro segun el traslado de
  responsabilidad al emisor.
* Entornos de pruebas y produccion con credenciales separadas.
* Modo venta o preautorizacion con captura, anulacion y reembolsos totales y
  parciales desde WooCommerce.
* Cuotas BAC Credomatic: un PowerTranz Id por plan, con importe minimo y filtro
  por BIN.
* Apple Pay en la Web por metodo directo: validacion de comercio con TLS mutuo y
  descifrado EC_v1 del token en el servidor.
* Nota en el pedido y caja de registro de transacciones con codigo ISO, RRN,
  autorizacion, estatus 3DS, ECI y AVS/CVV.
* Registro completo en los logs de WooCommerce con redaccion de datos sensibles.
* Compatible con HPOS y con el checkout por bloques.

== Installation ==

1. Copie la carpeta powertranz-woocommerce en wp-content/plugins/.
2. Active el plugin.
3. Vaya a WooCommerce - Ajustes - Pagos - PowerTranz (tarjetas).
4. Elija el entorno e introduzca las credenciales que le entrego el soporte de
   First Atlantic Commerce.

== Frequently Asked Questions ==

= Donde veo el detalle de una transaccion? =

En la pantalla del pedido, en la caja "PowerTranz: registro de transacciones", y
en WooCommerce - Estado - Logs, archivo de origen powertranz.

= Como se configuran las cuotas de BAC? =

La API de PowerTranz no tiene un campo de cuotas: BAC asigna un PowerTranz Id y
contrasena distintos a cada plan. En la seccion "Cuotas BAC Credomatic" se
configura una fila por plan con esas credenciales.

= Guarda el numero de tarjeta? =

No. Nunca se persiste el PAN completo ni el CVV. En el pedido solo quedan la
marca, los cuatro ultimos digitos y la caducidad.

== Changelog ==

= 1.0.1 =
* El motivo del rechazo se guarda como aviso de WooCommerce al volver de 3-D Secure: se muestra en "Pagar pedido" o en el checkout si la tienda redirige ahi, una sola vez.

= 1.0.0 =
* Version inicial.
