<?php

namespace Database\Factories;

use App\Enums\Perfil;
use App\Models\Membro;
use Illuminate\Database\Eloquent\Factories\Factory;

class MembroFactory extends Factory
{
    protected $model = Membro::class;

    public function definition(): array
    {
        return [
            'idt_associacao' => \App\Models\Associacao::factory(),
            'nom_membro' => fake('pt_BR')->name(),
            'ind_aprovado' => true,
            'eml_membro' => fake()->unique()->safeEmail(),
            'tel_membro' => fake('pt_BR')->optional()->phoneNumber(),
            'dat_nascimento' => fake()->date(),
            'end_logradouro' => fake('pt_BR')->optional()->streetName(),
            'end_numero' => fake()->optional()->buildingNumber(),
            'end_complemento' => fake('pt_BR')->optional()->secondaryAddress(),
            'tip_associado' => fake()->randomElement(Perfil::cases())->value,
            'des_telegram_chat_id' => null,
            'ind_notificar_whatsapp' => fake()->boolean(70),
            'ind_notificar_email' => fake()->boolean(80),
            'ind_notificar_telegram' => fake()->boolean(20),
        ];
    }
}
