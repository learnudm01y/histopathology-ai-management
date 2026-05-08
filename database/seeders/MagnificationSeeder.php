<?php

namespace Database\Seeders;

use App\Models\Magnification;
use Illuminate\Database\Seeder;

class MagnificationSeeder extends Seeder
{
    public function run(): void
    {
        $entries = [
            ['label' => 'x10', 'value' => 10, 'folder_name' => '10x', 'notes' => 'Low magnification'],
            ['label' => 'x20', 'value' => 20, 'folder_name' => '20x', 'notes' => 'Standard magnification'],
            ['label' => 'x40', 'value' => 40, 'folder_name' => '40x', 'notes' => 'High magnification'],
        ];

        foreach ($entries as $entry) {
            Magnification::firstOrCreate(
                ['label' => $entry['label']],
                $entry + ['is_active' => true],
            );
        }
    }
}
