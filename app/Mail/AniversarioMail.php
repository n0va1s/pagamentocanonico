<?php

namespace App\Mail;

use App\Models\Membro;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AniversarioMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Membro $membro) {}

    public function build()
    {
        return $this->subject('Feliz aniversário! 🎂 - ' . config('app.name'))
            ->html($this->getHtmlContent());
    }

    protected function getHtmlContent(): string
    {
        $nome = explode(' ', trim($this->membro->nom_membro))[0];
        $appName = config('app.name');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Feliz Aniversário</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #fdfaf6; color: #333333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 30px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .header { background: linear-gradient(135deg, #db2777, #f43f5e); padding: 40px 20px; text-align: center; color: #ffffff; }
        .header h1 { margin: 0; font-size: 28px; font-weight: 700; }
        .content { padding: 30px 40px; line-height: 1.6; text-align: center; }
        .content h2 { color: #db2777; margin-top: 0; }
        .cake { font-size: 50px; margin: 20px 0; }
        .footer { background: #fdfaf6; padding: 20px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #f1f5f9; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Feliz Aniversário! 🎂</h1>
        </div>
        <div class="content">
            <div class="cake">🎉🍰🎈</div>
            <h2>Parabéns, {$nome}!</h2>
            <p>Em nome de toda a equipe do <strong>{$appName}</strong>, desejamos a você um dia muito especial, repleto de alegrias, saúde, paz e realizações.</p>
            <p>Que este novo ciclo que se inicia hoje traga muitas felicidades e conquistas para a sua vida. Agradecemos por fazer parte da nossa jornada!</p>
            <br>
            <p>Com carinho,<br><strong>Equipe {$appName}</strong></p>
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
