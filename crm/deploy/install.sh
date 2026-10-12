#!/usr/bin/env bash
# Instala o actualiza el CRM 360 en un VPS con AlmaLinux 9 + CyberPanel 3.0.
# Uso (como root, por SSH):
#   curl -fsSLo install.sh https://raw.githubusercontent.com/keineria/crmevent-cb/main/crm/deploy/install.sh
#   bash install.sh [dominio] [rama]
# Es seguro volver a ejecutarlo: actualiza el código sin tocar el .env existente.
set -euo pipefail

DOMAIN="${1:-event.colombiabirdfair.com}"
BRANCH="${2:-main}"
REPO="https://github.com/keineria/crmevent-cb.git"
SITE="/home/${DOMAIN}"
APP="${SITE}/app"
CRM="${APP}/crm"
PHP="/usr/local/lsws/lsphp83/bin/php"
VHOST="/usr/local/lsws/conf/vhosts/${DOMAIN}/vhost.conf"

say() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31mError: %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "ejecuta este script como root."
[[ -d "${SITE}/public_html" ]] || die "no existe ${SITE}/public_html. Crea primero el sitio ${DOMAIN} en CyberPanel con PHP 8.3."
[[ -f "$VHOST" ]] || die "no encuentro ${VHOST}. ¿El sitio ${DOMAIN} está creado en CyberPanel?"

SITEUSER="$(stat -c %U "${SITE}/public_html")"
cd /tmp
as_site() { sudo -u "$SITEUSER" -H "$@"; }

say "Instalando PHP 8.3 (extensiones), Composer, Node 22, Git y Supervisor"
dnf install -y epel-release >/dev/null
dnf install -y git unzip supervisor >/dev/null
# strict=0: si el repositorio de LiteSpeed no tiene un paquete (algunos vienen incluidos), sigue con el resto.
dnf install -y --setopt=strict=0 lsphp83 lsphp83-common lsphp83-mysqlnd lsphp83-pdo lsphp83-intl lsphp83-bcmath \
    lsphp83-gd lsphp83-zip lsphp83-pecl-zip lsphp83-opcache lsphp83-process lsphp83-xml lsphp83-mbstring >/dev/null
ln -sf "$PHP" /usr/local/bin/php83
# Solo las que el CRM necesita (composer.lock); intl, bcmath y zip son opcionales.
# Read the list once: with pipefail, "php -m | grep -q" fails at random when grep closes the pipe early.
MODULES="$("$PHP" -m)"
MISSING=""
for ext in pdo_mysql mbstring openssl dom xmlreader fileinfo tokenizer ctype; do
    grep -qix "$ext" <<<"$MODULES" || MISSING="${MISSING} ${ext}"
done
[[ -z "$MISSING" ]] || die "a PHP 8.3 le faltan estas extensiones:${MISSING}. Revisa con: dnf search lsphp83"
# zip solo hace falta para leer .xlsx en el importador; sin ella el CRM funciona y acepta .csv.
COMPOSER_FLAGS=()
if ! grep -qix zip <<<"$MODULES"; then
    echo "Aviso: PHP 8.3 no tiene la extensión zip. El importador solo aceptará archivos .csv hasta que la instales."
    COMPOSER_FLAGS+=(--ignore-platform-req=ext-zip)
fi
if ! command -v node >/dev/null || [[ "$(node -v | cut -d. -f1 | tr -d v)" -lt 22 ]]; then
    dnf module reset -y nodejs >/dev/null
    dnf module enable -y nodejs:22 >/dev/null
    dnf install -y nodejs >/dev/null
fi
if [[ ! -x /usr/local/bin/composer ]]; then
    curl -fsSL https://getcomposer.org/installer | "$PHP" -- --install-dir=/usr/local/bin --filename=composer
fi
composer() { as_site "$PHP" /usr/local/bin/composer "$@"; }
artisan() { as_site "$PHP" "${CRM}/artisan" "$@"; }

# El importador acepta archivos de hasta 20 MB; lsphp trae 2 MB por defecto.
PHPINI="/usr/local/lsws/lsphp83/etc/php.ini"
if [[ -f "$PHPINI" ]] && ! grep -qE '^upload_max_filesize\s*=\s*(2[5-9]|[3-9][0-9]|[0-9]{3,})M' "$PHPINI"; then
    cp "$PHPINI" "${PHPINI}.bak.$(date +%Y%m%d%H%M%S)"
    sed -i -E 's/^upload_max_filesize\s*=.*/upload_max_filesize = 25M/; s/^post_max_size\s*=.*/post_max_size = 30M/' "$PHPINI"
