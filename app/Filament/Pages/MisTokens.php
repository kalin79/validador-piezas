<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Tokens de API para el plugin de Figma y otros clientes externos.
 *
 * Hasta ahora los tokens se creaban con un comando de consola, lo que obliga a
 * que alguien con acceso al servidor lo genere y se lo haga llegar al
 * disenador. Ese envio termina siendo un mensaje de WhatsApp con la credencial
 * en texto plano, y nadie lo revoca despues.
 *
 * Cada quien genera el suyo desde aqui, lo ve una sola vez y puede revocarlo
 * cuando quiera. El token hereda exactamente el alcance del usuario: las mismas
 * marcas que ve en el panel, ni una mas.
 */
class MisTokens extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Operación';

    protected static ?string $navigationLabel = 'Mis tokens de API';

    protected static ?string $title = 'Tokens de API';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.mis-tokens';

    /**
     * El token recien creado, para mostrarlo una unica vez.
     *
     * Vive solo en la propiedad de Livewire: no se guarda en sesion ni en base.
     * Sanctum almacena unicamente el hash, asi que si se pierde no hay forma de
     * recuperarlo y hay que generar otro.
     */
    public ?string $tokenNuevo = null;

    /**
     * Cualquiera que entre al panel puede tener su token: el alcance lo sigue
     * poniendo su usuario. Lo que no puede es crear tokens para otro.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('validation.trigger') === true;
    }

    /** @return Collection<int, object> */
    public function getTokensProperty(): Collection
    {
        return auth()->user()
            ->tokens()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($t): object => (object) [
                'id' => $t->id,
                'nombre' => $t->name,
                'creado' => $t->created_at,
                'ultimoUso' => $t->last_used_at,
                'nuncaUsado' => $t->last_used_at === null,
            ]);
    }

    public function getMarcasProperty(): int
    {
        return auth()->user()->accessibleBrandCount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generar')
                ->label('Generar token')
                ->icon(Heroicon::OutlinedPlus)
                ->schema([
                    TextInput::make('nombre')
                        ->label('Para que es')
                        ->placeholder('Plugin de Figma de Juan')
                        ->required()
                        ->maxLength(60)
                        ->helperText('Sirve para saber cual revocar despues. Nombra el equipo o la persona, no "token1".'),
                ])
                ->modalHeading('Generar un token de API')
                ->modalDescription('Se mostrara una sola vez. El token da acceso a las mismas marcas que ves tu.')
                ->modalSubmitActionLabel('Generar')
                ->action(function (array $data): void {
                    $token = auth()->user()->createToken($data['nombre']);
                    app(\App\Services\AuditLogger::class)->log('token.created', auth()->user(), newValues: ['token_name' => $data['nombre'], 'origen' => 'panel']);

                    $this->tokenNuevo = $token->plainTextToken;

                    Notification::make()
                        ->title('Token generado')
                        ->body('Copialo ahora: no se vuelve a mostrar.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function revocar(int $id): void
    {
        $token = auth()->user()->tokens()->whereKey($id)->first();

        if ($token === null) {
            // Se comprueba la pertenencia con la relacion del usuario, no con
            // el id suelto: sin eso, cambiar el numero en la peticion revocaria
            // el token de cualquier otro.
            Notification::make()
                ->title('Ese token no es tuyo')
                ->danger()
                ->send();

            return;
        }

        $nombre = $token->name;
        $token->delete();

        app(\App\Services\AuditLogger::class)->log('token.revoked', auth()->user(), newValues: ['token_name' => $nombre, 'origen' => 'panel']);

        Notification::make()
            ->title("Token '{$nombre}' revocado")
            ->body('Quien lo tuviera deja de tener acceso de inmediato.')
            ->success()
            ->send();
    }

    public function ocultarToken(): void
    {
        $this->tokenNuevo = null;
    }
}
