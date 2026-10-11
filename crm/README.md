# CRM 360 · Colombia Birdfair

CRM de la feria, alimentado por el plugin EVR de WordPress (fuente de verdad de compras, cupos y precios).

- **Stack:** Laravel 13 (PHP 8.3), Inertia v3, React 19 + TypeScript, Tailwind 4, MariaDB.
- **Roles:** Admin, Admin registro, Registro, Registro salidas, Analista, Marketing (`app/Support/Roles.php`).
- **Despliegue:** VPS Hostinger con AlmaLinux 9 y CyberPanel 3.0, en un subdominio. Ver [docs/deploy-cyberpanel.md](docs/deploy-cyberpanel.md).

## Desarrollo local

```bash
composer install
npm install
cp .env.example .env            # para local basta DB_CONNECTION=sqlite
php artisan key:generate
php artisan migrate --seed       # crea las tablas y los roles
php artisan crm:create-admin tu@correo.com "Tu nombre"
composer run dev                 # servidor, cola y Vite
```

## Comprobaciones

```bash
php artisan test
vendor/bin/pint --test
npm run types && npm run lint && npm run format:check && npm run build
```

## Módulos

| Módulo | Estado |
| --- | --- |
| Acceso, roles y usuario administrador | Listo |
| Configuración › Correo de envíos (transaccional y difusión, prueba antes de activar, SPF/DKIM/DMARC) | Listo |
| Contactos: lista con filtros, importador de Excel/CSV (asistentes e interesados) | Listo |
| Suscriptores del sitio web: formulario con doble confirmación (`POST /api/v1/subscribe`) | Listo |
| Baja de difusiones: enlace y cabeceras `List-Unsubscribe` en todo `BroadcastMailable` | Listo |
| Campañas y plantillas | Pendiente |
| EVR Bridge: sincronización con el plugin | Pendiente |

Las variables `MAIL_*` del `.env` solo cubren correos del sistema (por ejemplo, recuperar contraseña). Los correos a participantes usan la cuenta configurada en **Configuración › Correo de envíos**, guardada cifrada con `APP_KEY`.

## Difusiones y consentimiento

- Toda difusión debe extender `App\Mail\BroadcastMailable` y enviarse con `App\Services\Mail\BroadcastSender`. El sender rechaza a quien no autorizó o se dio de baja; la clase añade el enlace de baja, el pie legal y las cabeceras de baja con un clic de Gmail y Outlook.
- Un contacto importado solo queda autorizado si se marca «Estas personas autorizaron recibir boletines» al importar. Quien se dio de baja nunca se reactiva por una importación.
- Los suscriptores del sitio quedan autorizados solo al confirmar desde el correo (doble confirmación, Ley 1581 de 2012).
