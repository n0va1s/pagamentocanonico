<?php

namespace App\Models;

use App\Enums\TipoAssociado;
use App\Jobs\NotificarBoasVindas;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Membro extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'membros';

    protected $primaryKey = 'idt_membro';

    public $timestamps = true;

    protected $fillable = [
        'nom_membro',
        'nom_ofx',
        'eml_membro',
        'tel_membro',
        'end_logradouro',
        'end_mumero',
        'end_complemento',
        'tip_associado',
        'des_telegram_chat_id',
        'ind_notificar_whatsapp',
        'ind_notificar_email',
        'ind_notificar_telegram',
    ];

    /**
     * Retorna o nome usado para matching com o campo nom_pessoa do OFX.
     * Prioriza nom_ofx quando preenchido, com fallback para nom_membro.
     */
    public function nomeParaMatchingOfx(): string
    {
        return $this->nom_ofx ?? $this->nom_membro;
    }

    protected $casts = [
        'tip_associado' => TipoAssociado::class,
        'ind_notificar_whatsapp' => 'boolean',
        'ind_notificar_email' => 'boolean',
        'ind_notificar_telegram' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::created(function (self $membro) {
            NotificarBoasVindas::dispatch($membro)->afterCommit();
        });
    }
}
