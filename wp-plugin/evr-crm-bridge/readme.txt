=== EVR CRM Bridge ===
Contributors: evr
Tags: woocommerce, crm, api, inscripciones
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conecta EVR Event Registration con el CRM 360 mediante una API REST protegida con clave.

== Description ==

Complemento para sitios que usan WooCommerce y el plugin EVR Event Registration. Expone una API REST protegida con clave para que el CRM 360 descargue las actividades, los pedidos, los participantes y sus inscripciones.

* Las rutas de lectura no modifican nada (compatible con HPOS).
* Las rutas de escritura usan los servicios de EVR (cupos, transacciones e historial), firmadas por el usuario elegido en Ajustes › CRM 360. Sin ese usuario, el CRM solo puede leer.
* Todas las rutas requieren la clave de API.

Rutas disponibles (prefijo `/wp-json/evr-crm/v1`):

* `GET /ping` — comprueba la conexión.
* `GET /activities` — lista de actividades, incluidas las archivadas.
* `GET /orders` — pedidos con participantes y actividades, paginados por cursor (`after_updated`, `after_order`, `created_after`, `limit`).
* `POST /participants/{id}` — nombre, correo, WhatsApp, camiseta, alimentación, alergias y entidad.
* `POST /assignments/{id}/replace` y `/cancel` — cambiar o retirar una actividad, como "Editar" en Inscritos.
* `GET /desk/activities`, `POST /desk/preview` y `/desk/register` — un registro nuevo, como "Nuevo Registro".

La clave se envía en la cabecera `Authorization: Bearer <clave>` o en `X-CRM-Key: <clave>` (algunos servidores Apache o LiteSpeed eliminan la cabecera Authorization).

== Installation ==

1. En WordPress, ve a Plugins › Añadir nuevo › Subir plugin y selecciona el archivo `evr-crm-bridge.zip`.
2. Pulsa "Instalar ahora" y luego "Activar". Al activarlo se genera automáticamente una clave de API.
3. Ve a Ajustes › CRM 360.
4. Copia la "URL de la API" y la "Clave de API".
5. En el CRM, abre Configuración › Plugin de inscripciones, pega la URL y la clave, y guarda.

== Frequently Asked Questions ==

= ¿Qué pasa si regenero la clave? =

La clave anterior deja de funcionar de inmediato. Pega la nueva clave en el CRM para que siga sincronizando.

= El CRM responde "no autorizado" aunque la clave es correcta =

Es probable que el servidor elimine la cabecera Authorization. El CRM puede enviar la clave en la cabecera `X-CRM-Key`.

== Changelog ==

= 1.1.0 =
* Rutas de escritura para la mesa de registro del CRM y elección del usuario que firma los cambios.
* El nombre de la regla de descuento de cada actividad viaja en `/orders`.

= 1.0.0 =
* Versión inicial: rutas /ping, /activities y /orders, página Ajustes › CRM 360.
