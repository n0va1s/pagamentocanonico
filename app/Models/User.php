<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

use App\Enums\Perfil;

#[Fillable(['name', 'email', 'password', 'role', 'idt_membro'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Perfil::class,
        ];
    }

    /**
     * Check if user is an administrator
     */
    public function isAdmin(): bool
    {
        return $this->role === Perfil::ADMIN;
    }

    /**
     * Check if user is a director
     */
    public function isDiretor(): bool
    {
        return $this->role === Perfil::DIRETOR;
    }

    /**
     * Check if user is a member
     */
    public function isMembro(): bool
    {
        return $this->role === Perfil::MEMBRO;
    }

    /**
     * Check if user has one of the specified roles
     *
     * @param Perfil|string|array $roles
     */
    public function hasRole(Perfil|string|array $roles): bool
    {
        if (is_array($roles)) {
            foreach ($roles as $role) {
                if ($this->hasRole($role)) {
                    return true;
                }
            }
            return false;
        }

        if ($this->role === null) {
            return false;
        }

        $roleValue = $roles instanceof Perfil ? $roles->value : $roles;
        return $this->role->value === $roleValue;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function membro()
    {
        return $this->belongsTo(Membro::class, 'idt_membro', 'idt_membro');
    }

    protected ?int $membroAssociacaoIdCached = null;
    protected static bool $resolvingScope = false;

    public function getMembroAssociacaoId(): ?int
    {
        if (!$this->idt_membro) {
            return null;
        }
        if ($this->membroAssociacaoIdCached === null) {
            $this->membroAssociacaoIdCached = Membro::withoutGlobalScopes()->where('idt_membro', $this->idt_membro)->value('idt_associacao') ?? -1;
        }
        return $this->membroAssociacaoIdCached === -1 ? null : $this->membroAssociacaoIdCached;
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (!$user->idt_membro && $user->role !== Perfil::ADMIN && $user->role !== 'admin') {
                $existingMembro = Membro::withoutGlobalScopes()->where('eml_membro', $user->email)->first();
                if ($existingMembro) {
                    $user->idt_membro = $existingMembro->idt_membro;
                    $user->role = $existingMembro->tip_associado;
                } else {
                    $membro = Membro::create([
                        'nom_membro' => $user->name,
                        'eml_membro' => $user->email,
                        'tip_associado' => $user->role ?? Perfil::MEMBRO,
                        'idt_associacao' => request('idt_associacao') ?? Associacao::first()?->idt_associacao ?? Associacao::factory()->create()->idt_associacao,
                        'end_logradouro' => request('end_logradouro'),
                        'end_numero' => request('end_numero'),
                        'end_complemento' => request('end_complemento'),
                        'nom_apelido' => request('nom_apelido'),
                        'tel_membro' => request('tel_membro'),
                        'ind_aprovado' => false,
                        'usu_autorizador' => null,
                    ]);
                    $user->idt_membro = $membro->idt_membro;
                }
            }
        });

        static::updated(function (User $user) {
            if ($user->wasChanged('role') && $user->idt_membro) {
                $membro = Membro::withoutGlobalScopes()->find($user->idt_membro);
                if ($membro && $membro->tip_associado !== $user->role) {
                    $membro->update(['tip_associado' => $user->role]);
                }
            }
        });

        static::addGlobalScope('associacao', function (\Illuminate\Database\Eloquent\Builder $builder) {
            if (static::$resolvingScope) {
                return;
            }

            try {
                static::$resolvingScope = true;

                if (auth()->check() && !auth()->user()->isAdmin()) {
                    $associacaoId = auth()->user()->getMembroAssociacaoId();
                    $builder->whereIn('idt_membro', function ($query) use ($associacaoId) {
                        $query->select('idt_membro')
                              ->from('membros')
                              ->where('idt_associacao', $associacaoId);
                    });
                }
            } finally {
                static::$resolvingScope = false;
            }
        });
    }
}
