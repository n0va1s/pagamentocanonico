<x-layouts::auth :title="__('Register')" maxWidth="2xl">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Associação -->
            <x-select-associacao
                name="idt_associacao"
                :label="__('Associação')"
                placeholder="Selecione a associação..."
                required
            />

            <!-- CPF & Nome -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:input
                    name="num_cpf_membro"
                    label="CPF"
                    :value="old('num_cpf_membro')"
                    type="text"
                    mask="999.999.999-99"
                    required
                    placeholder="000.000.000-00"
                />
                <flux:input
                    name="name"
                    :label="__('Nome')"
                    :value="old('name')"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                    :placeholder="__('Nome completo')"
                />
            </div>

            <!-- Apelido & Endereço -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:input
                    name="nom_apelido"
                    label="Apelido"
                    :value="old('nom_apelido')"
                    type="text"
                    placeholder="Como é conhecido(a)"
                />
                <flux:input
                    name="end_logradouro"
                    label="Endereço"
                    :value="old('end_logradouro')"
                    type="text"
                    placeholder="Ex: SMLN MI Trecho 03 - Rua Jerivá"
                />
            </div>

            <!-- Número & Complemento -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:input
                    id="end_numero"
                    name="end_numero"
                    label="Número"
                    :value="old('end_numero')"
                    type="text"
                    placeholder="Ex: 123"
                />
                <flux:input
                    id="end_complemento"
                    name="end_complemento"
                    label="Complemento"
                    :value="old('end_complemento')"
                    type="text"
                    placeholder="Lote C - Casa 1"
                />
            </div>

            <!-- Celular & Email -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:input
                    name="tel_membro"
                    label="Celular"
                    :value="old('tel_membro')"
                    type="text"
                    mask="(99) 99999-9999"
                    placeholder="(61) 98888-7777"
                />
                <flux:input
                    name="email"
                    :label="__('Email address')"
                    :value="old('email')"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="seunome@gmail.com"
                />
            </div>

            <!-- Password & Confirm Password -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="new-password"
                    :placeholder="__('Password')"
                    passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                    viewable
                />
                <flux:input
                    name="password_confirmation"
                    :label="__('Confirm password')"
                    type="password"
                    required
                    autocomplete="new-password"
                    :placeholder="__('Confirm password')"
                    passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                    viewable
                />
            </div>

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>

    <script>
        (function() {
            function initAddressFields() {
                const endNumero = document.getElementById('end_numero');
                const endComplemento = document.getElementById('end_complemento');

                if (endNumero && endComplemento) {
                    if (endNumero.dataset.hasListener) return;
                    endNumero.dataset.hasListener = 'true';

                    endNumero.addEventListener('input', function() {
                        const val = this.value;
                        if (/[a-zA-Z]/.test(val)) {
                            const numbers = val.replace(/[^0-9]/g, '');
                            const letters = val.replace(/[^a-zA-Z]/g, '');
                            
                            this.value = numbers;
                            
                            const lotInfo = `Lote ${letters.toUpperCase()}`;
                            
                            if (endComplemento.value.trim() === '') {
                                endComplemento.value = lotInfo;
                            } else if (!endComplemento.value.includes(lotInfo)) {
                                endComplemento.value = `${endComplemento.value.trim()} ${lotInfo}`;
                            }
                        } else {
                            this.value = val.replace(/[^0-9]/g, '');
                        }
                    });
                }
            }

            document.addEventListener('DOMContentLoaded', initAddressFields);
            document.addEventListener('livewire:navigated', initAddressFields);
        })();
    </script>
</x-layouts::auth>
