<?php

namespace App\Providers;

use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        DevCommands::artisan('serve --port=3116', 'server');
        DevCommands::artisan('queue:listen --queue=council --tries=1 --timeout=0', 'council');
        DevCommands::artisan('agents:monitor', 'monitor');
    }
}
