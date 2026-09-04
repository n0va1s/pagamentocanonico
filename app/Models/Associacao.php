<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Associacao extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'associacoes';

    protected $primaryKey = 'idt_associacao';

    protected $fillable = [
        'nom_associacao',
        'tel_contato',
        'des_chave_pix',
        'dat_inicio_cobranca',
    ];

    protected $casts = [
        'dat_inicio_cobranca' => 'date',
    ];

    public function membros(): HasMany
    {
        return $this->hasMany(Membro::class, 'idt_associacao', 'idt_associacao');
    }

    public function contatos(): HasMany
    {
        return $this->hasMany(Contato::class, 'idt_associacao', 'idt_associacao');
    }

    public function ofx(): HasMany
    {
        return $this->hasMany(Ofx::class, 'idt_associacao', 'idt_associacao');
    }

    public function taxas(): HasMany
    {
        return $this->hasMany(AssociacaoTaxa::class, 'idt_associacao', 'idt_associacao');
    }

    /**
     * Retorna o registro de taxa vigente na data informada (ou data atual se nulo).
     */
    public function getTaxaVigenteEm(?string $data = null): ?AssociacaoTaxa
    {
        $dataRef = $data ?? now()->format('Y-m-d');

        return $this->taxas()
            ->where('dat_inicio', '<=', $dataRef)
            ->where(function ($q) use ($dataRef) {
                $q->whereNull('dat_fim')
                  ->orWhere('dat_fim', '>=', $dataRef);
            })
            ->orderByDesc('dat_inicio')
            ->first();
    }

    public function getChavePixAttribute(): ?string
    {
        return $this->des_chave_pix;
    }
}
