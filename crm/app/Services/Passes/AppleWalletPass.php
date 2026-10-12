<?php

namespace App\Services\Passes;

use App\Models\RegistrationParticipant;
use Carbon\CarbonImmutable;
use RuntimeException;
use ZipArchive;

/**
 * Apple Wallet event ticket (.pkpass) carrying the participant's check-in QR.
 *
 * A .pkpass is a zip with pass.json, images, a manifest of their SHA-1 hashes and a detached signature of the
 * manifest made with the Pass Type ID certificate (plus Apple's WWDR intermediate).
 */
class AppleWalletPass
{
    public function __construct(private ParticipantSchedule $schedule) {}

    public static function configured(): bool
    {
        $c = config('crm.wallet.apple');

        return filled($c['pass_type_id']) && filled($c['team_id'])
            && filled($c['certificate']) && is_readable($c['certificate'])
            && filled($c['wwdr']) && is_readable($c['wwdr']);
    }

    public function build(RegistrationParticipant $participant): string
    {
        if (! self::configured()) {
            throw new RuntimeException('Apple Wallet no está configurado.');
        }

        $files = [
            'pass.json' => json_encode($this->pass($participant), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'icon.png' => PassImages::png(29, 29),
            'icon@2x.png' => PassImages::png(58, 58),
            'icon@3x.png' => PassImages::png(87, 87),
            'logo.png' => PassImages::png(160, 50),
            'logo@2x.png' => PassImages::png(320, 100),
        ];
        $files['manifest.json'] = json_encode(array_map(fn (string $data) => sha1($data), $files), JSON_UNESCAPED_SLASHES);
        $files['signature'] = $this->sign($files['manifest.json']);

        $zipPath = tempnam(sys_get_temp_dir(), 'pkpass');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        foreach ($files as $name => $data) {
            $zip->addFromString($name, $data);
        }
        $zip->close();

        try {
            return (string) file_get_contents($zipPath);
        } finally {
            @unlink($zipPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function pass(RegistrationParticipant $participant): array
    {
        $config = config('crm.wallet');
        $entries = $this->schedule->for($participant);
        $first = $entries->first();
        [$r, $g, $b] = PassImages::rgb((string) $config['background']);
        $barcode = [
            'format' => 'PKBarcodeFormatQR',
            'message' => $participant->checkin_code,
            'messageEncoding' => 'iso-8859-1',
            'altText' => $participant->checkin_code,
        ];

        $pass = [
            'formatVersion' => 1,
            'passTypeIdentifier' => $config['apple']['pass_type_id'],
            'teamIdentifier' => $config['apple']['team_id'],
            'serialNumber' => $participant->checkin_code,
            'organizationName' => $config['organization'],
            'description' => 'Ingreso a '.$config['event_name'],
            'logoText' => $config['event_name'],
            'backgroundColor' => "rgb({$r},{$g},{$b})",
            'foregroundColor' => 'rgb(255,255,255)',
            'labelColor' => 'rgb(255,214,170)',
            'barcodes' => [$barcode],
            'barcode' => $barcode,
            'eventTicket' => [
                'primaryFields' => [['key' => 'name', 'label' => 'PARTICIPANTE', 'value' => $participant->full_name]],
                'secondaryFields' => [['key' => 'code', 'label' => 'CÓDIGO', 'value' => $participant->checkin_code]],
                'auxiliaryFields' => $first ? [[
                    'key' => 'first',
                    'label' => 'PRIMERA ACTIVIDAD',
                    'value' => $first['name'].' · '.self::when($first),
                ]] : [],
                'backFields' => [
                    [
                        'key' => 'activities',
                        'label' => 'Tus actividades',
                        'value' => $entries->isEmpty()
                            ? 'Aún no tienes actividades con fecha.'
                            : $entries->map(fn (array $e) => '• '.$e['name'].' — '.self::when($e).($e['location'] ? ' · '.$e['location'] : ''))->implode("\n"),
                    ],
                    ['key' => 'help', 'label' => 'Ingreso', 'value' => 'Muestra este código QR en el registro. También puedes dar tu número de documento.'],
                ],
            ],
        ];

        if ($first) {
            // Wallet brings the pass to the lock screen around the first activity.
            $pass['relevantDate'] = $first['start']->toIso8601String();
        }

        return $pass;
    }

    /**
     * @param  array{all_day: bool, start: CarbonImmutable}  $entry
     */
    private static function when(array $entry): string
    {
        $day = $entry['start']->locale('es')->translatedFormat('D j M');

        return $entry['all_day'] ? $day : $day.' '.$entry['start']->format('H:i');
    }

    /**
     * DER-encoded detached CMS signature of the manifest.
     */
    private function sign(string $manifest): string
    {
        $c = config('crm.wallet.apple');
        if (! openssl_pkcs12_read((string) file_get_contents($c['certificate']), $p12, (string) $c['certificate_password'])) {
            throw new RuntimeException('No se pudo abrir el certificado de Apple Wallet. Revisa el archivo .p12 y su contraseña.');
        }

        $dir = sys_get_temp_dir();
        $in = tempnam($dir, 'manifest');
        $out = tempnam($dir, 'signature');
        file_put_contents($in, $manifest);

        try {
            $ok = openssl_cms_sign(
                $in, $out, $p12['cert'], $p12['pkey'], [],
                OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER, $c['wwdr'],
            );
            if (! $ok) {
                throw new RuntimeException('No se pudo firmar el pase de Apple Wallet: '.openssl_error_string());
            }

            return (string) file_get_contents($out);
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }
}
