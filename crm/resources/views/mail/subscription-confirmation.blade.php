<x-mail::message>
# Hola{{ $name ? " {$name}" : '' }}

Recibimos tu solicitud para recibir las novedades de **Colombia Birdfair**. Confirma que este es tu correo:

<x-mail::button :url="$url" color="success">
Confirmar suscripción
</x-mail::button>

El enlace vence en 7 días. Si no fuiste tú, ignora este mensaje y no te escribiremos.

Al confirmar autorizas el tratamiento de tus datos para el envío de información de la feria, conforme a la Ley 1581 de 2012.
</x-mail::message>
