<?php

namespace Database\Seeders;

use App\Models\Membro;
use Illuminate\Database\Seeder;

class MembroSeeder extends Seeder
{
    public function run(): void
    {
        // 20 membros aleatórios
        Membro::factory()->count(20)->create();
    }
}
