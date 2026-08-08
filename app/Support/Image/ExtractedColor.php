<?php

declare(strict_types=1);

namespace App\Support\Image;

use App\Support\Color\Lab;
use App\Support\Color\Rgb;

final readonly class ExtractedColor
{
    public function __construct(
        public Rgb $rgb,
        public Lab $lab,
        public float $share,
        public int $pixels,
    ) {}

    public function hex(): string
    {
        return $this->rgb->toHex();
    }

    public function percent(): float
    {
        return round($this->share * 100, 2);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'hex' => $this->hex(),
            'rgb' => [$this->rgb->r, $this->rgb->g, $this->rgb->b],
            'lab' => $this->lab->toArray(),
            'share' => round($this->share, 6),
            'percent' => $this->percent(),
            'pixels' => $this->pixels,
        ];
    }
}
