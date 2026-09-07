<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // update(['status' => ...]) on a non-fillable attribute is a silent
        // no-op by default. That is how Listing::publish() appeared to work
        // while never actually leaving 'draft'. Outside production, throw
        // instead - a loud failure beats a listing nobody can see.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Model::preventLazyLoading() is worth enabling too, once there is
        // time to chase the N+1s it will surface.
    }
}
