<?php

namespace App\Services\Notifications\Messages;

use App\Enums\TipoNotificacao;
use App\Models\Membro;

class MensagemBuilder
{
    /**
     * Constrói a mensagem adequada para o tipo e canal informados.
     *
     * @param  array<string, mixed>  $dados  Dados extras para interpolação (ex: valor, mês)
     */
    public function construir(TipoNotificacao $tipo, Membro $membro, array $dados = []): string
    {
        return match ($tipo) {
            TipoNotificacao::INADIMPLENTE => $this->inadimplente($membro, $dados),
            TipoNotificacao::ANIVERSARIANTE => $this->aniversariante($membro),
            TipoNotificacao::BOAS_VINDAS => $this->boasVindas($membro),
            TipoNotificacao::CUSTOM => $dados['message'] ?? $dados['mensagem'] ?? '',
            TipoNotificacao::QUITACAO_ANUAL => $this->quitacaoAnual($membro, $dados),
        };
    }

    private function quitacaoAnual(Membro $membro, array $dados): string
    {
        $ano = $dados['ano'] ?? date('Y');

        return implode("\n\n", [
            "Prezado(a) {$membro->nom_membro},",
            "Declaramos, para os devidos fins de direito, que V. Sa. encontra-se em situação de *ADIMPLÊNCIA* perante a Associação dos Servidores da Caixa e da Justiça Eleitoral (ASCAJE) referente a todas as contribuições financeiras ordinárias do ano de *{$ano}*.",
            "Esta declaração confere plena quitação de suas obrigações associativas para o período supracitado.",
            "Agradecemos a sua valiosa parceria e colaboração.",
            "Brasília, " . date('d/m/Y') . ".",
            "Diretoria Financeira\n_ASCAJE_",
        ]);
    }


    private function inadimplente(Membro $membro, array $dados): string
    {
        $primeiroNome = $this->primeiroNome($membro->nom_membro);
        $mes = $dados['mes'] ?? 'mês atual';
        $valor = isset($dados['valor'])
            ? 'R$ '.number_format((float) $dados['valor'], 2, ',', '.')
            : 'valor em aberto';

        return implode("\n\n", [
            "Olá, {$primeiroNome}! 👋",
            "Identificamos que o pagamento referente a *{$mes}* no valor de *{$valor}* ainda não foi registrado em nosso sistema.",
            'Caso já tenha efetuado o pagamento, por favor desconsidere esta mensagem.',
            'Em caso de dúvidas, entre em contato conosco.',
            '_'.config('app.name').'_',
        ]);
    }

    private function aniversariante(Membro $membro): string
    {
        $primeiroNome = $this->primeiroNome($membro->nom_membro);

        return implode("\n\n", [
            "🎂 Feliz aniversário, {$primeiroNome}!",
            'Em nome de toda a equipe do *'.config('app.name').'*, desejamos a você um dia muito especial, repleto de alegrias e realizações.',
            'Que este novo ano de vida traga muitas conquistas! 🎉',
        ]);
    }

    private function boasVindas(Membro $membro): string
    {
        $primeiroNome = $this->primeiroNome($membro->nom_membro);
        $tipo = $membro->tip_associado->label();

        return implode("\n\n", [
            "🎉 Bem-vindo(a), {$primeiroNome}!",
            "É com grande satisfação que recebemos você como *{$tipo}* do *".config('app.name').'*.',
            'Estamos à disposição para qualquer dúvida. Seja muito bem-vindo(a)! 🙌',
        ]);
    }

    private function primeiroNome(string $nomeCompleto): string
    {
        return explode(' ', trim($nomeCompleto))[0];
    }
}
