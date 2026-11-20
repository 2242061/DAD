<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('DEMO_EMAIL');
        $password = env('DEMO_PASSWORD');

        if (! $email || ! $password) {
            throw new \RuntimeException('Configura DEMO_EMAIL e DEMO_PASSWORD antes de executar db:seed.');
        }

        User::updateOrCreate(
            ['email' => $email],
            ['name' => 'Utilizador de teste', 'password' => $password]
        );
    }
}
