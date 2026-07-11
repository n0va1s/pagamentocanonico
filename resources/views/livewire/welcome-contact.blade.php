<?php

use App\Models\Contato;
use Livewire\Volt\Component;

new class extends Component {
    public string $nome = '';
    public string $email = '';
    public string $mensagem = '';
    public ?int $idt_associacao = null;

    public function submitContact(): void
    {
        $this->idt_associacao = auth()->user()?->membro?->idt_associacao;
        $this->validate([
            'nome' => 'required|string|min:3|max:100',
            'email' => 'required|email|max:100',
            'mensagem' => 'required|string|min:10|max:1000',
            'idt_associacao' => 'required|exists:associacoes,idt_associacao',
        ], [
            'nome.required' => 'O seu nome é obrigatório.',
            'nome.min' => 'O nome deve ter no mínimo 3 caracteres.',
            'email.required' => 'O e-mail é obrigatório.',
            'email.email' => 'Informe um e-mail válido.',
            'mensagem.required' => 'A mensagem é obrigatória.',
            'mensagem.min' => 'A mensagem deve ter no mínimo 10 caracteres.',
            'idt_associacao.required' => 'A associação é obrigatória.',
            'idt_associacao.exists' => 'A associação selecionada é inválida.',
        ]);

        // 1. Salvar no banco de dados
        Contato::create([
            'nome' => $this->nome,
            'email' => $this->email,
            'mensagem' => $this->mensagem,
            'idt_associacao' => $this->idt_associacao,
        ]);

        // 2. Enviar ao Telegram
        $botToken = config('services.telegram.bot_token', '');
        $chatId = config('services.telegram.contact_chat_id') ?: env('TELEGRAM_CONTACT_CHAT_ID', '');
        $enviouTelegram = false;

        if (filled($botToken) && filled($chatId)) {
            $texto = "📩 <b>Nova Mensagem de Contato</b>\n" .
                     "👤 <b>Nome:</b> {$this->nome}\n" .
                     "✉️ <b>E-mail:</b> {$this->email}\n" .
                     "💬 <b>Mensagem:</b>\n{$this->mensagem}";
            try {
                $response = \Illuminate\Support\Facades\Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $texto,
                    'parse_mode' => 'HTML'
                ]);
                $enviouTelegram = $response->successful();
            } catch (\Exception $e) {
                // ignore
            }
        }

        if ($enviouTelegram) {
            \Flux::toast(variant: 'success', text: 'Mensagem enviada com sucesso para a administração!');
        } else {
            // Se o Telegram falhar, mas salvou no banco, ainda assim informamos que salvou
            \Flux::toast(variant: 'warning', text: 'Mensagem registrada localmente. (Falha temporária ao enviar ao Telegram)');
        }
        $this->reset(['nome', 'email', 'mensagem', 'idt_associacao']);
    }

    public function mount(): void
    {
        $this->idt_associacao = auth()->user()?->membro?->idt_associacao;
    }

    public function with(): array
    {
        return [
            'associacoes' => \App\Models\Associacao::orderBy('nom_associacao')->get(),
        ];
    }
}; ?>

<div class="w-full max-w-xl mx-auto">
    <form wire:submit="submitContact" class="space-y-4">
        <flux:input 
            label="Seu Nome" 
            wire:model="nome" 
            placeholder="Digite seu nome completo" 
            required 
            icon="user"
        />
        <flux:input 
            label="Seu E-mail" 
            type="email" 
            wire:model="email" 
            placeholder="exemplo@email.com" 
            required 
            icon="envelope"
        />
        
        <flux:select 
            label="Associação" 
            wire:model="idt_associacao" 
            placeholder="Selecione a associação..." 
            disabled
        >
            @foreach($associacoes as $assoc)
                <flux:select.option value="{{ $assoc->idt_associacao }}">{{ $assoc->nom_associacao }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:textarea 
            label="Sua Mensagem" 
            wire:model="mensagem" 
            rows="5" 
            placeholder="Escreva como a administração pode lhe ajudar..." 
            required 
        />
        
        <flux:button 
            type="submit" 
            variant="primary" 
            class="w-full" 
            icon="paper-airplane"
            wire:loading.attr="disabled"
        >
            <span wire:loading.remove>Enviar Mensagem</span>
            <span wire:loading>Enviando...</span>
        </flux:button>
    </form>
</div>
