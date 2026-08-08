<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\Submission;
use App\Models\ValidationRun;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Validacion de una pieza suelta: marca e imagen, sin contexto de campana.
 *
 * Es el camino que usan la API del plugin de Figma y la pantalla de validacion
 * rapida. Produce exactamente los mismos registros que el flujo completo
 * -carga, pieza, ejecucion, veredicto- porque la trazabilidad no puede
 * depender de por donde entro la pieza. Una validacion hecha desde Figma tiene
 * que poder defenderse igual que una hecha desde el panel.
 *
 * La unica diferencia real es que sin canal no se evalua formato: dimensiones,
 * relacion de aspecto y peso quedan fuera. Todo lo demas -paleta, contraste,
 * duplicados, copy, tono, cumplimiento y logo- se evalua igual.
 */
final class QuickValidation
{
    public function __construct(
        private AssetIngestor $ingestor = new AssetIngestor(),
        private ValidationRunner $runner = new ValidationRunner(),
    ) {}

    /**
     * @param  string|null  $channel        opcional: si el cliente lo conoce, se valida tambien el formato
     * @param  string|null  $externalRef    identificador del origen (por ejemplo el archivo de Figma)
     * @param  string|null  $model          modelo de IA especifico, null usa el configurado
     */
    public function validate(
        Brand $brand,
        UploadedFile $file,
        ?int $userId = null,
        string $source = 'api',
        ?string $channel = null,
        ?string $externalRef = null,
        ?string $model = null,
    ): ValidationRun {
        $submission = $this->submissionFor($brand, $userId, $source, $channel, $externalRef);

        $asset = $this->ingestor->ingest($submission, $file, config('filesystems.default', 'local'));

        // Sincrono a proposito: quien llama espera el veredicto en la misma
        // peticion. Con QUEUE_CONNECTION=sync despachar el Job daria lo mismo,
        // pero llamar al runner directo deja claro que aqui no hay asincronia
        // y evita que activar colas mas adelante rompa esta ruta en silencio.
        return $this->runner->run($asset, $userId, model: $model);
    }

    /**
     * Reutiliza la carga cuando el origen la identifica.
     *
     * Un disenador que itera sobre la misma pieza en Figma puede disparar
     * decenas de validaciones. Sin agrupar, cada una crearia una carga suelta
     * y el panel se volveria ilegible. Con external_ref todas caen en la misma
     * carga y el historial de la pieza queda donde se puede leer.
     */
    private function submissionFor(
        Brand $brand,
        ?int $userId,
        string $source,
        ?string $channel,
        ?string $externalRef,
    ): Submission {
        if ($externalRef !== null) {
            $existente = Submission::query()
                ->where('brand_id', $brand->id)
                ->where('external_ref', $externalRef)
                ->latest('id')
                ->first();

            if ($existente !== null) {
                return $existente;
            }
        }

        return Submission::create([
            'brand_id' => $brand->id,
            'user_id' => $userId,
            'channel' => $channel,
            'campaign' => $externalRef !== null ? "Figma {$externalRef}" : null,
            'source' => $source,
            'external_ref' => $externalRef,
            'notes' => $channel === null
                ? 'Validacion sin canal declarado: no se evaluo formato.'
                : null,
        ]);
    }

    /**
     * Resuelve una marca desde "cliente/marca", validando el acceso.
     *
     * Se usan slugs y no ids numericos porque el identificador viaja en el
     * codigo del plugin: un id cambia entre entornos, un slug no.
     *
     * @param  array<int, int>|null  $accessibleBrandIds  null omite la comprobacion
     */
    public static function resolveBrand(string $referencia, ?array $accessibleBrandIds = null): Brand
    {
        $partes = array_values(array_filter(explode('/', trim($referencia, " /"))));

        if (count($partes) !== 2) {
            throw new RuntimeException(
                'El identificador de marca debe tener la forma cliente/marca, por ejemplo gloria/pro.'
            );
        }

        [$cliente, $marca] = $partes;

        $brand = Brand::query()
            ->where('slug', $marca)
            ->whereHas('client', fn ($q) => $q->where('slug', $cliente))
            ->with('client')
            ->first();

        if ($brand === null) {
            throw new RuntimeException("No existe la marca '{$referencia}'.");
        }

        if (! $brand->is_active) {
            throw new RuntimeException("La marca '{$referencia}' esta inactiva.");
        }

        if ($accessibleBrandIds !== null && ! in_array($brand->id, $accessibleBrandIds, true)) {
            throw new RuntimeException("Sin acceso a la marca '{$referencia}'.");
        }

        return $brand;
    }
}
