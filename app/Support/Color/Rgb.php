<?php

declare(strict_types=1);

namespace App\Support\Color;

use InvalidArgumentException;

final readonly class Rgb
{
    public function __construct(
        public int $r,
        public int $g,
        public int $b,
    ) {
        foreach ([$r, $g, $b] as $canal) {
            if ($canal < 0 || $canal > 255) {
                throw new InvalidArgumentException("Canal fuera de rango: {$canal}");
            }
        }
    }

    public static function fromHex(string $hex): self
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            throw new InvalidArgumentException("Hexadecimal invalido: {$hex}");
        }

        return new self(
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        );
    }

    public static function fromInt(int $color): self
    {
        return new self(
            ($color >> 16) & 0xFF,
            ($color >> 8) & 0xFF,
            $color & 0xFF,
        );
    }

    public function toHex(): string
    {
        return sprintf('#%02X%02X%02X', $this->r, $this->g, $this->b);
    }
}
