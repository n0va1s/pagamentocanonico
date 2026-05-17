<?php

namespace App\Http\Controllers;

use App\Enums\Canal;
use App\Models\Membro;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificacaoController extends Controller
{
    public function __construct(
        private NotificationDispatcher $dispatcher
    ) {}

    public function testar(Request $request)
    {
        $request->validate([
            'idt_membro' => ['required', 'integer', 'exists:membros,idt_membro'],
            'canal' => ['required', Rule::enum(Canal::class)],
            'mensagem' => ['nullable', 'string', 'max:1000'],
        ]);

        $membro = Membro::findOrFail($request->idt_membro);
        $mensagem = $request->mensagem
            ?? 'Teste de notificação do sistema '.config('app.name');

        $resultado = $this->dispatcher->sendVia($request->canal, $membro, $mensagem, 'test');

        return back()->with(
            $resultado['success'] ? 'success' : 'error',
            $resultado['success']
                ? 'Notificação enviada com sucesso!'
                : 'Falha: '.($resultado['error'] ?? 'Erro desconhecido')
        );
    }
}
