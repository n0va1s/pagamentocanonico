<?php

namespace App\Mail;

use App\Models\Membro;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BoasVindasMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Membro $membro) {}

    public function build()
    {
        return $this->subject('Bem-vindo(a) ao ' . config('app.name') . '! 🎉')
            ->html($this->getHtmlContent());
    }

    protected function getHtmlContent(): string
    {
        $nome = explode(' ', trim($this->membro->nom_membro))[0];
        $associacao = $this->membro->associacao?->nom_associacao ?? 'nossa Associação';
        $appName = config('app.name');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Bem-vindo(a)</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f4f7f6; color: #333333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 30px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .header { background: linear-gradient(135deg, #1e3a8a, #3b82f6); padding: 40px 20px; text-align: center; color: #ffffff; }
        .header h1 { margin: 0; font-size: 28px; font-weight: 700; }
        .content { padding: 30px 40px; line-height: 1.6; }
        .content h2 { color: #1e3a8a; margin-top: 0; }
        .cta-box { background-color: #f0f7ff; border-left: 4px solid #3b82f6; padding: 20px; margin: 25px 0; border-radius: 0 8px 8px 0; }
        .cta-box h3 { margin-top: 0; color: #1e3a8a; }
        .footer { background: #f8fafc; padding: 20px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Bem-vindo(a) ao {$appName}! 🎉</h1>
        </div>
        <div class="content">
            <h2>Olá, {$nome}!</h2>
            <p>É com grande satisfação que informamos que a sua adesão à <strong>{$associacao}</strong> foi aprovada com sucesso! Agora você faz parte do nosso quadro oficial de associados.</p>
            
            <div class="cta-box">
                <h3>💳 Contribuição Associativa</h3>
                <p>Para manter sua associação regularizada e usufruir de todos os benefícios, o pagamento de suas taxas de associação deve ser efetuado através de transferência bancária ou Pix para a conta da associação.</p>
                <p><strong>Importante:</strong> Certifique-se de realizar o pagamento a partir de uma conta em seu nome ou informe à administração o nome exato registrado em seu extrato bancário (OFX) para que a conciliação ocorra de forma automática no sistema.</p>
            </div>
            
            <p>Estamos muito felizes em ter você conosco. Em caso de qualquer dúvida, fique à vontade para responder a este e-mail ou entrar em contato com a diretoria.</p>
            
            <p>Atenciosamente,<br><strong>Diretoria de {$associacao}</strong></p>
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
