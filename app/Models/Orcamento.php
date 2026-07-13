<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Orcamento extends Model
{
    use HasFactory;

    protected $table = 'orcamentos';
    protected $primaryKey = 'idt_orcamento';

    protected $fillable = [
        'idt_transacao',
        'arq_orcamento_1',
        'arq_orcamento_2',
        'arq_orcamento_3',
    ];

    /**
     * Get the transaction that owns the budget files.
     */
    public function transacao()
    {
        return $this->belongsTo(Transacao::class, 'idt_transacao');
    }
}
