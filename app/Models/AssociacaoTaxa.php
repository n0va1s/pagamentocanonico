<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssociacaoTaxa extends Model
{
    use HasFactory;

    protected $table = 'associacao_taxas';

    protected $primaryKey = 'idt_associacao_taxa';

    protected $fillable = [
        'idt_associacao',
        'val_taxa',
        'val_anual',
        'dat_inicio',
        'dat_fim',
    ];

    protected $casts = [
        'val_taxa' => 'decimal:2',
        'val_anual' => 'decimal:2',
        'dat_inicio' => 'date',
        'dat_fim' => 'date',
    ];

    public function associacao(): BelongsTo
    {
        return $this->belongsTo(Associacao::class, 'idt_associacao', 'idt_associacao');
    }
}
