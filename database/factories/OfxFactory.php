<?php

namespace Database\Factories;

use App\Models\Ofx;
use Illuminate\Database\Eloquent\Factories\Factory;

class OfxFactory extends Factory
{
    protected $model = Ofx::class;

    public function definition(): array
    {
        $inicio = fake()->dateTimeBetween('-6 months', '-1 month');
        $fim = fake()->dateTimeBetween($inicio, 'now');

        return [
            'des_arquivo' => 'extrato_'.fake()->numerify('######').'.ofx',
            'cod_banco' => fake()->randomElement(['001', '033', '104', '237', '341']),
            'num_conta' => fake()->numerify('#####-#'),
            'dat_inicio' => $inicio,
            'dat_fim' => $fim,
            'qtd_transacao' => 0,
            'val_total' => 0,
        ];
    }
}
