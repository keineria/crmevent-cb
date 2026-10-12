<?php

namespace Tests\Feature\Portal;

use App\Mail\CheckinQrMail;
use App\Models\EventActivity;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Services\Passes\PassLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class PassTest extends TestCase
{
    use RefreshDatabase;

    private RegistrationParticipant $ana;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wallet-'.uniqid();
        mkdir($this->dir);

        EventActivity::create(['evr_id' => 1, 'type' => 'conference', 'name' => 'Conferencias', 'destination' => 'Teatro Jorge Isaacs, Cali',
            'event_date' => '2027-09-30', 'start_time' => '08:00:00', 'end_time' => '17:30:00', 'status' => 'publish']);

        $order = Registration::create(['wc_order_id' => 501, 'number' => '501', 'status' => 'completed', 'currency' => 'COP', 'total' => 180000,
            'billing' => ['email' => 'ana@example.com'], 'ordered_at' => '2026-09-01 10:00:00']);
        $this->ana = RegistrationParticipant::create(['registration_id' => $order->id, 'evr_order_participant_id' => 10, 'evr_participant_id' => 1,
            'full_name' => 'Ana Pérez', 'email' => 'ana@example.com', 'document_type' => 'cc', 'document_number' => '1020304050']);
        RegistrationActivity::create(['registration_participant_id' => $this->ana->id, 'evr_assignment_id' => 101, 'evr_activity_id' => 1,
            'name' => 'Conferencias', 'type' => 'conference', 'event_date' => '2027-09-30', 'start_time' => '08:00:00', 'status' => 'confirmed', 'price' => 1, 'total' => 1]);
        RegistrationActivity::create(['registration_participant_id' => $this->ana->id, 'evr_assignment_id' => 102, 'evr_activity_id' => 3,
            'name' => 'Salida a Chicoral, Valle', 'type' => 'birding', 'event_date' => '2027-10-02', 'start_time' => '05:00:00', 'status' => 'confirmed', 'price' => 1, 'total' => 1]);
        RegistrationActivity::create(['registration_participant_id' => $this->ana->id, 'evr_assignment_id' => 103, 'evr_activity_id' => 9,
            'name' => 'Retirada', 'type' => 'workshop', 'event_date' => '2027-10-01', 'status' => 'cancelled', 'price' => 1, 'total' => 1]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);

        parent::tearDown();
    }

    public function test_the_calendar_lists_active_activities_in_utc(): void
    {
        $ics = $this->get(PassLinks::calendar($this->ana))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->getContent();

        $this->assertStringContainsString("SUMMARY:Conferencias\r\n", $ics);
        // 08:00 to 17:30 in Bogotá (UTC-5), end time taken from the activity catalogue.
        $this->assertStringContainsString("DTSTART:20270930T130000Z\r\nDTEND:20270930T223000Z", $ics);
        $this->assertStringContainsString('LOCATION:Teatro Jorge Isaacs\, Cali', $ics);
        // No end time known: two hours.
        $this->assertStringContainsString("DTSTART:20271002T100000Z\r\nDTEND:20271002T120000Z", $ics);
        $this->assertStringContainsString('SUMMARY:Salida a Chicoral\, Valle', $ics);
        $this->assertStringNotContainsString('Retirada', $ics);
        $this->assertStringNotContainsString($this->ana->checkin_code, $ics);
    }

    public function test_links_need_their_own_token(): void
    {
        $this->get("/pase/{$this->ana->id}/nope/calendario.ics")->assertNotFound();
        $this->get("/pase/{$this->ana->id}/".PassLinks::token($this->ana, PassLinks::WALLET).'/calendario.ics')->assertNotFound();
        $this->get("/pase/{$this->ana->id}/".PassLinks::token($this->ana, PassLinks::CALENDAR).'/apple')->assertNotFound();
    }

    public function test_wallets_stay_hidden_until_configured(): void
    {
        $this->assertNull(PassLinks::apple($this->ana));
        $this->assertNull(PassLinks::google($this->ana));
        $this->get("/pase/{$this->ana->id}/".PassLinks::token($this->ana, PassLinks::WALLET).'/google')->assertNotFound();

        $html = (new CheckinQrMail($this->ana))->render();
        $this->assertStringNotContainsString('Wallet', $html);
        $this->assertStringContainsString(e(PassLinks::calendar($this->ana)), $html);
        $this->assertStringContainsString('Agregar a Google Calendar', $html);
    }

    public function test_the_portal_offers_the_calendar_without_the_code(): void
    {
        $this->get('/inscritos');
        [$a, $b] = session('portal_challenge');
        $this->post('/inscritos/consultar', ['document' => '1020304050', 'human' => (string) ($a + $b)]);

        $this->get('/inscritos')->assertInertia(fn (Assert $page) => $page
            ->where('people.0.calendar.ics', PassLinks::calendar($this->ana))
            ->where('people.0.calendar.google', fn (string $url) => str_starts_with($url, 'https://calendar.google.com/calendar/r?cid=webcal%3A%2F%2F'))
            ->where('people.0.activities.0.google_calendar', fn (string $url) => str_contains($url, 'dates=20270930T130000Z%2F20270930T223000Z')));
    }

    public function test_apple_wallet_pass_is_signed_and_carries_the_code(): void
    {
        $this->configureApple();
        $url = PassLinks::apple($this->ana);
        $this->assertNotNull($url);

        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/vnd.apple.pkpass');
        file_put_contents("{$this->dir}/pass.pkpass", $response->getContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open("{$this->dir}/pass.pkpass"));
        $pass = json_decode($zip->getFromName('pass.json'), true);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $signature = $zip->getFromName('signature');

        $this->assertSame($this->ana->checkin_code, $pass['barcodes'][0]['message']);
        $this->assertSame('pass.test.birdfair', $pass['passTypeIdentifier']);
        $this->assertSame('Ana Pérez', $pass['eventTicket']['primaryFields'][0]['value']);
        $this->assertStringNotContainsString('Retirada', $pass['eventTicket']['backFields'][0]['value']);
        foreach (['pass.json', 'icon.png', 'logo.png'] as $file) {
            $this->assertSame(sha1($zip->getFromName($file)), $manifest[$file]);
        }

        file_put_contents("{$this->dir}/manifest.json", $zip->getFromName('manifest.json'));
        file_put_contents("{$this->dir}/signature.der", $signature);
        $this->assertTrue(openssl_cms_verify("{$this->dir}/manifest.json", OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY,
            null, [], null, null, null, "{$this->dir}/signature.der", OPENSSL_ENCODING_DER));

        $this->assertStringContainsString('Agregar a Apple Wallet', (new CheckinQrMail($this->ana))->render());
    }

    public function test_google_wallet_link_is_a_signed_jwt_with_the_ticket(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        file_put_contents("{$this->dir}/google.json", json_encode(['client_email' => 'wallet@birdfair.iam.gserviceaccount.com', 'private_key' => $pem]));
        config(['crm.wallet.google.issuer_id' => '3388000000012345678', 'crm.wallet.google.service_account' => "{$this->dir}/google.json"]);

        $location = $this->get(PassLinks::google($this->ana))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://pay.google.com/gp/v/save/', $location);

        [$header, $payload, $signature] = explode('.', substr($location, strlen('https://pay.google.com/gp/v/save/')));
        $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));
        $this->assertSame(1, openssl_verify("{$header}.{$payload}", $decode($signature), openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256));

        $claims = json_decode($decode($payload), true);
        $ticket = $claims['payload']['eventTicketObjects'][0];
        $this->assertSame('savetowallet', $claims['typ']);
        $this->assertSame('3388000000012345678.colombia-birdfair', $claims['payload']['eventTicketClasses'][0]['id']);
        $this->assertSame('3388000000012345678.'.$this->ana->checkin_code, $ticket['id']);
        $this->assertSame($this->ana->checkin_code, $ticket['barcode']['value']);
        $this->assertSame('Ana Pérez', $ticket['ticketHolderName']);
    }

    private function configureApple(): void
    {
        // A stand-in for Apple's WWDR intermediate, and a pass certificate issued by it.
        $caKey = openssl_pkey_new(['private_key_bits' => 2048]);
        $ca = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test WWDR'], $caKey), null, $caKey, 30, [], 1);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Pass Type ID: pass.test.birdfair'], $key), $ca, $caKey, 30, [], 2);
        openssl_pkcs12_export_to_file($cert, "{$this->dir}/pass.p12", $key, 'secreto');
        openssl_x509_export_to_file($ca, "{$this->dir}/wwdr.pem");

        config(['crm.wallet.apple' => [
            'pass_type_id' => 'pass.test.birdfair',
            'team_id' => 'ABCDE12345',
            'certificate' => "{$this->dir}/pass.p12",
            'certificate_password' => 'secreto',
            'wwdr' => "{$this->dir}/wwdr.pem",
        ]]);
    }
}
