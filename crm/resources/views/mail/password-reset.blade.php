<x-mail::message>
# Hola{{ $name ? " {$name}" : '' }}

Recibimos una solicitud para restablecer la contraseña de tu cuenta en el **CRM de Colombia Birdfair**.

<x-mail::button :url="$url" color="success">
Crear una contraseña nueva
</x-mail::button>

El enlace vence en {{ $minutes }} minutos y solo sirve una vez. Si no lo pediste, ignora este mensaje: tu contraseña actual sigue igual.
</x-mail::message>
