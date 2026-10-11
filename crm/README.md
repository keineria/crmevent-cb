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
| Contactos: importador de Excel (asistentes e interesados) y suscriptores de la web | Siguiente |
| EVR Bridge: sincronización con el plugin | Pendiente |

Las variables `MAIL_*` del `.env` solo cubren correos del sistema (por ejemplo, recuperar contraseña). Los correos a participantes usan la cuenta configurada en **Configuración › Correo de envíos**, guardada cifrada con `APP_KEY`.
