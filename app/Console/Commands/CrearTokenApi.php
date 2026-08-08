<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Genera un token de API para un usuario existente.
 *
 * El token hereda las marcas del usuario: no hay permisos separados que
 * mantener sincronizados. Si a alguien se le quita el acceso a una marca en
 * el panel, su token deja de poder validar contra ella en el acto.
 */
class CrearTokenApi extends Command
{
    protected $signature = 'validador:token
                            {email : Correo del usuario dueno del token}
                            {--nombre=figma : Nombre para identificar el token}';

    protected $description = 'Crea un token de API para el plugin de Figma u otro cliente';

    public function handle(): int
    {
        $usuario = User::where('email', $this->argument('email'))->first();

        if ($usuario === null) {
            $this->error("No existe un usuario con el correo {$this->argument('email')}.");

            return self::FAILURE;
        }

        if (! method_exists($usuario, 'createToken')) {
            $this->error('El modelo User no usa el trait HasApiTokens de Sanctum.');
            $this->line('Corre primero:  php artisan install:api');
            $this->line('y agrega  use Laravel\\Sanctum\\HasApiTokens;  al modelo User.');

            return self::FAILURE;
        }

        $nombre = (string) $this->option('nombre');
        $token = $usuario->createToken($nombre);

        $marcas = $usuario->accessibleBrandIds()->count();

        $this->newLine();
        $this->info("Token '{$nombre}' creado para {$usuario->name}.");
        $this->line("Alcance: {$marcas} marca(s), las mismas que ve en el panel.");
        $this->newLine();
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->warn('Copialo ahora: no se vuelve a mostrar. Guardalo donde guardas secretos,');
        $this->warn('nunca en el repositorio del plugin.');
        $this->newLine();

        return self::SUCCESS;
    }
}
