<?php

namespace App\Services\Connections;

use Exception;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EvolutionService
{
    protected string $url;
    protected string $key;

    public function __construct()
    {
        $this->url = env('EVOLUTION_API_URL');
        $this->key = env('EVOLUTION_API_KEY');
    }

    protected function request(string $method, string $endpoint, array $data = []): array
    {
        try {
            $response = Http::withHeaders([
                'apikey' => $this->key,
                'Content-Type' => 'application/json',
            ])->timeout(30)->$method("{$this->url}/{$endpoint}", $data);

            if ($response->failed()) {
                Log::error("Evolution API Error: {$endpoint}", [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                throw new Exception("Erro na API Evolution: " . ($response->json()['message'] ?? 'Erro desconhecido'));
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::critical("Falha de conexão com Evolution: {$e->getMessage()}");
            throw $e;
        }
    }

    public function createInstance(string $name, string $phone)
    {
        $payload = [
            'instanceName' => $name,
            'integration' => 'WHATSAPP-BAILEYS',
            'number' => $phone,
            'token' => bin2hex(random_bytes(10)),
            'qrcode' => true,
            'groupsIgnore' => true,
            'readMessages' => true,
        ];

        $webhookUrl = env('EVOLUTION_WEBHOOK');
        if ($webhookUrl) {
            $payload['webhook'] = [
                'url' => $webhookUrl,
                'base64' => true,
                'headers' => [
                    'authorization' => env('EVOLUTION_AUTH_TOKEN'),
                    'Content-Type' => 'application/json',
                ],
                'events' => [
                    'APPLICATION_STARTUP',
                    'MESSAGES_UPSERT',
                    'CONNECTION_UPDATE',
                ],
            ];
        }

        return $this->request('post', 'instance/create', $payload);
    }

    public function getInstanceStatus(string $name)
    {
        return $this->request('get', "instance/connectionState/{$name}");
    }

    public function logoutInstance(string $name)
    {
        try {
            $response = Http::withHeaders([
                'apikey' => $this->key,
                'Content-Type' => 'application/json',
            ])->delete("{$this->url}/instance/logout/{$name}");

            return $response->json();
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            Notification::make()->title('Erro')->danger()->body('Erro ao desconectar instância')->send();

            return response()->json([
                'status' => 'error',
                'message' => 'Falha ao desconectar instancia',
            ]);
        }
    }

    public function deleteInstance(string $name)
    {
        return $this->request('delete', "instance/delete/{$name}");
    }

    public function getQrCode(string $instanceName): ?array
    {
        try {
            $response = $this->request('get', "instance/connect/{$instanceName}");

            if (isset($response['base64'])) {
                return [
                    'base64' => $response['base64'],
                    'code'   => $response['code'] ?? null,
                    'count'  => $response['count'] ?? 0,
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::info("Instância {$instanceName} possivelmente já conectada ou erro ao buscar QR.");
            return null;
        }
    }

    public function refreshConnection(string $instanceName): array
    {
        $this->request('delete', "instance/logout/{$instanceName}");
        usleep(500000);
        return $this->getQrCode($instanceName);
    }
}
