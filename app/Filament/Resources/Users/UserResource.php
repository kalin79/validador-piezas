<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;
class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;
    protected static string|UnitEnum|null $navigationGroup = 'Accesos';
    protected static ?string $navigationLabel = 'Usuarios';
    protected static ?string $modelLabel = 'usuario';
    protected static ?string $pluralModelLabel = 'Usuarios';
    protected static ?int $navigationSort = 2;

    // protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * Se ven los usuarios de los propios equipos, nada mas.
     *
     * Sin este filtro cualquiera entraba a /admin/users, se asignaba
     * super_admin y abria todos los clientes.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user === null || $user->hasGlobalAccess()) {
            return $query;
        }

        $equipos = $user->teams()->pluck('teams.id');

        if ($equipos->isEmpty()) {
            return $query->whereKey($user->id);
        }

        return $query->where(function (Builder $q) use ($equipos, $user): void {
            $q->whereHas('teams', fn (Builder $t) => $t->whereIn('teams.id', $equipos))
                ->orWhere('id', $user->id);
        });
    }
}
