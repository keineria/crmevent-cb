<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\ReconcilePluginOrders;
use App\Jobs\SyncPluginRegistrations;
use App\Models\AuditLog;
use App\Models\PluginConnection;
use App\Models\Registration;
use App\Services\Plugin\EvrClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PluginConnectionController extends Controller
{
    public function edit(): Response
    {
        $c = PluginConnection::current();

        return Inertia::render('settings/plugin', [
            'connection' => [
                'base_url' => $c->base_url,
                'has_key' => filled($c->api_key),
                'created_after' => $c->created_after?->toDateString(),
                'enabled' => $c->enabled,
                'last_synced_at' => $c->last_synced_at?->toIso8601String(),
                'last_status' => $c->last_status,
                'last_error' => $c->last_error,
                'last_result' => $c->last_result,
                'last_reconciled_at' => $c->last_reconciled_at?->toIso8601String(),
                'last_reconcile_result' => $c->last_reconcile_result,
            ],
            'counts' => [
                'registrations' => Registration::count(),
                'paid' => Registration::whereIn('status', Registration::PAID_STATUSES)->count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'base_url' => ['required', 'url:https', 'max:255'],
            'api_key' => ['nullable', 'string', 'min:20', 'max:200'],
            'created_after' => ['nullable', 'date_format:Y-m-d'],
            'enabled' => ['boolean'],
        ], [], ['base_url' => 'URL del sitio', 'api_key' => 'clave', 'created_after' => 'fecha inicial']);

        $connection = PluginConnection::current();
        $connection->fill([
            'base_url' => EvrClient::normalizeBaseUrl($data['base_url']),
            'created_after' => $data['created_after'] ?? null,
            'enabled' => $data['enabled'] ?? false,
            'updated_by' => $request->user()->id,
        ]);
        if (filled($data['api_key'] ?? null)) {
            $connection->api_key = trim($data['api_key']);
        }

        // Changing the site or the start date means reading everything again from the beginning.
        if ($connection->isDirty(['base_url', 'created_after'])) {
            $connection->resetCursor();
        }

        $changed = array_keys($connection->getDirty());
        $connection->save();
        AuditLog::record('plugin-connection.updated', $connection, ['fields' => array_values(array_diff($changed, ['api_key'])), 'key_changed' => in_array('api_key', $changed, true)]);

        return back()->with('success', 'Conexión guardada.');
    }

    public function test(): RedirectResponse
    {
        try {
            $ping = EvrClient::for(PluginConnection::current())->ping();
        } catch (Throwable $e) {
            AuditLog::record('plugin-connection.tested', PluginConnection::current(), ['resultado' => 'error']);

            return back()->with('error', $e->getMessage());
        }
        AuditLog::record('plugin-connection.tested', PluginConnection::current(), ['resultado' => ($ping['evr_active'] ?? false) && ($ping['woocommerce'] ?? false) ? 'correcto' : 'plugin inactivo']);

        if (! ($ping['evr_active'] ?? false) || ! ($ping['woocommerce'] ?? false)) {
            return back()->with('error', 'El sitio responde, pero EVR o WooCommerce no están activos.');
        }

        return back()->with('success', 'Conexión correcta con '.($ping['site'] ?? 'el sitio').'.');
    }

    public function sync(Request $request): RedirectResponse
    {
        $connection = PluginConnection::current();
        if (! $connection->isConfigured()) {
            return back()->with('error', 'Guarda primero la URL y la clave.');
        }

        if ($request->boolean('from_start')) {
            $connection->resetCursor();
            $connection->save();
        }

        SyncPluginRegistrations::dispatch();
        AuditLog::record('plugin-connection.synced', $connection, $request->boolean('from_start') ? ['desde_el_inicio' => true] : []);

        return back()->with('success', 'Sincronización en curso. Las inscripciones aparecerán en unos minutos.');
    }

    /**
     * Removes from the CRM the orders deleted or trashed in WordPress (test purchases, mostly), without waiting
     * for the hourly check.
     */
    public function reconcile(): RedirectResponse
    {
        $connection = PluginConnection::current();
        if (! $connection->isConfigured()) {
            return back()->with('error', 'Guarda primero la URL y la clave.');
        }

        ReconcilePluginOrders::dispatch();
        AuditLog::record('plugin-connection.reconciled', $connection);

        return back()->with('success', 'Revisando los pedidos con WordPress. En unos minutos desaparecen los que se borraron allá.');
    }
}
