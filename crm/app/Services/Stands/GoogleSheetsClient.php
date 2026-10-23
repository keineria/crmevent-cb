<?php

namespace App\Services\Stands;

use App\Models\AppSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Talks to the Google Sheets API as a service account. The sheet must be a native Google Sheet
 * shared with the service account's email as Editor.
 */
class GoogleSheetsClient
{
    public const SETTING = 'stands.google_service_account';

    private const API = 'https://sheets.googleapis.com/v4/spreadsheets/';

    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    /**
     * The service account key: the one uploaded in Stand comercial, else the file named in .env.
     *
     * @return array{client_email: string, private_key: string}|null
     */
    public static function account(): ?array
    {
        $json = null;
        if ($stored = AppSetting::get(self::SETTING)) {
            try {
                $json = Crypt::decryptString($stored);
            } catch (Throwable) {
                $json = null;
            }
        }
        if ($json === null) {
            $path = config('crm.stands.google_service_account');
            $json = $path && is_readable($path) ? (string) file_get_contents($path) : null;
        }

        return $json ? self::parseAccount($json) : null;
    }

    /** @return array{client_email: string, private_key: string}|null */
    public static function parseAccount(string $json): ?array
    {
        $data = json_decode($json, true);
        if (! is_array($data) || empty($data['client_email']) || empty($data['private_key']) || ! openssl_pkey_get_private($data['private_key'])) {
            return null;
        }

        return ['client_email' => (string) $data['client_email'], 'private_key' => (string) $data['private_key']];
    }

    public static function configured(): bool
    {
        return self::account() !== null;
    }

    /** Spreadsheet id from a docs.google.com link, or the id itself. */
    public static function spreadsheetId(string $urlOrId): ?string
    {
        $text = trim($urlOrId);
        if (preg_match('#/spreadsheets/d/([A-Za-z0-9_-]{20,})#', $text, $m)) {
            return $m[1];
        }

        return preg_match('/^[A-Za-z0-9_-]{20,}$/', $text) ? $text : null;
    }

    /** @return array{title: string, sheets: list<string>} */
    public function spreadsheet(string $id): array
    {
        $data = $this->send(fn (PendingRequest $http) => $http->get(self::API.$id, ['fields' => 'properties.title,sheets.properties.title']));

        return [
            'title' => (string) data_get($data, 'properties.title', 'Hoja de Google'),
            'sheets' => array_values(array_map(fn ($s) => (string) data_get($s, 'properties.title'), $data['sheets'] ?? [])),
        ];
    }

    /**
     * One tab with values, formulas, formats, merges and drop-down lists.
     *
     * @return array<string, mixed>
     */
    public function grid(string $id, string $sheet): array
    {
        $fields = 'sheets(properties(title,gridProperties),merges,data(columnMetadata(pixelSize),rowData(values('
            .'formattedValue,userEnteredValue,effectiveValue,dataValidation(condition),'
            .'effectiveFormat(backgroundColor,horizontalAlignment,numberFormat,textFormat(bold))))))';
        $data = $this->send(fn (PendingRequest $http) => $http->get(self::API.$id, [
            'ranges' => self::quote($sheet),
            'includeGridData' => 'true',
            'fields' => $fields,
        ]));

        return $data['sheets'][0] ?? throw new RuntimeException("No encontré la pestaña «{$sheet}» en la hoja de Google.");
    }

    /** What is in one cell right now: its formula when it has one, otherwise its value. */
    public function rawCell(string $id, string $sheet, string $cell): ?string
    {
        $data = $this->send(fn (PendingRequest $http) => $http->get(self::API.$id.'/values/'.rawurlencode(self::quote($sheet).'!'.$cell), [
            'valueRenderOption' => 'FORMULA',
        ]));
        $value = $data['values'][0][0] ?? null;

        return $value === null ? null : (string) $value;
    }

    /** Writes one cell. Text is written as is; numbers go as numbers so Google keeps the cell's format. */
    public function write(string $id, string $sheet, string $cell, string|int|float|null $value, bool $raw): void
    {
        $this->send(fn (PendingRequest $http) => $http->withQueryParameters(['valueInputOption' => $raw ? 'RAW' : 'USER_ENTERED'])
            ->put(self::API.$id.'/values/'.rawurlencode(self::quote($sheet).'!'.$cell), [
                'values' => [[$value ?? '']],
            ]));
    }

    private static function quote(string $sheet): string
    {
        return "'".str_replace("'", "''", $sheet)."'";
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        $response = $call(Http::withToken($this->token())->acceptJson()->timeout(20));
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $message = (string) $response->json('error.message', '');
        throw new RuntimeException(match ($response->status()) {
            403 => 'La cuenta de servicio no tiene acceso a la hoja. Compártela con '.(self::account()['client_email'] ?? 'la cuenta de servicio').' como Editor.',
            404 => 'No encontré esa hoja de Google. Revisa el enlace.',
            400 => str_contains($message, 'not supported for this document')
                ? 'Ese archivo es un Excel guardado en Drive. Ábrelo y usa Archivo › Guardar como Hojas de cálculo de Google, y conecta la copia.'
                : 'Google rechazó la solicitud: '.$message,
            default => 'Google respondió con un error ('.$response->status().'): '.$message,
        });
    }

    private function token(): string
    {
        $account = self::account() ?? throw new RuntimeException('Falta la clave de la cuenta de servicio de Google.');

        return Cache::remember('stands.google_token.'.md5($account['client_email']), 3000, function () use ($account) {
            $now = time();
            $header = self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = self::base64url(json_encode([
                'iss' => $account['client_email'],
                'scope' => self::SCOPE,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            openssl_sign("{$header}.{$claims}", $signature, $account['private_key'], OPENSSL_ALGO_SHA256);

            $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "{$header}.{$claims}.".self::base64url($signature),
            ]);
            if (! $response->successful() || ! $response->json('access_token')) {
                throw new RuntimeException('Google no aceptó la clave de la cuenta de servicio. Revisa que la Google Sheets API esté activada en ese proyecto.');
            }

            return (string) $response->json('access_token');
        });
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
