<?php

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';

    // Membro properties
    public string $num_cpf_membro = '';
    public string $nom_apelido = '';
    public string $tel_membro = '';
    public string $end_logradouro = '';
    public string $end_numero = '';
    public string $end_complemento = '';
    public ?int $idt_associacao = null;
    public bool $hasMembro = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = Auth::user();
        $this->name = $user->name;
        $this->email = $user->email;

        $membro = $user->membro;
        if ($membro) {
            $this->hasMembro = true;
            $this->num_cpf_membro = $membro->num_cpf_membro ?? '';
            $this->nom_apelido = $membro->nom_apelido ?? '';
            $this->tel_membro = $membro->tel_membro ?? '';
            $this->end_logradouro = $membro->end_logradouro ?? '';
            $this->end_numero = $membro->end_numero ?? '';
            $this->end_complemento = $membro->end_complemento ?? '';
            $this->idt_associacao = $membro->idt_associacao;
        }
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $rules = $this->profileRules($user->id);

        if ($this->hasMembro) {
            $rules['nom_apelido'] = ['nullable', 'string', 'max:100'];
            $rules['tel_membro'] = ['nullable', 'string', 'max:20'];
            $rules['end_logradouro'] = ['nullable', 'string', 'max:150'];
            $rules['end_numero'] = ['nullable', 'string', 'max:20'];
            $rules['end_complemento'] = ['nullable', 'string', 'max:150'];
        }

        $validated = $this->validate($rules);

        $user->fill([
            'name' => $this->name,
            'email' => $this->email,
        ]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($this->hasMembro) {
            $membro = $user->membro;

            // Extra fallback backend numeric validation and split
            $rawNumero = $this->end_numero;
            $rawComplemento = $this->end_complemento;
            $numero = $rawNumero;
            $complemento = $rawComplemento;

            if ($rawNumero !== null && $rawNumero !== '') {
                $digits = preg_replace('/[^0-9]/', '', $rawNumero);
                $numero = $digits !== '' ? $digits : null;

                $letters = trim(preg_replace('/[^a-zA-Z]/', '', $rawNumero));
                if ($letters !== '') {
                    $loteSuffix = 'Lote ' . strtoupper($letters);
                    if ($complemento !== null && $complemento !== '') {
                        if (strpos($complemento, $loteSuffix) === false) {
                            $complemento .= ' ' . $loteSuffix;
                        }
                    } else {
                        $complemento = $loteSuffix;
                    }
                }
            }

            $this->end_numero = $numero ?? '';
            $this->end_complemento = $complemento ?? '';

            $membro->update([
                'nom_membro' => $user->name,
                'eml_membro' => $user->email,
                'nom_apelido' => $this->nom_apelido ?: null,
                'tel_membro' => $this->tel_membro ?: null,
                'end_logradouro' => $this->end_logradouro ?: null,
                'end_numero' => $numero,
                'end_complemento' => $complemento,
            ]);
        }

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Flux::toast(text: __('A new verification link has been sent to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            @if ($hasMembro)
                {{-- Associação --}}
                <flux:select
                    id="idt_associacao"
                    wire:model="idt_associacao"
                    label="Associação"
                    disabled
                >
                    @foreach(\App\Models\Associacao::orderBy('nom_associacao')->get() as $assoc)
                        <flux:select.option value="{{ $assoc->idt_associacao }}">
                            {{ $assoc->nom_associacao }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                {{-- CPF & Nome --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <flux:input wire:model="num_cpf_membro" label="CPF" type="text" disabled />
                    <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />
                </div>

                {{-- Apelido & Endereço --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <flux:input wire:model="nom_apelido" label="Apelido" type="text" placeholder="Seu apelido" />
                    <flux:input wire:model="end_logradouro" label="Endereço / Logradouro" type="text" placeholder="Rua, Avenida, etc." />
                </div>

                {{-- Número & Complemento --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <flux:input
                        id="end_numero"
                        wire:model="end_numero"
                        label="Número"
                        type="text"
                        placeholder="Nº"
                        x-on:input="
                            let val = $el.value;
                            if (/[a-zA-Z]/.test(val)) {
                                let numbers = val.replace(/[^0-9]/g, '');
                                let letters = val.replace(/[^a-zA-Z]/g, '');
                                $el.value = numbers;
                                $wire.set('end_numero', numbers);
                                
                                let lotInfo = 'Lote ' + letters.toUpperCase();
                                let compVal = $wire.get('end_complemento') || '';
                                if (compVal.trim() === '') {
                                    $wire.set('end_complemento', lotInfo);
                                } else if (!compVal.includes(lotInfo)) {
                                    $wire.set('end_complemento', compVal.trim() + ' ' + lotInfo);
                                }
                            } else {
                                let numbers = val.replace(/[^0-9]/g, '');
                                $el.value = numbers;
                                $wire.set('end_numero', numbers);
                            }
                        "
                    />
                    <flux:input
                        id="end_complemento"
                        wire:model="end_complemento"
                        label="Complemento"
                        type="text"
                        placeholder="Apto, Bloco, etc."
                    />
                </div>

                {{-- Celular & Email --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <flux:input wire:model="tel_membro" label="Celular" type="text" mask="(99) 99999-9999" placeholder="(61) 98154-6988" />
                    <div>
                        <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                        @if ($this->hasUnverifiedEmail)
                            <div>
                                <flux:text class="mt-4">
                                    {{ __('Your email address is unverified.') }}

                                    <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                        {{ __('Click here to re-send the verification email.') }}
                                    </flux:link>
                                </flux:text>
                            </div>
                        @endif
                    </div>
                </div>
            @else
                {{-- Fallback Layout --}}
                <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />
                <div>
                    <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                    @if ($this->hasUnverifiedEmail)
                        <div>
                            <flux:text class="mt-4">
                                {{ __('Your email address is unverified.') }}

                                <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                    {{ __('Click here to re-send the verification email.') }}
                                </flux:link>
                            </flux:text>
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="update-profile-button">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>

        @if ($this->showDeleteUser)
            <livewire:pages::settings.delete-user-form />
        @endif
    </x-pages::settings.layout>
</section>
