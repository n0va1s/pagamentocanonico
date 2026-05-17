<?php

namespace Database\Factories;

use App\Models\Ofx;
use App\Models\Resumo;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResumoFactory extends Factory
{
    protected $model = Resumo::class;

    public function definition(): array
    {
        $nomesMeses = [
            1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr',
            5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
            9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez',
        ];

        $mes = fake()->numberBetween(1, 12);
        $total = fake()->randomFloat(2, 0, 3000);

        return [
            'idt_ofx' => Ofx::factory(),
            'nom_pessoa' => fake('pt_BR')->name(),
            'num_ano' => fake()->numberBetween(2024, 2026),
            'num_mes' => $mes,
            'nom_mes' => $nomesMeses[$mes],
            'val_total' => $total,
            'num_transacao' => fake()->numberBetween(1, 10),
            'ind_pago' => $total > 0,
        ];
    }
}
