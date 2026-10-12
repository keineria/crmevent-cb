<?php

namespace App\Services\Plugin;

use App\Models\PluginConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP client for the API of the "EVR CRM Bridge" WordPress add-on (namespace evr-crm/v1).
 */
class EvrClient
{
    public function __construct(private string $baseUrl, private string $apiKey) {}

    public static function for(PluginConnection $connection): self
    {
        if (! $connection->isConfigured()) {
            throw new RuntimeException('Falta la URL o la clave del plugin.');
        }

        return new self($connection->base_url, $connection->api_key);
    }

    /**
     * Accepts the site URL, the wp-json URL or the full namespace URL shown in WordPress.
     */
    public static function normalizeBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $url = preg_replace('#/evr-crm/v1$#', '', $url);
        $url = preg_replace('#/wp-json$#', '', $url);

        return $url.'/wp-json/evr-crm/v1';
    }

    /**
     * @return array<string, mixed>
     */
    public function ping(): array
    {
        return $this->get('ping');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activities(): array
    {
        return $this->get('activities')['data'] ?? [];
    }

    /**
     * @param  array{updated_at: string|null, order_id: int}  $cursor
     * @return array{data: list<array<string, mixed>>, next_cursor: array{updated_at: string, order_id: int}|null, has_more: bool}
     */
    public function orders(array $cursor, ?string $createdAfter, int $limit = 50): array
    {
        $body = $this->get('orders', array_filter([
            'after_updated' => $cursor['updated_at'],
            'after_order' => $cursor['order_id'] ?: null,
            'created_after' => $createdAfter,
            'limit' => $limit,
        ]));

        return [
            'data' => $body['data'] ?? [],
            'next_cursor' => $body['next_cursor'] ?? null,
            'has_more' => (bool) ($body['has_more'] ?? false),
        ];
    }

    /**
     * Contact details of a plugin participant. Returns the refreshed orders.
     *
     * @param  array<string, string>  $details
     * @return list<array<string, mixed>>
     */
    public function updateParticipant(int $participantId, int $orderParticipantId, array $details, string $crmUser): array
    {
        return $this->post("participants/{$participantId}", [...$details, 'order_participant_id' => $orderParticipantId, 'crm_user' => $crmUser])['orders'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function replaceAssignment(int $assignmentId, int $activityId, string $crmUser): array
    {
        return $this->post("assignments/{$assignmentId}/replace", ['activity_id' => $activityId, 'crm_user' => $crmUser])['orders'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cancelAssignment(int $assignmentId, string $crmUser): array
    {
        return $this->post("assignments/{$assignmentId}/cancel", ['crm_user' => $crmUser])['orders'] ?? [];
    }

    /**
     * Open activities with live free seats, as the plugin's "Nuevo Registro" lists them.
     *
     * @return list<array<string, mixed>>
     */
    public function deskActivities(): array
    {
        return $this->get('desk/activities')['data'] ?? [];
    }

    /**
     * What a new desk registration would charge; takes no seat.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function deskPreview(array $input): array
    {
        return $this->post('desk/preview', $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{order_id: int, orders: list<array<string, mixed>>}
     */
    public function deskRegister(array $input, float $expectedTotal, string $crmUser): array
    {
        $body = $this->post('desk/register', [...$input, 'expected_total' => $expectedTotal, 'crm_user' => $crmUser]);

        return ['order_id' => (int) ($body['order_id'] ?? 0), 'orders' => $body['orders'] ?? []];
    }

    /**
     * Writes are never retried: the plugin may have applied the first attempt.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-CRM-Key' => $this->apiKey])
                ->withToken($this->apiKey)
                ->connectTimeout(10)
                ->timeout(60)
                ->post(self::normalizeBaseUrl($this->baseUrl).'/'.$path, $body);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo conectar con el sitio: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new RuntimeException($this->describe($response));
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        try {
            $response = $this->request()->get(self::normalizeBaseUrl($this->baseUrl).'/'.$path, $query);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo conectar con el sitio: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new RuntimeException($this->describe($response));
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('El sitio respondió algo que no es JSON. ¿Está activo el plugin EVR CRM Bridge?');
        }

        return $json;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['X-CRM-Key' => $this->apiKey])
            ->withToken($this->apiKey)
            ->connectTimeout(10)
            ->timeout(60)
            ->retry(2, 1000, throw: false);
    }

    private function describe(Response $response): string
    {
        if ($response->json('code') === 'rest_no_route') {
            return 'El plugin EVR CRM Bridge de WordPress no tiene esta función. Actualízalo a la versión 1.1 o superior.';
        }

        return match ($response->status()) {
            401, 403 => 'La clave no es válida. Cópiala de nuevo desde WordPress › Ajustes › CRM 360.',
            404 => $response->json('code') === 'evr_crm_not_found' ? (string) $response->json('message') : 'No se encontró la API. Revisa la URL y que el plugin EVR CRM Bridge esté activo.',
            409, 422 => str($response->json('message') ?? 'El plugin rechazó el cambio.')->limit(300)->toString(),
            503 => 'El sitio respondió que EVR o WooCommerce no están activos.',
            default => "El sitio respondió con error {$response->status()}: ".str($response->json('message') ?? $response->body())->limit(200),
        };
    }
}
