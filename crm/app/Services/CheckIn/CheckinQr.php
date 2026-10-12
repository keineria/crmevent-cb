<?php

namespace App\Services\CheckIn;

use App\Models\RegistrationParticipant;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Draws the participant's check-in QR. It only holds the code, so a lost phone or a photo of it gives nothing away.
 */
class CheckinQr
{
    public function png(RegistrationParticipant $participant, int $scale = 10): string
    {
        return (new QRCode(new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'scale' => $scale,
        ])))->render($participant->checkin_code);
    }

    public function svg(RegistrationParticipant $participant): string
    {
        return (new QRCode(new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'svgAddXmlHeader' => false,
            'connectPaths' => true,
        ])))->render($participant->checkin_code);
    }
}
