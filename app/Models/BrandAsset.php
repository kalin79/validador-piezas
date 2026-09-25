<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BrandAssetType;
use App\Enums\LogoPosition;
use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Activo grafico de referencia de una marca: logotipos, sellos, marcas de agua.
 *
 * Guarda el archivo y las reglas de uso que despues se verifican sobre la
 * pieza. La deteccion de donde esta el logo la hara el modelo de vision en la
 * Fase 3; lo que vive aqui es contra que medirlo.
 */
class BrandAsset extends Model
{
    use BelongsToBrand;
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => BrandAssetType::class,
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'min_width_percent' => 'float',
            'clear_space_ratio' => 'float',
            'allowed_positions' => 'array',
            'applies_to_channels' => 'array',
        ];
    }

    public function url(): ?string
    {
        // Ruta autenticada: aplica BrandAssetPolicy::view antes de servir.
        return $this->exists ? route('archivos.activo-marca', $this) : null;
    }

    public function appliesToChannel(?string $channel): bool
    {
        if ($channel === null || blank($this->applies_to_channels)) {
            return true;
        }

        return in_array($channel, $this->applies_to_channels, true);
    }

    public function allowsPosition(LogoPosition $position): bool
    {
        if (blank($this->allowed_positions)) {
            return true;
        }

        return in_array($position->value, $this->allowed_positions, true);
    }

    /**
     * Espacio libre exigido alrededor del logo, en pixeles de la pieza.
     *
     * Se expresa como multiplo del alto del propio logo tal como aparece en la
     * pieza, que es la convencion habitual en los manuales de marca: "dejar un
     * margen equivalente a la altura de la X del logotipo".
     */
    public function clearSpacePixels(float $logoHeightInPiece): ?float
    {
        if ($this->clear_space_ratio === null) {
            return null;
        }

        return $logoHeightInPiece * $this->clear_space_ratio;
    }

    /** @return array<string, mixed> */
    public function toValidationContext(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'name' => $this->name,
            'is_required' => $this->is_required,
            'min_width_percent' => $this->min_width_percent,
            'clear_space_ratio' => $this->clear_space_ratio,
            'allowed_positions' => $this->allowed_positions,
            'reference_dimensions' => [
                'width' => $this->width,
                'height' => $this->height,
            ],
        ];
    }
}
