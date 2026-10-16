<?php

namespace App\Models;

use App\Enums\EfeitoRegra;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegraClassificacao extends Model
{
    use HasFactory;

    protected $table = 'regras_classificacao';

    protected $primaryKey = 'idt_regra';

    protected $fillable = [
        'idt_associacao',
        'nom_regra',
        'des_efeito',
        'ind_ativo',
    ];

    protected $casts = [
        'des_efeito' => EfeitoRegra::class,
        'ind_ativo' => 'boolean',
    ];

    public function criterios(): HasMany
    {
        return $this->hasMany(RegraClassificacaoCriterio::class, 'idt_regra');
    }

    public function associacao(): BelongsTo
    {
        return $this->belongsTo(Associacao::class, 'idt_associacao', 'idt_associacao');
    }
}
