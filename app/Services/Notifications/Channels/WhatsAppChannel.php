<?php

namespace App\Services\Notifications\Channels;

use App\Enums\TipoNotificacao;
use App\Models\Membro;
use App\Models\Notificacao;
use App\Services\Notifications\Contracts\NotificationChannelInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppChannel implements NotificationChannelInterface
{
    private string $baseUrl;

    private string $apiKey;

    private string $instancia;

    public function __construct()
    {
        $this->baseUrl = config('services.evolution.url', 'http://localhost:8080');
        $this->apiKey = config('services.evolution.api_key', '');
        $this->instancia = config('services.evolution.instance', 'default');
    }

    public function getChannelName(): string
    {
        return 'whatsapp';
    }

    public function send(Membro $membro, string $mensagem, TipoNotificacao $tipo): array
    {
        $telefone = $this->sanitizarTelefone($membro->tel_membro);

        if (empty($telefone)) {
            return ['success' => false, 'error' => 'Número de WhatsApp não cadastrado ou inválido.'];
        }

        try {
            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/message/sendText/{$this->instancia}", [
                'number' => $telefone,
                'options' => [
                    'delay' => 1200,
                    'presence' => 'composing',
                ],
                'textMessage' => ['text' => $mensagem],
            ]);

            $data = $response->json();

            if ($response->successful() && ($data['status'] ?? '') !== 'error') {
                $externalId = $data['key']['id'] ?? $data['id'] ?? null;
                $this->registrar($membro, $mensagem, $tipo, true, $externalId);

                return ['success' => true, 'external_id' => $externalId];
            }

            $erro = $data['message'] ?? $data['error'] ?? 'Erro desconhecido na Evolution API.';
            $this->registrar($membro, $mensagem, $tipo, false, null, $erro);

            return ['success' => false, 'error' => $erro];

        } catch (\Exception $e) {
            Log::error("WhatsAppChannel [{$membro->idt_membro}]: ".$e->getMessage());
            $this->registrar($membro, $mensagem, $tipo, false, null, $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cria a instância na Evolution API (executar uma vez via tinker ou comando).
     */
    public function criarInstancia(): array
    {
        $response = Http::withHeaders([
            'apikey' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/instance/create", [
            'instanceName' => $this->instancia,
            'token' => $this->apiKey,
            'qrcode' => true,
        ]);

        return $response->json() ?? ['error' => 'Sem resposta da API.'];
    }

    /**
     * Retorna o QR Code em base64 para conectar o WhatsApp.
     */
    public function obterQrCode(): ?string
    {
        $response = Http::withHeaders(['apikey' => $this->apiKey])
            ->get("{$this->baseUrl}/instance/connect/{$this->instancia}");

        $data = $response->json();

        return $data['qrcode']['base64'] ?? $data['qrcode'] ?? null;
    }

    private function sanitizarTelefone(?string $telefone): ?string
    {
        if (empty($telefone)) {
            return null;
        }

        $limpo = preg_replace('/\D/', '', $telefone);

        // Adiciona DDI 55 se tiver 10 ou 11 dígitos (formato brasileiro)
        if (strlen($limpo) === 10 || strlen($limpo) === 11) {
            $limpo = '55'.$limpo;
        }

        if (! str_starts_with($limpo, '55') || strlen($limpo) < 12) {
            return null;
        }

        return $limpo;
    }

    private function registrar(
        Membro $membro,
        string $conteudo,
        TipoNotificacao $tipo,
        bool $sucesso,
        ?string $externalId = null,
        ?string $erro = null
    ): void {
        Notificacao::create([
            'idt_membro' => $membro->idt_membro,
            'tip_canal' => $this->getChannelName(),
            'txt_conteudo' => $conteudo,
            'ind_enviada' => $sucesso,
            'num_externo' => $externalId,
            'msg_erro' => $erro,
        ]);
    }
}
