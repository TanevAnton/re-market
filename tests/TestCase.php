<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Vite;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests render Blade, and the layout calls @vite(). Without a running dev
     * server or a built manifest that throws ViteManifestNotFoundException, so
     * every page test failed with an unrelated 500 instead of telling us
     * anything about the app.
     *
     * Laravel used to ship a WithoutVite trait for this; it is gone in 13, so
     * we bind a Vite that emits nothing. Overriding only the two methods Blade
     * calls means the rest of the real class - and any future additions to it -
     * stay intact.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null)
            {
                return '';
            }

            public function reactRefresh()
            {
                return '';
            }
        });
    }
}
