<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transacao extends Model
{
    use HasFactory;


    protected $table = 'transacoes';

    protected $primaryKey = 'idt_transacao';

    public $timestamps = true;

    protected $fillable = [
        'idt_ofx',
        'num_transacao',
        'dat_transacao',
        'tip_transacao',
        'val_transacao',
        'des_transacao',
        'num_check',
        'nom_pessoa',
    ];

    protected $casts = [
        'dat_transacao' => 'date',
        'val_transacao' => 'decimal:2',
    ];

    public function ofx(): BelongsTo
    {
        return $this->belongsTo(Ofx::class, 'idt_ofx');
    }

    protected static function booted(): void
    {
        static::addGlobalScope('associacao', function (\Illuminate\Database\Eloquent\Builder $builder) {
            if (auth()->check() && !auth()->user()->isAdmin()) {
                $associacaoId = auth()->user()->getMembroAssociacaoId();
                $builder->whereIn('idt_ofx', function ($query) use ($associacaoId) {
                    $query->select('idt_ofx')
                          ->from('ofx')
                          ->where('idt_associacao', $associacaoId);
                });
            }
        });
    }
}
