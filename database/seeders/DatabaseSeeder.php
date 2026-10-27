<?php

namespace Database\Seeders;

use App\Models\Advisor;
use App\Models\Fallback;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (Advisor::doesntExist()) {
            Advisor::restoreDefaults();
        }

        if (Fallback::doesntExist()) {
            Fallback::restoreDefaults();
        }
    }
}
