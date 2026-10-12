@props(['url'])
{{-- Header of Markdown emails (QR code, subscription confirmation): the email logo from Configuración › Marca when there is one. --}}
@php($brandLogo = \App\Support\Branding::url(\App\Support\Branding::EMAIL))
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($brandLogo)
<img src="{{ $brandLogo }}" alt="{{ trim($slot) }}" width="180" style="width: 180px; max-width: 100%; height: auto; border: 0;">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
