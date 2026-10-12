{{-- Brand line at the top of every CRM email: the logo from Configuración › Marca y logos, or the fair's name when none is uploaded. --}}
@php($brandLogo = \App\Support\Branding::url(\App\Support\Branding::EMAIL))
<tr>
    @if ($brandLogo)
        <td style="padding:8px 4px 16px;">
            <img src="{{ $brandLogo }}" alt="Colombia Birdfair" width="180" style="display:block;width:180px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;">
        </td>
    @else
        <td style="padding:8px 4px 16px;font-size:18px;font-weight:700;color:#2F5D2A;letter-spacing:0.2px;">Colombia Birdfair</td>
    @endif
</tr>
