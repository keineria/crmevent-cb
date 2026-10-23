# Stand comercial conectado a una hoja de Google

El CRM lee y escribe una pestaña de una hoja de Google (por ejemplo la pestaña **Stands** del MASTER):

- Lo que se edita en el CRM se escribe en la hoja al instante y Google recalcula las fórmulas.
- Lo que se cambia en Drive llega al CRM al abrir la pestaña, con **Sincronizar**, o solo cada minuto (`stands:sync` en el cron).
- Las celdas con fórmula no se pueden editar desde el CRM.
- **Stands comprados** muestra las filas que tienen número de stand y algo escrito en «Nombre del Stand». Las filas con un
  solo texto, como «ZONA EXTERIOR», marcan la zona de las filas siguientes. Las marcas *Pagó*, *Documentos completos* y
  *Facturado* se guardan en el CRM, por zona y número de stand.

## Pasos (una vez)

1. **Hoja nativa de Google.** Si el archivo dice `.XLSX` junto al nombre, es un Excel guardado en Drive y la API no lo
   abre. Usa *Archivo › Guardar como Hojas de cálculo de Google* y trabajen desde la copia.
2. **Cuenta de servicio.** En [console.cloud.google.com](https://console.cloud.google.com): crea o elige un proyecto, activa
   *Google Sheets API*, crea una cuenta de servicio y descarga su clave `.json`. Sirve la misma de Google Wallet.
3. **Clave en el CRM.** Un Admin la sube en *Stand comercial › Conectar una hoja de Google*. Se guarda cifrada.
   (También sirve `GOOGLE_SHEETS_SERVICE_ACCOUNT=/ruta/clave.json` en el `.env`.)
4. **Compartir.** Comparte la hoja con el correo de la cuenta de servicio (termina en `iam.gserviceaccount.com`) como Editor.
5. **Conectar.** Pega el enlace de la hoja y el nombre de la pestaña, y pulsa *Conectar*.
