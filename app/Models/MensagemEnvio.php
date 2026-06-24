<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MensagemEnvio extends Model
{
    use HasFactory;

    protected $table = 'mensagem_envio';
    protected $primaryKey = 'idt_mensagem_envio';

    protected $fillable = [
        'idt_mensagem',
        'nom_destinatario',
        'tel_destinatario',
        'nom_responsavel',
        'ind_enviado',
        'dat_envio',
    ];

    protected $casts = [
        'ind_enviado' => 'boolean',
        'dat_envio' => 'datetime',
    ];

    public function mensagem()
    {
        return $this->belongsTo(Mensagem::class, 'idt_mensagem', 'idt_mensagem');
    }
}
