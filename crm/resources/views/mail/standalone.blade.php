{{-- A whole HTML document pasted in the editor, sent as written. $append (unsubscribe footer, tracking pixel) goes before </body>; $png fills the QR image. --}}
{!! \App\Services\Campaigns\CampaignRenderer::document(
    isset($png) ? str_replace('%%QR_SRC%%', $message->embedData($png, 'qr.png', 'image/png'), $body) : $body,
    $preheader ?? null,
    $append ?? '',
) !!}
