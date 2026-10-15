<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegraClassificacaoCriterio extends Model
{
    use HasFactory;

    protected $table = 'regras_classificacao_criterios';

    protected $primaryKey = 'idt_criterio';

    protected $fillable = [
        'idt_regra',
        'tip_match',
        'val_match',
    ];

    public function regra(): BelongsTo
    {
        return $this->belongsTo(RegraClassificacao::class, 'idt_regra');
    }
}
