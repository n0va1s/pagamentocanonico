<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class Contato extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'idt_associacao',
        'nome',
        'email',
        'mensagem',
    ];

    public function associacao()
    {
        return $this->belongsTo(Associacao::class, 'idt_associacao', 'idt_associacao');
    }

    protected static function booted(): void
    {
        static::addGlobalScope('associacao', function (\Illuminate\Database\Eloquent\Builder $builder) {
            if (auth()->check() && !auth()->user()->isAdmin()) {
                $builder->where('idt_associacao', auth()->user()->getMembroAssociacaoId());
            }
        });
    }
}
