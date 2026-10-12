<x-mail::message>
# Hola {{ $name }}

Este es tu código para entrar a **Colombia Birdfair**. Muéstralo en la entrada y en el punto de encuentro de tus salidas; también puedes dar tu número de documento.

<div style="text-align: center; margin: 24px 0;">
<img src="{{ $message->embedData($png, 'qr.png', 'image/png') }}" alt="Código QR {{ $code }}" width="240" height="240" style="width: 240px; height: 240px;">
<p style="font-size: 20px; font-weight: bold; letter-spacing: 2px; margin: 8px 0 0;">{{ $code }}</p>
</div>

@if ($activities->isNotEmpty())
**Tus actividades**

@foreach ($activities as $activity)
- {{ $activity->name }}@if ($activity->event_date) · {{ $activity->event_date->locale('es')->translatedFormat('l j \d\e F') }}@endif{{ $activity->start_time ? ' · '.substr($activity->start_time, 0, 5) : '' }}
@endforeach
@endif

El código es personal. Si alguien más va contigo, cada persona recibe el suyo.
</x-mail::message>
