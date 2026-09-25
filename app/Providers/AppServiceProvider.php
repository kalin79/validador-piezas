<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AuditLogger;
use App\Services\RuleResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

use App\Models\Team;
use App\Models\User;
use App\Observers\TeamObserver;
use App\Observers\UserObserver;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

use App\Models\PromptTemplate;
use App\Observers\PromptTemplateObserver;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandAsset;
use App\Models\Client;
use App\Models\Palette;
use App\Models\RuleSet;
use App\Models\Submission;
use App\Observers\RuleSetObserver;
use App\Policies\AssetPolicy;
use App\Policies\BrandAssetPolicy;
use App\Policies\BrandPolicy;
use App\Policies\ClientPolicy;
use App\Policies\PalettePolicy;
use App\Policies\PromptTemplatePolicy;
use App\Policies\RuleSetPolicy;
use App\Policies\SubmissionPolicy;
use App\Policies\TeamPolicy;
use App\Policies\UserPolicy;


class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RuleResolver::class);
        $this->app->singleton(AuditLogger::class);
    }

    public function boot(): void
    {
        \App\Models\BrandAsset::observe(\App\Observers\BrandAssetObserver::class);
        $isProduction = $this->app->environment('production');

        PromptTemplate::observe(PromptTemplateObserver::class);

        RateLimiter::for('validaciones', fn(Request $request): Limit =>
            Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));

        Team::observe(TeamObserver::class);
        User::observe(UserObserver::class);

        // Existia en app/Observers pero nunca se registraba, asi que la
        // invariante de "una sola version publicada por dueno" solo la aplicaba
        // el boton del panel. Por seeder o por script quedaban dos vigentes.
        RuleSet::observe(RuleSetObserver::class);

        $this->registrarPoliticas();

        // Bitacora de auditoria: login, roles y accesos (ver RegistrarEnBitacora).
        \Illuminate\Support\Facades\Event::subscribe(\App\Listeners\RegistrarEnBitacora::class);

        /*
         * preventLazyLoading tambien en produccion.
         *
         * Antes era shouldBeStrict(!$isProduction): las consultas N+1 reventaban
         * en desarrollo y degradaban en silencio justo donde importa. Es
         * preferible un error visible a una pagina que tarda ocho segundos sin
         * que nadie sepa por que.
         */
        /*
         * Fuera de produccion, todo estricto: un N+1 o un atributo inexistente
         * revienta y se corrige antes de salir.
         *
         * En produccion, el lazy loading se REGISTRA en vez de lanzar. Antes
         * era estricto tambien aqui, y cualquier columna nueva de Filament que
         * leyera una relacion sin precargar convertia un problema de
         * rendimiento en un error 500 para el usuario.
         */
        Model::shouldBeStrict(! $isProduction);

        if ($isProduction) {
            Model::preventLazyLoading();
            Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relation): void {
                \Illuminate\Support\Facades\Log::warning('Lazy loading en produccion', [
                    'model' => $model::class,
                    'relation' => $relation,
                ]);
            });
        }
        DB::prohibitDestructiveCommands($isProduction);

    }

    /**
     * Registro explicito de las politicas.
     *
     * Laravel las descubre solo por convencion de nombres, pero en un sistema
     * de auditoria conviene que la lista este a la vista: si manana alguien
     * agrega un modelo con datos de cliente y olvida su politica, aqui se nota.
     * El descubrimiento automatico falla en silencio, y en silencio significa
     * "todo permitido".
     */
    private function registrarPoliticas(): void
    {
        $politicas = [
            User::class => UserPolicy::class,
            Team::class => TeamPolicy::class,
            Client::class => ClientPolicy::class,
            Brand::class => BrandPolicy::class,
            RuleSet::class => RuleSetPolicy::class,
            PromptTemplate::class => PromptTemplatePolicy::class,
            Palette::class => PalettePolicy::class,
            BrandAsset::class => BrandAssetPolicy::class,
            Asset::class => AssetPolicy::class,
            Submission::class => SubmissionPolicy::class,
        ];

        foreach ($politicas as $modelo => $politica) {
            Gate::policy($modelo, $politica);
        }
    }
}