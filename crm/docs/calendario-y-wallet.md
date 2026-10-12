# Calendario y pases de Wallet

## Qué ve el participante

- **Correo del código QR**: debajo del QR aparecen los botones *Agregar a Google Calendar* e *iPhone, Outlook y otros (.ics)*.
  Cuando las claves de abajo están puestas, también *Agregar a Apple Wallet* y *Guardar en Google Wallet*.
- **Portal Inscritos › Calendario**: los mismos dos botones de calendario y un botón por actividad para agregarla sola a Google Calendar.
  El portal no ofrece Wallet porque el pase lleva el QR, y el portal solo pide el número de documento.

El calendario sale de las actividades pagadas y activas de la persona, con la hora de inicio de la inscripción y la hora de fin
del catálogo del plugin (si no hay hora de fin se cuentan 2 horas). El lugar es el "destino" de la actividad.
Google Calendar queda suscrito al enlace y se actualiza solo cada pocas horas; el archivo .ics es una foto del momento.

Los enlaces son `https://event.colombiabirdfair.com/pase/{id}/{token}/...`. El token del calendario no abre el pase de Wallet.

## Apple Wallet

Necesitas una cuenta de Apple Developer (USD 99 al año).

1. En developer.apple.com › Certificates, IDs & Profiles › Identifiers, crea un **Pass Type ID**, por ejemplo `pass.com.colombiabirdfair.ingreso`.
2. En ese Pass Type ID, crea su certificado (*Create Certificate*), súbele una CSR hecha desde un Mac (Acceso a Llaveros) o con OpenSSL,
   descarga el `.cer`, ábrelo en el Mac y expórtalo desde el Llavero como `.p12` con una contraseña.
3. Descarga el intermedio **Apple WWDR G4** de apple.com/certificateauthority y conviértelo a PEM:
   `openssl x509 -inform der -in AppleWWDRCAG4.cer -out wwdr.pem`
4. Copia `pase.p12` y `wwdr.pem` al servidor en `crm/storage/app/private/wallet/` y pon en `crm/.env`:

```
APPLE_WALLET_PASS_TYPE_ID=pass.com.colombiabirdfair.ingreso
APPLE_WALLET_TEAM_ID=XXXXXXXXXX          # Team ID de 10 caracteres (Membership)
APPLE_WALLET_CERTIFICATE=/ruta/a/crm/storage/app/private/wallet/pase.p12
APPLE_WALLET_CERTIFICATE_PASSWORD=la-contraseña-del-p12
APPLE_WALLET_WWDR_CERTIFICATE=/ruta/a/crm/storage/app/private/wallet/wwdr.pem
```

## Google Wallet

1. En pay.google.com/business/console crea la cuenta de emisor (*Google Wallet API*) y anota el **Issuer ID**.
2. En console.cloud.google.com, en un proyecto, activa *Google Wallet API*, crea una **cuenta de servicio** y descarga su clave `.json`.
3. En la consola de Google Pay & Wallet › Usuarios, invita el correo de la cuenta de servicio con rol *Desarrollador*.
4. Copia el `.json` a `crm/storage/app/private/wallet/` y pon en `crm/.env`:

```
GOOGLE_WALLET_ISSUER_ID=3388000000000000000
GOOGLE_WALLET_SERVICE_ACCOUNT=/ruta/a/crm/storage/app/private/wallet/google-wallet.json
GOOGLE_WALLET_CLASS=colombia-birdfair-2027
```

Mientras la cuenta de emisor esté en modo de prueba, solo los correos agregados como usuarios de prueba pueden guardar el pase.
Para todos los participantes, pide en la consola el acceso de publicación (*Request publishing access*).

## Opcional

`CRM_WALLET_EVENT_NAME` (nombre en el pase y en el calendario), `CRM_WALLET_ORGANIZATION` y `CRM_WALLET_BACKGROUND` (color, por defecto `#2F5D2A`).
El logo del pase es el logo de correo de Configuración › Marca (PNG o JPG).

Después de cambiar `.env`, ejecuta `php artisan config:cache` en `crm/`.
