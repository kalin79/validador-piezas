<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolesAndPermissionsSeeder::class]);

        // Los datos de demostracion (clientes, marcas y usuarios de ejemplo)
        // nunca se cargan en produccion, aunque alguien corra db:seed.
        if (! app()->isProduction()) {
            $this->call([DemoDataSeeder::class]);
        }
    }
}
