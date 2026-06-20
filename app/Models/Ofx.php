<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ofx extends Model
{
    use HasFactory;


    protected $table = 'ofx';

    protected $primaryKey = 'idt_ofx';

    public $timestamps = true;

    protected $fillable = [
        'des_arquivo',
        'cod_banco',
        'num_conta',
        'dat_inicio',
        'dat_fim',
        'qtd_transacao',
        'val_total',
    ];

    protected $casts = [
        'dat_inicio' => 'date',
        'dat_fim' => 'date',
        'val_total' => 'decimal:2',
        'qtd_transacao' => 'integer',
    ];

    public function transacoes(): HasMany
    {
        return $this->hasMany(Transacao::class, 'idt_ofx');
    }

    public function resumos(): HasMany
    {
        return $this->hasMany(Resumo::class, 'idt_ofx');
    }
}
