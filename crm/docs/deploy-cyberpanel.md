# Despliegue en Hostinger (AlmaLinux 9 + CyberPanel 3.0)

Guía para publicar el CRM en `crm.colombiabirdfair.com`. Los comandos de shell se ejecutan por SSH como `root` salvo que se indique otro usuario.

## 1. Sitio y PHP

1. En CyberPanel: **Websites › Create Website**, dominio `crm.colombiabirdfair.com`, PHP **8.3**. Marca SSL.
2. Si el subdominio no tiene SSL aún: **SSL › Manage SSL** y emite el certificado (el DNS del subdominio debe apuntar a la IP del VPS).
3. Extensiones que necesita Laravel:

   ```bash
   dnf install -y lsphp83-mysqlnd lsphp83-intl lsphp83-bcmath lsphp83-gd lsphp83-zip lsphp83-opcache lsphp83-process lsphp83-xml lsphp83-mbstring
   ln -sf /usr/local/lsws/lsphp83/bin/php /usr/local/bin/php83
   php83 -m | grep -Ei 'pdo_mysql|intl|bcmath|mbstring|zip'
   ```

4. Composer y Node 22 (para compilar el frontend):

   ```bash
   curl -sS https://getcomposer.org/installer | php83 -- --install-dir=/usr/local/bin --filename=composer
   dnf module enable -y nodejs:22 && dnf install -y nodejs git
   ```

## 2. Base de datos

En **Databases › Create Database** crea la base `crm` y su usuario. Guarda la contraseña: va en el `.env`.

## 3. Código

CyberPanel crea el usuario del sitio (por ejemplo `colom1234`) y la carpeta `/home/crm.colombiabirdfair.com`. Trabaja siempre como ese usuario para que los permisos queden bien.

```bash
SITE=/home/crm.colombiabirdfair.com
SITEUSER=$(stat -c %U $SITE/public_html)
sudo -u $SITEUSER git clone https://github.com/keineria/crmevent-cb.git $SITE/app
cd $SITE/app/crm
sudo -u $SITEUSER cp .env.example .env
```

Edita `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://crm.colombiabirdfair.com` y los datos `DB_*`. Después:

```bash
sudo -u $SITEUSER php83 /usr/local/bin/composer install --no-dev --optimize-autoloader
sudo -u $SITEUSER npm ci && sudo -u $SITEUSER npm run build
sudo -u $SITEUSER php83 artisan key:generate
sudo -u $SITEUSER php83 artisan migrate --seed --force
sudo -u $SITEUSER php83 artisan crm:create-admin keineria90@gmail.com "Keiner"
sudo -u $SITEUSER php83 artisan optimize
```

`APP_KEY` cifra las credenciales de correo. Si se pierde o se cambia, hay que volver a escribir las contraseñas en **Configuración › Correo de envíos**. Guarda una copia del `.env` fuera del servidor.

## 4. Raíz del sitio

En **Websites › List Websites › Manage › vHost Conf**, cambia la raíz para que apunte a la carpeta `public` del CRM:

```
docRoot                   $VH_ROOT/app/crm/public
```

y asegúrate de que el bloque `rewrite` tenga `enable 1` y `autoLoadHtaccess 1` (el `.htaccess` de Laravel hace el resto). Guarda y reinicia OpenLiteSpeed:

```bash
systemctl restart lsws
```

## 5. Cola y tareas programadas

Los envíos masivos y la sincronización con el plugin corren en la cola. Instala Supervisor:

```bash
dnf install -y epel-release && dnf install -y supervisor
cat > /etc/supervisord.d/crm-worker.ini <<INI
[program:crm-worker]
command=/usr/local/bin/php83 /home/crm.colombiabirdfair.com/app/crm/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=$SITEUSER
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/home/crm.colombiabirdfair.com/app/crm/storage/logs/worker.log
INI
systemctl enable --now supervisord
supervisorctl reread && supervisorctl update
```

En **Websites › Cron Jobs** (usuario del sitio) agrega una tarea cada minuto:

```
* * * * * /usr/local/bin/php83 /home/crm.colombiabirdfair.com/app/crm/artisan schedule:run >> /dev/null 2>&1
```

## 6. Actualizar

```bash
cd /home/crm.colombiabirdfair.com/app/crm
sudo -u $SITEUSER git pull
sudo -u $SITEUSER php83 /usr/local/bin/composer install --no-dev --optimize-autoloader
sudo -u $SITEUSER npm ci && sudo -u $SITEUSER npm run build
sudo -u $SITEUSER php83 artisan migrate --force
sudo -u $SITEUSER php83 artisan optimize
supervisorctl restart crm-worker
```

## 7. Comprobar

- `https://crm.colombiabirdfair.com` redirige al inicio de sesión.
- Entra con el admin y abre **Configuración › Correo de envíos**. Guarda los datos del proveedor, envía el correo de prueba y verifica SPF, DKIM y DMARC.
- `storage/logs/laravel.log` no muestra errores.
