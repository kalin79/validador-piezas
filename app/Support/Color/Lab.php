<?php

declare(strict_types=1);

namespace App\Support\Color;

/**
 * Color en el espacio CIELAB (D65, observador 2 grados).
 */
final readonly class Lab
{
    public function __construct(
        public float $l,
        public float $a,
        public float $b,
    ) {}

    /** @return array{l: float, a: float, b: float} */
    public function toArray(): array
    {
        return [
            'l' => round($this->l, 4),
            'a' => round($this->a, 4),
            'b' => round($this->b, 4),
        ];
    }

    /** @param array{l: float, a: float, b: float} $data */
    public static function fromArray(array $data): self
    {
        return new self((float) $data['l'], (float) $data['a'], (float) $data['b']);
    }
}
