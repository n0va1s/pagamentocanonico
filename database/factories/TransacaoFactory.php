<?php

namespace Database\Factories;

use App\Models\Ofx;
use App\Models\Transacao;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransacaoFactory extends Factory
{
    protected $model = Transacao::class;

    public function definition(): array
    {
        return [
            'idt_ofx' => Ofx::factory(),
            'num_transacao' => strtoupper(fake()->unique()->bothify('??##########')),
            'dat_transacao' => fake()->dateTimeBetween('-3 months', 'now'),
            'tip_transacao' => fake()->randomElement(['CREDIT', 'DEBIT']),
            'val_transacao' => fake()->randomFloat(2, 10, 5000),
            'des_transacao' => fake('pt_BR')->name(),
            'num_check' => fake()->optional()->numerify('######'),
            'nom_pessoa' => fake('pt_BR')->optional()->name(),
        ];
    }

    public function credito(): static
    {
        return $this->state(fn () => [
            'tip_transacao' => 'CREDIT',
            'val_transacao' => fake()->randomFloat(2, 50, 5000),
        ]);
    }

    public function debito(): static
    {
        return $this->state(fn () => [
            'tip_transacao' => 'DEBIT',
            'val_transacao' => fake()->randomFloat(2, 10, 2000),
        ]);
    }
}
