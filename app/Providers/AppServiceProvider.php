<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Contracts\EmbeddingProvider::class, \App\Services\EmbeddingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('context', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($request->user()?->id ?? $request->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('login', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        foreach ([\App\Models\Project::class, \App\Models\Workspace::class, \App\Models\Memory::class, \App\Models\Document::class, \App\Models\Conversation::class, \App\Models\ContextRun::class] as $model) {
            \Illuminate\Support\Facades\Gate::policy($model, \App\Policies\OwnedPolicy::class);
        }
    }
}
