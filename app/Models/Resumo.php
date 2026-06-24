<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resumo extends Model
{
    use HasFactory;


    protected $table = 'resumos';

    protected $primaryKey = 'idt_resumo';

    public $timestamps = true;

    protected $fillable = [
        'idt_ofx',
        'nom_pessoa',
        'num_ano',
        'num_mes',
        'nom_mes',
        'val_total',
        'num_transacao',
        'ind_pago',
    ];

    protected $casts = [
        'val_total' => 'decimal:2',
        'ind_pago' => 'boolean',
        'num_ano' => 'integer',
        'num_mes' => 'integer',
        'num_transacao' => 'integer',
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
