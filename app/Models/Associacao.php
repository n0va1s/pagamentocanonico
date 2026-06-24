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
        'val_taxa',
        'val_anual',
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
}