fi

say "Descargando el código (rama ${BRANCH})"
if [[ -d "${APP}/.git" ]]; then
    as_site git -C "$APP" fetch --quiet origin "$BRANCH"
    as_site git -C "$APP" checkout --quiet "$BRANCH"
    as_site git -C "$APP" reset --quiet --hard "origin/${BRANCH}"
else
    as_site git clone --quiet --branch "$BRANCH" "$REPO" "$APP"
fi

# Escribe KEY='valor' en el .env sin interpretar caracteres especiales.
set_env() {
    local key="$1" value="$2" file="${CRM}/.env"
    [[ "$value" != *"'"* ]] || die "el valor de ${key} no puede contener comillas simples (')."
    KEY="$key" VALUE="$value" awk 'BEGIN { k = ENVIRON["KEY"]; v = ENVIRON["VALUE"]; done = 0 }
        index($0, k "=") == 1 { print k "='\''" v "'\''"; done = 1; next } { print }
        END { if (!done) print k "='\''" v "'\''" }' "$file" > "${file}.tmp"
    cat "${file}.tmp" > "$file" && rm -f "${file}.tmp"
}

if [[ ! -f "${CRM}/.env" ]]; then
    say "Configurando el .env (datos de la base creada en CyberPanel › Databases)"
    read -rp "Nombre de la base de datos: " DB_NAME
    read -rp "Usuario de la base de datos: " DB_USER
    read -rsp "Contraseña de la base de datos: " DB_PASS; echo
    as_site cp "${CRM}/.env.example" "${CRM}/.env"
    chmod 600 "${CRM}/.env"
    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env APP_URL "https://${DOMAIN}"
    set_env DB_DATABASE "$DB_NAME"
    set_env DB_USERNAME "$DB_USER"
    set_env DB_PASSWORD "$DB_PASS"
    chown "$SITEUSER": "${CRM}/.env"
fi

say "Instalando dependencias y compilando la interfaz"
composer install --working-dir="$CRM" --no-dev --optimize-autoloader --no-interaction --quiet "${COMPOSER_FLAGS[@]}"
as_site bash -c 'cd "$1" && npm ci --no-audit --no-fund --loglevel=error && npm run build --silent' _ "$CRM"

grep -q '^APP_KEY=.\+' "${CRM}/.env" || artisan key:generate --force
artisan migrate --seed --force
artisan storage:link 2>/dev/null || true
artisan optimize
# One-time cleanup Keiner asked for (2026-10-12): contacts created by unpaid checkouts before that stopped.
if [[ ! -f "${CRM}/storage/app/.pruned-unpaid-v1" ]]; then
    artisan crm:prune-unpaid-contacts && as_site touch "${CRM}/storage/app/.pruned-unpaid-v1"
fi
# Preferred products and stars of each contact (also hourly from the scheduler).
artisan contacts:refresh-insights || true

say "Apuntando la raíz del sitio a crm/public"
if ! grep -q "docRoot.*app/crm/public" "$VHOST"; then
    cp "$VHOST" "${VHOST}.bak.$(date +%Y%m%d%H%M%S)"
    sed -i -E 's#^(\s*docRoot\s+).*#\1$VH_ROOT/app/crm/public#' "$VHOST"
    grep -q "autoLoadHtaccess" "$VHOST" || sed -i -E '0,/^\s*rewrite\s*\{/s##rewrite  {\n  autoLoadHtaccess        1#' "$VHOST"
    chown lsadm:nobody "$VHOST" 2>/dev/null || true
fi
# Let's Encrypt challenges: CyberPanel usually maps /.well-known/acme-challenge to its own folder with a
# "context" in the vhost; use that folder when present, otherwise the CRM docRoot.
[[ -L "${CRM}/public/.well-known" ]] && rm -f "${CRM}/public/.well-known"
mkdir -p "${CRM}/public/.well-known/acme-challenge"
chown -R "$SITEUSER": "${CRM}/public/.well-known"
# LiteSpeed sometimes cancels a restart that overlaps its own reload ("Job for lshttpd.service canceled"); try again.
restart_web() {
    local try
    for try in 1 2 3; do
        systemctl restart lsws && return 0
        echo "El reinicio del servidor web se canceló (intento ${try}); reintentando en 5 s."
        sleep 5
    done
    return 1
}
restart_web
ACME_DIR="$(awk '/context[[:space:]]+\/\.well-known\/acme-challenge/{f=1} f&&$1=="location"{print $2; exit}' "$VHOST")"
ACME_DIR="${ACME_DIR//\$VH_ROOT/$SITE}"
ACME_DIR="${ACME_DIR%/}"
if [[ -n "$ACME_DIR" ]]; then
    WEBROOT="${ACME_DIR%/.well-known/acme-challenge}"
