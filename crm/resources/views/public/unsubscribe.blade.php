@extends('public.layout', ['title' => 'Darse de baja'])

@section('content')
    @if ($contact->unsubscribed_at)
        <h1>Ya estás dado de baja</h1>
        <p>{{ $contact->email }} no recibe boletines ni difusiones de Colombia Birdfair.</p>
    @else
        <h1>¿Dejar de recibir boletines?</h1>
        <p>{{ $contact->email }} no recibirá más boletines ni difusiones. Los correos de tus compras e inscripciones seguirán llegando.</p>
        <form method="post" action="{{ route('unsubscribe.store', $token) }}">
            <button type="submit">Darme de baja</button>
        </form>
    @endif
@endsection
