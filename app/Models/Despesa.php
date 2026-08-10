<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Despesa extends Model
{
    use HasFactory;

    protected $table = 'despesas';
    protected $primaryKey = 'idt_despesa';

    protected $fillable = [
        'idt_transacao',
        'arq_despesa_1',
        'arq_despesa_2',
        'arq_despesa_3',
        'arq_pagamento',
    ];

    /**
     * Get the transaction that owns the expense files.
     */
    public function transacao()
    {
        return $this->belongsTo(Transacao::class, 'idt_transacao');
    }
}
