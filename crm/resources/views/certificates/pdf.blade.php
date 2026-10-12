@php
    // A4 landscape, measured in mm. Every block is placed absolutely: dompdf has no flexbox or grid.
    $decor = $background === null;
    $frame = [
        'selva' => ['content' => 'left:98mm;top:34mm;width:178mm;', 'align' => 'left', 'signers' => 'left:98mm;top:160mm;width:178mm;', 'logo' => null],
        'minimal' => ['content' => 'left:24mm;top:56mm;width:200mm;', 'align' => 'left', 'signers' => 'left:24mm;top:160mm;width:200mm;', 'logo' => 'left:24mm;top:20mm;width:120mm;text-align:left;'],
        'clasico' => ['content' => 'left:30mm;top:52mm;width:237mm;', 'align' => 'center', 'signers' => 'left:60mm;top:158mm;width:177mm;', 'logo' => 'left:30mm;top:20mm;width:237mm;text-align:center;'],
        'geometrico' => ['content' => 'left:24mm;top:56mm;width:190mm;', 'align' => 'left', 'signers' => 'left:24mm;top:160mm;width:190mm;', 'logo' => 'left:24mm;top:20mm;width:120mm;text-align:left;'],
    ][$template] ?? null;
    $families = ['serif' => "'Playfair Display'", 'sans' => "'Inter'", 'script' => "'Great Vibes'"];
    // Inline style of a block of the "propio" template, from its saved position and look.
    $place = function (array $b) use ($families) {
        $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($b['color'] ?? '')) ? $b['color'] : '#1C1A17';
        $align = in_array($b['align'] ?? '', ['left', 'center', 'right'], true) ? $b['align'] : 'left';

        return sprintf('left:%.1fmm;top:%.1fmm;width:%.1fmm;font-size:%.1fpt;color:%s;text-align:%s;font-family:%s;',
            (float) $b['x'], (float) $b['y'], (float) $b['w'], (float) $b['size'], $color, $align, $families[$b['font'] ?? 'sans'] ?? "'Inter'");
    };
    $nameFont = $template === 'clasico' ? "font-family:'Great Vibes';font-size:50pt;line-height:1.15;color:{$primary};" : "font-family:'Playfair Display';font-weight:bold;font-size:38pt;line-height:1.1;";
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @font-face { font-family: 'Inter'; font-weight: normal; src: url('{{ $fonts }}/Inter-Regular.ttf'); }
    @font-face { font-family: 'Inter'; font-weight: bold; src: url('{{ $fonts }}/Inter-SemiBold.ttf'); }
    @font-face { font-family: 'Playfair Display'; font-weight: normal; src: url('{{ $fonts }}/PlayfairDisplay-Regular.ttf'); }
    @font-face { font-family: 'Playfair Display'; font-weight: bold; src: url('{{ $fonts }}/PlayfairDisplay-Bold.ttf'); }
    @font-face { font-family: 'Playfair Display'; font-style: italic; src: url('{{ $fonts }}/PlayfairDisplay-Italic.ttf'); }
    @font-face { font-family: 'Great Vibes'; font-weight: normal; src: url('{{ $fonts }}/GreatVibes-Regular.ttf'); }
    @page { margin: 0; size: A4 landscape; }
    html, body { margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; color: #1C1A17; font-size: 12pt; }
    .abs { position: absolute; }
    .heading { font-size: 12pt; font-weight: bold; letter-spacing: 3pt; text-transform: uppercase; margin: 0; }
    .intro { font-family: 'Playfair Display'; font-style: italic; font-size: 14pt; color: #5F5A52; margin: 9mm 0 2mm; }
    .name { margin: 0; }
    .doc { font-size: 9.5pt; color: #5F5A52; margin: 2mm 0 0; letter-spacing: 0.5pt; }
    .rule { height: 1.2mm; width: 22mm; margin: 6mm 0 6mm; }
    .body { font-size: 12.5pt; line-height: 1.55; color: #3A3631; margin: 0; }
    .signers td { vertical-align: bottom; padding: 0 6mm 0 0; }
    .sig-img { height: 16mm; margin-bottom: 1mm; }
    .sig-line { border-top: 0.3mm solid #1C1A17; padding-top: 2mm; }
    .sig-name { font-size: 10.5pt; font-weight: bold; margin: 0; }
    .sig-role { font-size: 9pt; color: #5F5A52; margin: 0.5mm 0 0; }
    .qr-box { background: #FFFFFF; padding: 2mm; }
    .verify { font-size: 7pt; color: #5F5A52; line-height: 1.4; }
</style>
</head>
<body>
@if ($background)
    <img class="abs" src="{{ $background }}" style="left:0;top:0;width:297mm;height:210mm;" alt="">
@endif

@if ($template === 'propio')
    {{-- The uploaded design is the certificate: only the data is drawn, where it was placed. --}}
    @php $L = $layout; @endphp
    @if ($L['heading']['visible'] && $heading !== '')
        <p class="abs" style="{!! $place($L['heading']) !!}margin:0;letter-spacing:3pt;text-transform:uppercase;font-weight:bold;">{{ $heading }}</p>
    @endif
    @if ($L['intro']['visible'] && $intro !== '')
        <p class="abs" style="{!! $place($L['intro']) !!}margin:0;{{ $L['intro']['font'] === 'serif' ? 'font-style:italic;' : '' }}">{{ $intro }}</p>
    @endif
    @if ($L['name']['visible'])
        <p class="abs" style="{!! $place($L['name']) !!}margin:0;line-height:1.15;{{ $L['name']['font'] !== 'script' ? 'font-weight:bold;' : '' }}">{{ $name }}</p>
    @endif
    @if ($L['document']['visible'] && $document)
        <p class="abs" style="{!! $place($L['document']) !!}margin:0;letter-spacing:0.5pt;">Documento {{ $document }}</p>
    @endif
    @if ($L['body']['visible'])
        <p class="abs" style="{!! $place($L['body']) !!}margin:0;line-height:1.55;">{!! $body !!}</p>
    @endif
    @if ($L['signers']['visible'] && $signers)
        @php $col = (float) $L['signers']['w'] / count($signers); @endphp
        <div class="abs" style="{!! $place($L['signers']) !!}">
            <table cellpadding="0" cellspacing="0" style="width:100%;"><tr>
                @foreach ($signers as $signer)
                    <td style="width:{{ $col }}mm;vertical-align:bottom;padding:0 4mm;text-align:{{ $L['signers']['align'] }};">
                        @if ($signer['image'])
                            <img class="sig-img" src="{{ $signer['image'] }}" alt="">
                        @else
                            <div style="height:16mm;"></div>
                        @endif
                        <div style="border-top:0.3mm solid {{ $L['signers']['color'] }};padding-top:2mm;">
                            <p style="margin:0;font-weight:bold;">{{ $signer['name'] }}</p>
                            @if ($signer['role'])
                                <p style="margin:0.5mm 0 0;font-size:0.85em;opacity:0.8;">{{ $signer['role'] }}</p>
                            @endif
                        </div>
                    </td>
                @endforeach
            </tr></table>
        </div>
    @endif
    @if ($L['qr']['visible'] && $qr)
        <div class="abs" style="left:{{ (float) $L['qr']['x'] }}mm;top:{{ (float) $L['qr']['y'] }}mm;width:{{ (float) $L['qr']['size'] + 10 }}mm;text-align:center;">
            <div class="qr-box" style="display:inline-block;"><img src="{{ $qr }}" style="width:{{ (float) $L['qr']['size'] }}mm;height:{{ (float) $L['qr']['size'] }}mm;" alt=""></div>
            <div class="verify">{{ $code }}</div>
        </div>
    @endif
@else
{{-- Decoration of each layout. A design image uploaded as background replaces it. --}}
@if ($template === 'selva')
    @if ($decor)
        <div class="abs" style="left:0;top:0;width:78mm;height:210mm;background:{{ $primary }};"></div>
        <div class="abs" style="left:0;top:0;width:78mm;height:4mm;background:{{ $accent }};"></div>
        <div class="abs" style="left:14mm;top:112mm;width:56mm;color:#FFFFFF;">
            <p style="margin:0;font-size:8.5pt;letter-spacing:2.5pt;text-transform:uppercase;font-weight:bold;">Colombia Birdfair</p>
            <p style="margin:1mm 0 0;font-family:'Playfair Display';font-weight:bold;font-size:40pt;line-height:1;">{{ $edition }}</p>
            <div style="width:12mm;height:1.2mm;background:{{ $accent }};margin-top:4mm;"></div>
        </div>
    @endif
    @if ($logo)
        <div class="abs" style="left:12mm;top:16mm;width:54mm;background:#FFFFFF;padding:4mm;text-align:center;border-radius:2mm;">
            <img src="{{ $logo }}" style="max-width:54mm;max-height:24mm;" alt="">
        </div>
    @endif
    @if ($qr)
        <div class="abs" style="left:14mm;top:160mm;width:60mm;">
            <table cellpadding="0" cellspacing="0"><tr>
                <td style="vertical-align:bottom;"><div class="qr-box"><img src="{{ $qr }}" style="width:22mm;height:22mm;" alt=""></div></td>
                <td style="vertical-align:bottom;padding-left:3mm;">
                    <div class="verify" style="color:{{ $decor ? '#FFFFFF' : '#5F5A52' }};">Verifica en<br>{{ $verifyHost }}<br><strong>{{ $code }}</strong></div>
                </td>
            </tr></table>
        </div>
    @endif
@elseif ($template === 'minimal')
    @if ($decor)
        <div class="abs" style="left:150mm;top:4mm;width:140mm;text-align:right;font-family:'Playfair Display';font-weight:bold;font-size:120pt;line-height:1;color:{{ $primary }};opacity:0.07;">{{ $edition }}</div>
        <div class="abs" style="left:0;top:204mm;width:297mm;height:6mm;background:{{ $primary }};"></div>
        <div class="abs" style="left:0;top:204mm;width:60mm;height:6mm;background:{{ $accent }};"></div>
    @endif
@elseif ($template === 'clasico')
    @if ($decor)
        <div class="abs" style="left:7mm;top:7mm;width:280mm;height:193mm;border:1.4mm solid {{ $primary }};"></div>
        <div class="abs" style="left:11mm;top:11mm;width:274mm;height:187mm;border:0.3mm solid {{ $accent }};"></div>
        <div class="abs" style="left:243mm;top:150mm;width:28mm;height:28mm;border-radius:14mm;background:{{ $accent }};color:#FFFFFF;text-align:center;">
            <div style="padding-top:8mm;font-size:6.5pt;letter-spacing:1.5pt;font-weight:bold;">ASISTENCIA</div>
            <div style="font-family:'Playfair Display';font-weight:bold;font-size:14pt;line-height:1.1;">{{ $edition }}</div>
        </div>
    @endif
@elseif ($template === 'geometrico')
    @if ($decor)
        <div class="abs" style="left:205mm;top:-70mm;width:160mm;height:160mm;border-radius:80mm;background:{{ $primary }};"></div>
        <div class="abs" style="left:262mm;top:62mm;width:56mm;height:56mm;border-radius:28mm;background:{{ $accent }};"></div>
        <div class="abs" style="left:-40mm;top:178mm;width:80mm;height:80mm;border-radius:40mm;background:{{ $accent }};opacity:0.18;"></div>
        <div class="abs" style="left:232mm;top:20mm;width:50mm;text-align:right;color:#FFFFFF;">
            <p style="margin:0;font-size:8pt;letter-spacing:2.5pt;font-weight:bold;">COLOMBIA BIRDFAIR</p>
            <p style="margin:0;font-family:'Playfair Display';font-weight:bold;font-size:30pt;line-height:1.1;">{{ $edition }}</p>
        </div>
    @endif
@endif

@if ($frame['logo'] && $logo)
    <div class="abs" style="{{ $frame['logo'] }}"><img src="{{ $logo }}" style="max-height:22mm;max-width:70mm;" alt=""></div>
@endif

<div class="abs" style="{{ $frame['content'] }}text-align:{{ $frame['align'] }};">
    <p class="heading" style="color:{{ $template === 'selva' || $template === 'geometrico' ? $accent : $primary }};">{{ $heading }}</p>
    @if ($intro !== '')
        <p class="intro">{{ $intro }}</p>
    @else
        <div style="height:9mm;"></div>
    @endif
    <p class="name" style="{{ $nameFont }}">{{ $name }}</p>
    @if ($document)
        <p class="doc">Documento {{ $document }}</p>
    @endif
    <div class="rule" style="background:{{ $accent }};{{ $frame['align'] === 'center' ? 'margin-left:auto;margin-right:auto;' : '' }}"></div>
    <p class="body">{!! $body !!}</p>
</div>

@if ($signers)
    <div class="abs" style="{{ $frame['signers'] }}">
        <table class="signers" cellpadding="0" cellspacing="0" style="{{ $frame['align'] === 'center' ? 'margin:0 auto;' : '' }}"><tr>
            @foreach ($signers as $signer)
                <td style="width:62mm;{{ $frame['align'] === 'center' ? 'text-align:center;padding:0 5mm;' : '' }}">
                    @if ($signer['image'])
                        <img class="sig-img" src="{{ $signer['image'] }}" alt="">
                    @else
                        <div style="height:16mm;"></div>
                    @endif
                    <div class="sig-line">
                        <p class="sig-name">{{ $signer['name'] }}</p>
                        @if ($signer['role'])
                            <p class="sig-role">{{ $signer['role'] }}</p>
                        @endif
                    </div>
                </td>
            @endforeach
        </tr></table>
    </div>
@endif

@if ($qr && $template !== 'selva')
    @php
        $qrPlace = ['minimal' => 'left:243mm;top:160mm;', 'clasico' => 'left:24mm;top:156mm;', 'geometrico' => 'left:243mm;top:160mm;'][$template];
    @endphp
    <div class="abs" style="{{ $qrPlace }}width:32mm;text-align:center;">
        <div class="qr-box" style="display:inline-block;"><img src="{{ $qr }}" style="width:22mm;height:22mm;" alt=""></div>
        <div class="verify">Verifica en {{ $verifyHost }}<br><strong>{{ $code }}</strong></div>
    </div>
@endif
@endif
</body>
</html>
