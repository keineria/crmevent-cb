@extends('public.layout')

@push('head')
    <style>
        main { max-width: 600px; }
        fieldset { border: 0; margin: 0 0 28px; padding: 0; }
        legend { font-weight: 600; margin: 0 0 10px; padding: 0; }
        legend small { color: var(--muted); font-weight: 400; }
        .scale { display: flex; flex-wrap: wrap; gap: 6px; }
        .scale label { position: relative; }
        .scale input { position: absolute; opacity: 0; inset: 0; }
        .scale span { display: grid; place-items: center; min-width: 44px; min-height: 44px; padding: 0 10px; border: 1px solid var(--border);
            border-radius: 8px; cursor: pointer; font-weight: 600; }
        .scale input:checked + span { background: var(--primary); border-color: var(--primary); color: var(--on-primary); }
        .scale input:focus-visible + span { outline: 3px solid var(--primary); outline-offset: 2px; }
        .ends { display: flex; justify-content: space-between; color: var(--muted); font-size: 13px; margin-top: 6px; }
        .option { display: flex; align-items: center; gap: 10px; min-height: 40px; cursor: pointer; }
        .option input { width: 18px; height: 18px; accent-color: var(--primary); }
        textarea { width: 100%; min-height: 96px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--card);
            color: var(--text); font: inherit; }
        textarea:focus-visible { outline: 3px solid var(--primary); outline-offset: 1px; }
        .error { color: #B42318; font-size: 14px; margin: 8px 0 0; }
        @media (prefers-color-scheme: dark) { .error { color: #FDA29B; } }
    </style>
@endpush

@section('content')
    @if ($invitation->responded_at)
        <h1>¡Gracias, {{ \Illuminate\Support\Str::of($invitation->full_name)->before(' ') }}!</h1>
        <p>Recibimos tus respuestas sobre <strong style="color:var(--text);">{{ $survey->activity_name }}</strong>. Nos ayudan a preparar una mejor feria.</p>
        <a href="https://colombiabirdfair.com">Ir a colombiabirdfair.com</a>
    @else
        <h1>{{ $survey->title }}</h1>
        <p style="margin-bottom:8px;"><strong style="color:var(--text);">{{ $survey->activity_name }}</strong>@if ($date) · {{ $date }}@endif</p>
        @if ($survey->intro)
            <p>{{ $survey->intro }}</p>
        @endif

        @if ($errors->any())
            <p class="error" role="alert" style="margin-bottom:24px;">Faltan algunas respuestas. Revisa las preguntas marcadas.</p>
        @endif

        <form method="POST" action="{{ route('surveys.public.store', $invitation->token) }}">
            @csrf
            @foreach ($survey->questions as $q)
                @php($name = "q[{$q['key']}]")
                @php($old = old("q.{$q['key']}"))
                @php($error = $errors->first("q.{$q['key']}"))
                <fieldset @if ($error) aria-invalid="true" aria-describedby="err-{{ $q['key'] }}" @endif>
                    <legend>{{ $q['label'] }} @unless ($q['required'])<small>(opcional)</small>@endunless</legend>

                    @if ($q['type'] === 'rating' || $q['type'] === 'nps')
                        @php($range = $q['type'] === 'rating' ? range(1, 5) : range(0, 10))
                        <div class="scale">
                            @foreach ($range as $n)
                                <label>
                                    <input type="radio" name="{{ $name }}" value="{{ $n }}" @checked((string) $old === (string) $n) @if ($q['required']) required @endif>
                                    <span>{{ $n }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="ends">
                            @if ($q['type'] === 'rating')
                                <span>Muy mal</span><span>Excelente</span>
                            @else
                                <span>Nada probable</span><span>Muy probable</span>
                            @endif
                        </div>
                    @elseif ($q['type'] === 'choice')
                        @foreach ($q['options'] ?? [] as $option)
                            <label class="option">
                                <input type="radio" name="{{ $name }}" value="{{ $option }}" @checked($old === $option) @if ($q['required']) required @endif>
                                {{ $option }}
                            </label>
                        @endforeach
                    @else
                        <textarea name="{{ $name }}" maxlength="2000" aria-label="{{ $q['label'] }}" @if ($q['required']) required @endif>{{ $old }}</textarea>
                    @endif

                    @if ($error)
                        <p class="error" id="err-{{ $q['key'] }}">{{ $error }}</p>
                    @endif
                </fieldset>
            @endforeach

            <button type="submit">Enviar respuestas</button>
            <p style="font-size:14px;margin:16px 0 0;">Tus respuestas son confidenciales: las usamos solo para mejorar la feria.</p>
        </form>
    @endif
@endsection
