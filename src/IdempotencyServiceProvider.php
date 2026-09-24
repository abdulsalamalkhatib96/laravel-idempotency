<?php

namespace Abdulsalam\LaravelIdempotency;

use Abdulsalam\LaravelIdempotency\Console\DoctorCommand;
use Abdulsalam\LaravelIdempotency\Console\InspectCommand;
use Abdulsalam\LaravelIdempotency\Console\PruneCommand;
use Abdulsalam\LaravelIdempotency\Console\RecoverOrphansCommand;
use Abdulsalam\LaravelIdempotency\Contracts\IdempotencyKeyResolver;
use Abdulsalam\LaravelIdempotency\Contracts\LockManager;
use Abdulsalam\LaravelIdempotency\Contracts\PrincipalResolver;
use Abdulsalam\LaravelIdempotency\Contracts\RequestFingerprinter;
use Abdulsalam\LaravelIdempotency\Contracts\ResponseCodec;
use Abdulsalam\LaravelIdempotency\Contracts\ScopeResolver;
use Abdulsalam\LaravelIdempotency\Contracts\TenantResolver;
use Abdulsalam\LaravelIdempotency\Fingerprint\DefaultRequestFingerprinter;
use Abdulsalam\LaravelIdempotency\Http\Middleware\EnsureIdempotency;
use Abdulsalam\LaravelIdempotency\Keys\HeaderKeyResolver;
use Abdulsalam\LaravelIdempotency\Locking\LaravelLockManager;
use Abdulsalam\LaravelIdempotency\Recovery\RecoveryManager;
use Abdulsalam\LaravelIdempotency\Response\LaravelResponseCodec;
use Abdulsalam\LaravelIdempotency\Scope\DefaultPrincipalResolver;
use Abdulsalam\LaravelIdempotency\Scope\DefaultScopeResolver;
use Abdulsalam\LaravelIdempotency\Scope\HeaderTenantResolver;
use Abdulsalam\LaravelIdempotency\Storage\StoreManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;

final class IdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/idempotency.php', 'idempotency');

        $this->app->bind(IdempotencyKeyResolver::class, HeaderKeyResolver::class);
        $this->app->bind(PrincipalResolver::class, DefaultPrincipalResolver::class);
        $this->app->bind(TenantResolver::class, HeaderTenantResolver::class);
        $this->app->bind(ScopeResolver::class, DefaultScopeResolver::class);
        $this->app->bind(RequestFingerprinter::class, DefaultRequestFingerprinter::class);
        $this->app->bind(ResponseCodec::class, LaravelResponseCodec::class);
        $this->app->bind(LockManager::class, LaravelLockManager::class);

        $this->app->scoped(CurrentIdempotencyContext::class);
        $this->app->singleton(StoreManager::class);
        $this->app->singleton(RecoveryManager::class);
        $this->app->singleton(IdempotencyManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/idempotency.php' => config_path('idempotency.php'),
        ], 'idempotency-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations/2026_01_01_000000_create_idempotency_records_table.php' => database_path('migrations/2026_01_01_000000_create_idempotency_records_table.php'),
        ], 'idempotency-migrations');


        $alias = (string) config('idempotency.middleware_alias', 'idempotency');
        $this->app['router']->aliasMiddleware($alias, EnsureIdempotency::class);

        if (! Route::hasMacro('idempotent')) {
            Route::macro('idempotent', function (?string $profile = null) {
                /** @var \Illuminate\Routing\Route $this */
                return $this->middleware((string) config('idempotency.middleware_alias', 'idempotency').':'.($profile ?: (string) config('idempotency.default_profile', 'default')));
            });
        }

        if ((bool) config('idempotency.cleanup.enabled', true)) {
            $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('idempotency:prune')->hourly()->onOneServer();
            });
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneCommand::class,
                InspectCommand::class,
                RecoverOrphansCommand::class,
                DoctorCommand::class,
            ]);
        }
    }
}
