<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions;

use Illuminate\Database\Eloquent\Builder;

use App\Filament\Resources\Submissions\Pages\CreateSubmission;
use App\Filament\Resources\Submissions\Pages\EditSubmission;
use App\Filament\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Resources\Submissions\RelationManagers\AssetsRelationManager;
use App\Filament\Resources\Submissions\Schemas\SubmissionForm;
use App\Filament\Resources\Submissions\Tables\SubmissionsTable;
use App\Models\Submission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SubmissionResource extends Resource
{
    protected static ?string $model = Submission::class;

    protected static ?string $recordTitleAttribute = 'campaign';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Operación';

    protected static ?string $navigationLabel = 'Cargar piezas';

    protected static ?string $modelLabel = 'carga';

    protected static ?string $pluralModelLabel = 'Cargas';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return SubmissionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubmissionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AssetsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubmissions::route('/'),
            'create' => CreateSubmission::route('/create'),
            'edit' => EditSubmission::route('/{record}/edit'),
        ];
    }

    /**
     * Mismo criterio que SubmissionPolicy::view, aplicado a la consulta base:
     * alcance por marca y, con solo submission.view_own, unicamente las cargas
     * propias.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        $query = parent::getEloquentQuery()
            ->whereIn('brand_id', $user->accessibleBrandIds());

        if (! $user->hasPermissionTo('submission.view_any') && ! $user->hasPermissionTo('submission.view_brand')) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }
}
