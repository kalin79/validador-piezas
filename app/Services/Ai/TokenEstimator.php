<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Estima el costo de una validacion antes de ejecutarla.
 *
 * Una imagen se divide en parches de 28x28 pixeles, y cada parche cuesta un
 * token. La formula es ceil(ancho/28) * ceil(alto/28), acotada por el limite
 * de lado del modelo: por encima de ese limite la imagen se redimensiona sola,
 * asi que enviar una pieza de 4000 px no compra mas precision, solo latencia.
 */
final class TokenEstimator
{
    private const PATCH = 28;

    private const STANDARD_MAX_SIDE = 1568;

    /**
     * Tope de tokens visuales del tier estandar. Por encima de este valor el
     * modelo reduce la imagen por su cuenta, sin importar sus dimensiones.
     */
    private const STANDARD_MAX_TOKENS = 1568;

    /**
     * Tokens visuales de una imagen.
     *
     * Actuan dos limites a la vez: el lado maximo y el total de tokens. Por
     * eso una pieza de 1920x1080 no cuesta lo que sugiere su tamano: el
     * modelo la reduce hasta caber en el presupuesto de tokens.
     *
     * Es una estimacion para anticipar el gasto. El conteo que se factura es
     * el que devuelve la API en cada respuesta, y ese es el que se persiste.
     */
    public static function imageTokens(
        int $width,
        int $height,
        int $maxSide = self::STANDARD_MAX_SIDE,
        int $maxTokens = self::STANDARD_MAX_TOKENS,
    ): int {
        [$w, $h] = self::fitWithin($width, $height, $maxSide);

        $tokens = (int) (ceil($w / self::PATCH) * ceil($h / self::PATCH));

        return min($tokens, $maxTokens);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function fitWithin(int $width, int $height, int $maxSide): array
    {
        $lado = max($width, $height);

        if ($lado <= $maxSide) {
            return [$width, $height];
        }

        $factor = $maxSide / $lado;

        return [
            max(1, (int) round($width * $factor)),
            max(1, (int) round($height * $factor)),
        ];
    }

    /**
     * Aproximacion de tokens de texto. Sirve para estimar antes de llamar,
     * no para facturar: el conteo real lo devuelve la API en cada respuesta.
     */
    public static function textTokens(string $texto): int
    {
        // ~3.5 caracteres por token en espanol, algo menos eficiente que ingles.
        return (int) ceil(mb_strlen($texto) / 3.5);
    }

    public static function costUsd(string $model, int $inputTokens, int $outputTokens): float
    {
        $precios = config("ai.pricing.{$model}");

        if ($precios === null) {
            return 0.0;
        }

        return ($inputTokens / 1_000_000) * (float) $precios['input']
            + ($outputTokens / 1_000_000) * (float) $precios['output'];
    }

    /**
     * @return array{input_tokens: int, output_tokens: int, cost_usd: float, image_tokens: int}
     */
    public static function estimate(
        string $model,
        int $imageWidth,
        int $imageHeight,
        string $systemPrompt,
        string $userPrompt,
        int $expectedOutputTokens = 1200,
    ): array {
        $imagen = self::imageTokens($imageWidth, $imageHeight);
        $entrada = $imagen + self::textTokens($systemPrompt) + self::textTokens($userPrompt);

        return [
            'image_tokens' => $imagen,
            'input_tokens' => $entrada,
            'output_tokens' => $expectedOutputTokens,
            'cost_usd' => round(self::costUsd($model, $entrada, $expectedOutputTokens), 6),
        ];
    }
}
