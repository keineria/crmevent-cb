<?php

namespace App\Http\Controllers\CheckIn;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PluginConnection;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Services\CheckIn\ParticipantRecord;
use App\Services\Plugin\EvrClient;
use App\Services\Plugin\RegistrationSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Desk changes to a participant. The plugin applies them with its own rules (seats, history) and the
 * CRM copies the result back at once, so nothing is written here that the plugin did not accept.
 */
class ParticipantEditController extends Controller
{
    public function __construct(private RegistrationSync $sync) {}

    public function details(Request $request, RegistrationParticipant $participant): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'whatsapp' => ['nullable', 'string', 'max:32', 'regex:/^\+?[\d\s\-().]{8,24}$/'],
            'shirt_size' => ['nullable', Rule::in(['S', 'M', 'L', 'XL', 'XXL'])],
            'meal_type' => ['nullable', Rule::in(['traditional', 'vegetarian', 'vegan'])],
            'allergies' => ['nullable', 'string', 'max:500'],
            'entity' => ['nullable', 'string', 'max:191'],
        ], ['whatsapp.regex' => 'Escribe el WhatsApp con indicativo, por ejemplo +573001234567.']);

        if ($participant->evr_participant_id < 1) {
            return back()->with('error', 'Esta inscripción no está enlazada con un participante del plugin.');
        }

        // The plugin requires a valid email and keeps the entity per order: leave them as they are when left blank.
        $details = array_filter(array_map(fn ($v) => (string) ($v ?? ''), $data), fn ($v, $k) => $v !== '' || ! in_array($k, ['email', 'entity'], true), ARRAY_FILTER_USE_BOTH);

        return $this->apply($request, $participant, 'participant.updated', ['campos' => array_keys($details)], 'Datos actualizados.',
            fn (EvrClient $client) => $client->updateParticipant($participant->evr_participant_id, $participant->evr_order_participant_id, $details, $request->user()->name));
    }

    public function replace(Request $request, RegistrationActivity $activity): RedirectResponse
    {
        $data = $request->validate(['activity_id' => ['required', 'integer', 'min:1']]);

        if ($refused = $this->refuse($activity)) {
            return back()->with('error', $refused);
        }

        return $this->apply($request, $activity->participant, 'activity.replaced', ['de' => $activity->name, 'a' => $data['activity_id']],
            "Se cambió «{$activity->name}». El pago y los valores históricos se conservaron.",
            fn (EvrClient $client) => $client->replaceAssignment($activity->evr_assignment_id, (int) $data['activity_id'], $request->user()->name));
    }

    public function cancel(Request $request, RegistrationActivity $activity): RedirectResponse
    {
        if ($refused = $this->refuse($activity)) {
            return back()->with('error', $refused);
        }

        return $this->apply($request, $activity->participant, 'activity.cancelled', ['actividad' => $activity->name],
            "Se retiró «{$activity->name}» y su cupo quedó libre. El pago no cambia.",
            fn (EvrClient $client) => $client->cancelAssignment($activity->evr_assignment_id, $request->user()->name));
    }

    /**
     * Open activities with free seats, for the change and add pickers.
     */
    public function available(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->client()->deskActivities()]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * What adding these activities would charge, before anything is reserved.
     */
    public function preview(Request $request, RegistrationParticipant $participant): JsonResponse
    {
        $input = $this->deskInput($request, $participant);

        try {
            return response()->json($this->client()->deskPreview($input));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * New order for the person, exactly like "Nuevo Registro" in the plugin (payment collected at the desk, or free).
     */
    public function add(Request $request, RegistrationParticipant $participant): RedirectResponse
    {
        $input = $this->deskInput($request, $participant);
        $expected = (float) $request->validate(['expected_total' => ['required', 'numeric', 'min:0']])['expected_total'];

        try {
            $result = $this->client()->deskRegister($input, $expected, $request->user()->name);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->sync->applyOrders($result['orders']);
        AuditLog::record('activity.added', $participant, ['actividades' => $input['activity_ids'], 'pedido' => $result['order_id'], 'sin_costo' => $input['free']]);

        return back()->with('success', 'Actividades agregadas en un pedido nuevo'.($input['free'] ? ' sin costo.' : '. Recuerda cobrar el valor mostrado.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function deskInput(Request $request, RegistrationParticipant $participant): array
    {
        $data = $request->validate([
            'activity_ids' => ['required', 'array', 'min:1', 'max:20'],
            'activity_ids.*' => ['integer', 'min:1'],
            'entity' => ['required', 'string', 'max:191'],
            'free' => ['boolean'],
        ], ['entity.required' => 'Escribe la ENTIDAD; el plugin la pide para cada registro.']);

        $document = strtolower((string) $participant->document_type);
        abort_unless(array_key_exists($document, ParticipantRecord::DOCUMENT_TYPES) && filled($participant->document_number), 422,
            'La persona no tiene un tipo y número de documento válidos para el plugin.');

        return [
            'full_name' => $participant->full_name,
            'document_type' => $document,
            'document_number' => $participant->document_number,
            'email' => (string) $participant->email,
            'whatsapp' => (string) $participant->whatsapp,
            'shirt_size' => (string) $participant->shirt_size,
            'meal_type' => (string) $participant->meal_type,
            'allergies' => (string) $participant->allergies,
            'entity' => $data['entity'],
            'activity_ids' => array_values(array_unique(array_map('intval', $data['activity_ids']))),
            'free' => (bool) ($data['free'] ?? false),
        ];
    }

    private function refuse(RegistrationActivity $activity): ?string
    {
        if (! in_array($activity->status, RegistrationActivity::ACTIVE_STATUSES, true)) {
            return "«{$activity->name}» ya no está activa.";
        }
        if ($activity->checkIn()->exists()) {
            return "La persona ya ingresó a «{$activity->name}». Deshaz el ingreso antes de cambiarla.";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $audit
     * @param  callable(EvrClient): list<array<string, mixed>>  $write
     */
    private function apply(Request $request, RegistrationParticipant $participant, string $action, array $audit, string $success, callable $write): RedirectResponse
    {
        try {
            $orders = $write($this->client());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->sync->applyOrders($orders);
        AuditLog::record($action, $participant, $audit);

        return back()->with('success', $success);
    }

    private function client(): EvrClient
    {
        return EvrClient::for(PluginConnection::current());
    }
}
