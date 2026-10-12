<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $subject }}</title>
    <style>
        body { margin: 0; padding: 0; background: #F6F3EE; }
        .content h1 { font-size: 26px; line-height: 1.25; margin: 0 0 16px; color: #1F2A1C; }
        .content h2 { font-size: 20px; line-height: 1.3; margin: 28px 0 12px; color: #1F2A1C; }
        .content h3 { font-size: 17px; margin: 24px 0 8px; color: #1F2A1C; }
        .content p, .content li { font-size: 16px; line-height: 1.6; color: #3A3631; }
        .content p { margin: 0 0 16px; }
        .content ul, .content ol { padding-left: 22px; margin: 0 0 16px; }
        .content blockquote { margin: 0 0 16px; padding: 4px 16px; border-left: 4px solid #FF8600; color: #3A3631; }
        .content hr { border: 0; border-top: 1px solid #E4DED3; margin: 24px 0; }
        @media (max-width: 620px) { .card { padding: 24px 20px !important; } }
    </style>
</head>
<body style="margin:0;padding:0;background:#F6F3EE;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
@if ($preheader)
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{{ $preheader }}&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F3EE;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
                @include('mail.partials.brand-header')
                <tr>
                    <td class="card content" style="background:#FFFFFF;border-radius:12px;padding:32px 36px;">
                        {!! str_replace('%%QR_SRC%%', $message->embedData($png, 'qr.png', 'image/png'), $body) !!}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