else
    WEBROOT="${CRM}/public"
fi
mkdir -p "${WEBROOT}/.well-known/acme-challenge"
echo "Carpeta de verificación SSL: ${WEBROOT}/.well-known/acme-challenge"
sleep 2
echo ok > "${WEBROOT}/.well-known/acme-challenge/crm-check"
chmod 644 "${WEBROOT}/.well-known/acme-challenge/crm-check"
CHECK="$(curl -s -o /dev/null -w '%{http_code}' --resolve "${DOMAIN}:80:127.0.0.1" "http://${DOMAIN}/.well-known/acme-challenge/crm-check" || true)"
echo "Prueba de verificación HTTP: ${CHECK}"
if [[ "$CHECK" != "200" ]]; then
    grep -nE "docRoot|context|location|vhssl|^\s*rewrite" "$VHOST" || true
    curl -sI --resolve "${DOMAIN}:80:127.0.0.1" "http://${DOMAIN}/.well-known/acme-challenge/crm-check" | head -8 || true
fi
rm -f "${WEBROOT}/.well-known/acme-challenge/crm-check"

# A Let's Encrypt *staging* certificate (what CyberPanel leaves after failed attempts) is not trusted by browsers.
# Replace a missing or staging certificate with a real one, in the files the CyberPanel vhost already points to.
CERT_DIR="/etc/letsencrypt/live/${DOMAIN}"
ACME="/root/.acme.sh/acme.sh"
ISSUER="$(openssl x509 -in "${CERT_DIR}/fullchain.pem" -noout -issuer 2>/dev/null || true)"
if [[ -x "$ACME" ]] && { [[ -z "$ISSUER" ]] || grep -qi staging <<<"$ISSUER"; }; then
    say "Emitiendo el certificado SSL de Let's Encrypt"
    mkdir -p "$CERT_DIR"
    if "$ACME" --issue -d "$DOMAIN" --webroot "$WEBROOT" --server letsencrypt --force \
        && "$ACME" --install-cert -d "$DOMAIN" --cert-file "${CERT_DIR}/cert.pem" --key-file "${CERT_DIR}/privkey.pem" \
            --fullchain-file "${CERT_DIR}/fullchain.pem"; then
        restart_web
    else
        echo "No se pudo emitir el certificado. Revisa que el puerto 80 esté abierto y que Cloudflare esté pausado o en gris."
    fi
fi
echo "Certificado SSL: $(openssl x509 -in "${CERT_DIR}/fullchain.pem" -noout -issuer -enddate 2>/dev/null | tr '\n' ' ' || echo 'no encontrado')"
grep -E "^\s*(keyFile|certFile)" "$VHOST" || echo "El vhost no tiene bloque SSL todavía."

say "Configurando la cola (Supervisor) y las tareas programadas (cron)"
cat > /etc/supervisord.d/crm-worker.ini <<INI
[program:crm-worker]
command=${PHP} ${CRM}/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=${SITEUSER}
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=${CRM}/storage/logs/worker.log
INI
systemctl enable --now supervisord >/dev/null
supervisorctl reread >/dev/null && supervisorctl update >/dev/null
supervisorctl restart crm-worker >/dev/null || true
CRON="* * * * * ${PHP} ${CRM}/artisan schedule:run >> /dev/null 2>&1"
( crontab -u "$SITEUSER" -l 2>/dev/null | grep -vF "${CRM}/artisan schedule:run" || true; echo "$CRON" ) | crontab -u "$SITEUSER" -

if ! artisan tinker --execute='echo App\Models\User::role("Admin")->exists() ? "yes" : "no";' 2>/dev/null | grep -q yes; then
    say "Creando el usuario administrador"
    read -rp "Correo del administrador: " ADMIN_EMAIL
    read -rp "Nombre del administrador: " ADMIN_NAME
    artisan crm:create-admin "$ADMIN_EMAIL" "$ADMIN_NAME"
fi

say "Listo: https://${DOMAIN}"
echo "Si el sitio no tiene SSL aún, emítelo en CyberPanel › SSL › Manage SSL."
echo "Guarda una copia de ${CRM}/.env fuera del servidor: su APP_KEY cifra las claves de correo."
