<?php

namespace App\Services\Plugin;

use App\Models\PluginConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP client for the read-only API of the "EVR CRM Bridge" WordPress add-on (namespace evr-crm/v1).
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
        return match ($response->status()) {
            401, 403 => 'La clave no es válida. Cópiala de nuevo desde WordPress › Ajustes › CRM 360.',
            404 => 'No se encontró la API. Revisa la URL y que el plugin EVR CRM Bridge esté activo.',
            503 => 'El sitio respondió que EVR o WooCommerce no están activos.',
            default => "El sitio respondió con error {$response->status()}: ".str($response->json('message') ?? $response->body())->limit(200),
        };
    }
}
