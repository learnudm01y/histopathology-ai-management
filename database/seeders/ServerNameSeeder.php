<?php

namespace Database\Seeders;

use App\Models\ServerName;
use Illuminate\Database\Seeder;

class ServerNameSeeder extends Seeder
{
    public function run(): void
    {
        ServerName::updateOrCreate(
            ['name' => 'Hostinger Server'],
            [
                'type'        => 'local',
                'host'        => 'localhost',
                'description' => 'Main production server (Hostinger VPS). '
                               . 'Operations run locally on the same machine that hosts the web application. '
                               . 'Google Drive is accessed via rclone configured on this server.',
                'is_active'   => true,
            ]
        );
    }
}
