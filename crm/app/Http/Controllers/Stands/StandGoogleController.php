<?php

namespace App\Http\Controllers\Stands;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\StandWorkbook;
use App\Services\Stands\GoogleSheetsClient;
use App\Services\Stands\GoogleStandSheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Connects Stand comercial to a tab of a Google Sheet: the service account key, the sheet, and reading it again.
 */
class StandGoogleController extends Controller
{
    public function storeKey(Request $request): RedirectResponse
    {
        $request->validate(['key' => ['required', 'file', 'max:20', 'mimetypes:application/json,text/plain']], [], ['key' => 'clave']);

        $json = (string) file_get_contents($request->file('key')->getRealPath());
        $account = GoogleSheetsClient::parseAccount($json);
        if ($account === null) {
            return back()->withErrors(['key' => 'Ese archivo no es la clave .json de una cuenta de servicio de Google.']);
        }

        AppSetting::put(GoogleSheetsClient::SETTING, Crypt::encryptString($json));
        AuditLog::record('stands.google_key', null, ['cuenta' => $account['client_email']]);

        return back()->with('success', "Clave guardada. Comparte la hoja con {$account['client_email']} como Editor.");
    }

    public function destroyKey(): RedirectResponse
    {
        AppSetting::put(GoogleSheetsClient::SETTING, null);
        AuditLog::record('stands.google_key', null, ['cuenta' => 'quitada']);

        return back()->with('success', 'Clave de Google quitada.');
    }

    public function store(Request $request, GoogleSheetsClient $client, GoogleStandSheet $sheets): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500'],
            'sheet' => ['required', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:120'],
        ], [], ['url' => 'enlace', 'sheet' => 'pestaña']);

        $id = GoogleSheetsClient::spreadsheetId($data['url']);
        if ($id === null) {
            return back()->withErrors(['url' => 'Pega el enlace de la hoja de Google (docs.google.com/spreadsheets/d/…).']);
        }
        if (! GoogleSheetsClient::configured()) {
            return back()->withErrors(['url' => 'Primero hay que subir la clave de la cuenta de servicio de Google.']);
        }

        try {
            $spreadsheet = $client->spreadsheet($id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['url' => $e->getMessage()]);
        }

        $tab = collect($spreadsheet['sheets'])->first(fn (string $t) => mb_strtolower(trim($t)) === mb_strtolower(trim($data['sheet'])));
        if ($tab === null) {
            return back()->withErrors(['sheet' => 'Esa hoja no tiene la pestaña «'.$data['sheet'].'». Tiene: '.implode(', ', $spreadsheet['sheets']).'.']);
        }

        $workbook = StandWorkbook::create([
            'name' => trim($data['name'] ?? '') ?: $tab,
            'source' => StandWorkbook::SOURCE_GOOGLE,
            'google_spreadsheet_id' => $id,
            'google_sheet' => $tab,
            'original_name' => $spreadsheet['title'],
            'sheets' => [],
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        if (! $sheets->pull($workbook)) {
            $error = $workbook->sync_error;
            $workbook->delete();

            return back()->withErrors(['url' => $error]);
        }
        AuditLog::record('stands.google_connected', $workbook, ['hoja' => $spreadsheet['title'], 'pestaña' => $tab]);

        return to_route('stands.show', $workbook)->with('success', "Pestaña «{$tab}» conectada. Los cambios se ven en los dos lados.");
    }

    public function sync(StandWorkbook $workbook, GoogleStandSheet $sheets): RedirectResponse
    {
        abort_unless($workbook->isGoogle(), 404);

        return $sheets->pull($workbook)
            ? back()->with('success', 'Hoja de Google leída de nuevo.')
            : back()->with('error', $workbook->sync_error);
    }
}
