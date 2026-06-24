<?php

namespace App\Mail;

use App\Models\Membro;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LembretePagamentoMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $dados  [mes => string, valor => float]
     */
    public function __construct(public Membro $membro, public array $dados) {}

    public function build()
    {
        return $this->subject('[' . config('app.name') . '] Pendência financeira')
            ->html($this->getHtmlContent());
    }

    protected function getHtmlContent(): string
    {
        $nome = explode(' ', trim($this->membro->nom_membro))[0];
        $mes = $this->dados['mes'] ?? 'mês anterior';
        $valor = isset($this->dados['valor'])
            ? 'R$ ' . number_format((float) $this->dados['valor'], 2, ',', '.')
            : 'valor em aberto';
        $associacao = $this->membro->associacao?->nom_associacao ?? 'nossa Associação';
        $appName = config('app.name');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Aviso de Débito</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #fff5f5; color: #333333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 30px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .header { background: linear-gradient(135deg, #dc2626, #f87171); padding: 40px 20px; text-align: center; color: #ffffff; }
        .header h1 { margin: 0; font-size: 28px; font-weight: 700; }
        .content { padding: 30px 40px; line-height: 1.6; }
        .content h2 { color: #dc2626; margin-top: 0; }
        .alert-box { background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 20px; margin: 25px 0; border-radius: 0 8px 8px 0; }
        .alert-box h3 { margin-top: 0; color: #991b1b; }
        .footer { background: #fff5f5; padding: 20px; text-align: center; font-size: 12px; color: #b91c1c; opacity: 0.8; border-top: 1px solid #fecaca; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Aviso de Débito ⚠️</h1>
        </div>
        <div class="content">
            <h2>Olá, {$nome}!</h2>
            <p>Identificamos que o pagamento da contribuição associativa para a <strong>{$associacao}</strong> referente ao período de <strong>{$mes}</strong> no valor de <strong>{$valor}</strong> ainda não consta como liquidada em nosso sistema.</p>
            
            <div class="alert-box">
                <h3>💡 Como regularizar?</h3>
                <p>Por favor, efetue a transferência bancária ou Pix correspondente à taxa de associação. Se você já realizou o pagamento, sugerimos que envie o comprovante ou entre em contato para que possamos validar e atualizar seu registro.</p>
            </div>
            
            <p>Caso já tenha efetuado o pagamento nas últimas horas, por favor desconsidere esta mensagem. Agradecemos sua compreensão e colaboração para manter a nossa associação forte.</p>
            
            <p>Atenciosamente,<br><strong>Diretoria Financeira de {$associacao}</strong></p>
        </div>
        <div class="footer">
            <p>Este é um e-mail automático enviado por {$appName}.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
