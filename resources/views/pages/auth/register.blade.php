<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <!-- Apelido -->
            <flux:input
                name="nom_apelido"
                label="Apelido"
                :value="old('nom_apelido')"
                type="text"
                placeholder="Seu apelido"
            />

            <!-- Celular -->
            <flux:input
                name="tel_membro"
                label="Celular"
                :value="old('tel_membro')"
                type="text"
                mask="(99) 99999-9999"
                placeholder="(61) 98154-6988"
            />

            <!-- Logradouro -->
            <flux:input
                name="end_logradouro"
                label="Endereço / Logradouro"
                :value="old('end_logradouro')"
                type="text"
                placeholder="Rua, Avenida, etc."
            />

            <div class="flex gap-4">
                <!-- Número -->
                <div class="flex-1">
                    <flux:input
                        name="end_numero"
                        label="Número"
                        :value="old('end_numero')"
                        type="text"
                        placeholder="Nº"
                    />
                </div>

                <!-- Complemento -->
                <div class="flex-1">
                    <flux:input
                        name="end_complemento"
                        label="Complemento"
                        :value="old('end_complemento')"
                        type="text"
                        placeholder="Apto, Bloco, etc."
                    />
                </div>
            </div>

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Associação -->
            <flux:select
                name="idt_associacao"
                :label="__('Associação')"
                placeholder="Selecione a associação..."
                required
            >
                @foreach(\App\Models\Associacao::orderBy('nom_associacao')->get() as $assoc)
                    <flux:select.option value="{{ $assoc->idt_associacao }}">
                        {{ $assoc->nom_associacao }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <!-- Password -->
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

            <!-- Confirm Password -->
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
</x-layouts::auth>
