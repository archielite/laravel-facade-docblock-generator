<?php

namespace ArchiElite\LaravelFacadeDocBlockGenerator;

use ArchiElite\LaravelFacadeDocBlockGenerator\Commands\FacadeDocblockGenerateCommand;
use Illuminate\Support\ServiceProvider;

class LaravelFacadeDocBlockGeneratorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            FacadeDocblockGenerateCommand::class,
        ]);
    }
}
