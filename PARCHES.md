# Parches sobre archivos existentes

Tres cambios puntuales. Se entregan como bloques a insertar y no como
archivos completos: los que tengo en mi copia pueden no ser los ultimos
tuyos, y reemplazarlos a ciegas pisaria cambios que no veo.

---

## 1. RuleSetResource.php

**Import** — junto a los otros `use` de Pages:

```php
use App\Filament\Resources\RuleSets\Pages\ViewRuleSet;
use App\Filament\Resources\RuleSets\Schemas\RuleSetInfolist;
```

**Metodo nuevo** — pegalo debajo de `form()`:

```php
    public static function infolist(Schema $schema): Schema
    {
        return RuleSetInfolist::configure($schema);
    }
```

**getPages()** — agrega la linea `view`. El orden importa: Filament
resuelve las rutas de arriba hacia abajo y `/{record}` capturaria
`/create` si estuviera antes.

```php
    public static function getPages(): array
    {
        return [
            'index' => ListRuleSets::route('/'),
            'create' => CreateRuleSet::route('/create'),
            'view' => ViewRuleSet::route('/{record}'),
            'edit' => EditRuleSet::route('/{record}/edit'),
        ];
    }
```

---

## 2. RuleSetsTable.php

**Import**:

```php
use Filament\Actions\ViewAction;
```

**En `->recordActions([...])`** — como PRIMERA accion, antes de
`EditAction::make()`:

```php
                ViewAction::make()
                    ->label('Ver'),
```

No lleva `->visible()`: la policy ya decide con `view()`, y para un
borrador tambien tiene sentido consultarlo sin entrar a editar.

---

## 3. RulesRelationManager.php

**Import**:

```php
use Filament\Actions\ViewAction;
```

**En `->recordActions([...])` de `table()`** — como PRIMERA accion:

```php
                ViewAction::make()
                    ->label('Ver')
                    ->modalHeading(fn (Rule $record): string => "{$record->code} · {$record->title}"),
```

Sin `->schema()`: cuando no se le pasa uno, ViewAction reutiliza el
`form()` de este relation manager con todos los campos deshabilitados.
Eso mantiene una sola definicion de que campos tiene una regla. Si
manana agregas un campo al formulario, aparece en la vista sin tocar
nada.
