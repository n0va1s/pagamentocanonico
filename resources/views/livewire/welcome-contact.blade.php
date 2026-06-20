<?php

use App\Models\Contato;
use App\Services\Notifications\Channels\TelegramChannel;
use Livewire\Volt\Component;

new class extends Component {
    public string $nome = '';
    public string $email = '';
    public string $mensagem = '';

    public function submitContact(): void
    {
        $this->validate([
            'nome' => 'required|string|min:3|max:100',
            'email' => 'required|email|max:100',
            'mensagem' => 'required|string|min:10|max:1000',
        ], [
            'nome.required' => 'O seu nome é obrigatório.',
            'nome.min' => 'O nome deve ter no mínimo 3 caracteres.',
            'email.required' => 'O e-mail é obrigatório.',
            'email.email' => 'Informe um e-mail válido.',
            'mensagem.required' => 'A mensagem é obrigatória.',
            'mensagem.min' => 'A mensagem deve ter no mínimo 10 caracteres.',
        ]);

        // 1. Salvar no banco de dados
        Contato::create([
            'nome' => $this->nome,
            'email' => $this->email,
            'mensagem' => $this->mensagem,
        ]);

        // 2. Enviar ao Telegram
        $telegram = app(TelegramChannel::class);
        $res = $telegram->sendContactRequest($this->nome, $this->email, $this->mensagem);

        if ($res['success']) {
            $this->dispatch('toast', message: 'Mensagem enviada com sucesso para a administração!', variant: 'success');
            $this->reset(['nome', 'email', 'mensagem']);
        } else {
            // Se o Telegram falhar, mas salvou no banco, ainda assim informamos que salvou
            $this->dispatch('toast', message: 'Mensagem registrada localmente. (Falha temporária ao enviar ao Telegram)', variant: 'warning');
            $this->reset(['nome', 'email', 'mensagem']);
        }
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
