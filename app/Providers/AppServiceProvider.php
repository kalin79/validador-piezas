<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AuditLogger;
use App\Services\RuleResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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

        Model::shouldBeStrict(!$isProduction);
        DB::prohibitDestructiveCommands($isProduction);

    }
}