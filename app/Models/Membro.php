<?php

namespace App\Models;

use App\Enums\Perfil;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Membro extends Model
{
    use HasFactory;


    protected $table = 'membros';

    protected $primaryKey = 'idt_membro';

    public $timestamps = true;

    protected $fillable = [
        'idt_associacao',
        'nom_membro',
        'nom_ofx',
        'nom_apelido',
        'eml_membro',
        'tel_membro',
        'dat_nascimento',
        'end_logradouro',
        'end_numero',
        'end_complemento',
        'tip_associado',
        'usu_autorizador',
        'ind_aprovado',
        'des_telegram_chat_id',
    ];

    /**
     * Retorna o nome usado para matching com o campo nom_pessoa do OFX.
     * Prioriza nom_ofx quando preenchido, com fallback para nom_membro.
     */
    public function nomeParaMatchingOfx(): string
    {
        return $this->nom_ofx ?? $this->nom_membro;
    }

    public function associacao()
    {
        return $this->belongsTo(Associacao::class, 'idt_associacao', 'idt_associacao');
    }

    public function user()
    {
        return $this->hasOne(User::class, 'idt_membro', 'idt_membro');
    }

    protected $casts = [
        'tip_associado' => Perfil::class,
        'ind_aprovado' => 'boolean',
        'dat_nascimento' => 'date',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('associacao', function (\Illuminate\Database\Eloquent\Builder $builder) {
            if (auth()->check() && !auth()->user()->isAdmin()) {
                $builder->where('idt_associacao', auth()->user()->getMembroAssociacaoId());
            }
        });

        static::updated(function (Membro $membro) {
            if ($membro->wasChanged('ind_aprovado') && $membro->ind_aprovado) {
                try {
                    \Illuminate\Support\Facades\Mail::to($membro->eml_membro)
                        ->send(new \App\Mail\BoasVindasMail($membro));
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error("Failed to send welcome email to member {$membro->idt_membro}: " . $e->getMessage());
                }
            }

            if ($membro->wasChanged('tip_associado')) {
                $user = User::withoutGlobalScopes()->where('idt_membro', $membro->idt_membro)->first();
                if ($user && $user->role !== $membro->tip_associado) {
                    $user->update(['role' => $membro->tip_associado]);
                }
            }
        });
    }
}
