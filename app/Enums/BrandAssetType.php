<?php

declare(strict_types=1);

namespace App\Enums;

enum BrandAssetType: string
{
    case LogoPrimary = 'logo_primary';
    case LogoNegative = 'logo_negative';
    case LogoMono = 'logo_mono';
    case LogoIsotype = 'logo_isotype';
    case Seal = 'seal';
    case Watermark = 'watermark';

    public function label(): string
    {
        return match ($this) {
            self::LogoPrimary => 'Logotipo principal',
            self::LogoNegative => 'Logotipo en negativo',
            self::LogoMono => 'Logotipo monocromo',
            self::LogoIsotype => 'Isotipo (solo simbolo)',
            self::Seal => 'Sello corporativo',
            self::Watermark => 'Marca de agua',
        };
    }

    public function isLogo(): bool
    {
        return in_array($this, [
            self::LogoPrimary,
            self::LogoNegative,
            self::LogoMono,
            self::LogoIsotype,
        ], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static fn (array $c, self $case): array => $c + [$case->value => $case->label()],
            []
        );
    }
}
