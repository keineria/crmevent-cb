{{-- Required in every broadcast view. Expects $unsubscribeUrl and $footerText from BroadcastMailable. --}}
<hr style="border: 0; border-top: 1px solid #E4DED3; margin: 32px 0 16px;">
@if ($footerText)
    <p style="font-size: 12px; line-height: 1.5; color: #6B655C; margin: 0 0 8px;">{!! nl2br(e($footerText)) !!}</p>
@endif
<p style="font-size: 12px; line-height: 1.5; color: #6B655C; margin: 0;">
    ¿No quieres recibir más estos correos? <a href="{{ $unsubscribeUrl }}" style="color: #2F5D2A;">Darte de baja</a>.
</p>
