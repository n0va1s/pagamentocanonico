<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notificacao extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'notificacoes';

    protected $primaryKey = 'idt_notificacao';

    public $timestamps = true;

    protected $fillable = [
        'idt_membro',
        'tip_canal',
        'txt_conteudo',
        'ind_enviada',
        'num_externo',
        'msg_erro',
    ];

    public function membro(): BelongsTo
    {
        return $this->belongsTo(Membro::class, 'idt_membro');
    }
}
