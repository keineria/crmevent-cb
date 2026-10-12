{{-- Email header: the logo from Configuración › Marca, or the fair's name when none is uploaded. --}}
@php($brandLogo = \App\Support\Branding::url(\App\Support\Branding::EMAIL))
@if ($brandLogo)
    <img src="{{ $brandLogo }}" alt="Colombia Birdfair" width="180" style="display:block;width:180px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;">
@else
    <span style="font-size:18px;font-weight:700;color:#2F5D2A;letter-spacing:0.2px;">Colombia Birdfair</span>
@endif
