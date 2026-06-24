<?php

namespace Database\Factories;

use App\Models\Associacao;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssociacaoFactory extends Factory
{
    protected $model = Associacao::class;

    public function definition(): array
    {
        return [
            'nom_associacao' => $this->faker->unique()->company() . ' Association',
        ];
    }
}
