@extends('public.layout')

@section('content')
    @if ($recipient)
        <p style="display:inline-block;margin:0 0 16px;padding:4px 12px;border-radius:999px;background:var(--primary);color:var(--on-primary);font-size:14px;font-weight:600;">✓ Certificado válido</p>
        <h1>{{ $recipient->full_name }}</h1>
        <p style="margin-bottom:16px;">
            Asistió a <strong style="color:var(--text);">{{ $recipient->certificate->activity_name }}</strong>@if ($date), el {{ $date }}@endif.
            @if ($recipient->certificate->hours)
                Intensidad: {{ rtrim(rtrim(number_format((float) $recipient->certificate->hours, 1, ',', ''), '0'), ',') }} horas.
            @endif
        </p>
        <p style="font-size:14px;">Emitido por Colombia Birdfair · Código {{ $recipient->code }}</p>
        <form method="GET" action="{{ route('certificates.verify.pdf', $recipient->code) }}">
            <button type="submit">Descargar el certificado (PDF)</button>
        </form>
    @else
        <h1>No encontramos este certificado</h1>
        <p>El código <strong style="color:var(--text);">{{ $code }}</strong> no corresponde a ningún certificado emitido por Colombia Birdfair. Revisa que esté bien escrito o escanea de nuevo el QR.</p>
    @endif
@endsection
