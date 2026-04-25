<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'label_en'   => 'Normal',
                'is_active'  => true,
                'notes'      => 'Non-cancerous / healthy tissue slides (TCGA code 11A)',
            ],
            [
                'label_en'   => 'Tumor',
                'is_active'  => true,
                'notes'      => 'Cancerous / tumor tissue slides (TCGA code 01Z)',
            ],
            [
                'label_en'   => 'Unknown',
                'is_active'  => true,
                'notes'      => 'Category not yet determined',
            ],
        ];

        foreach ($categories as $cat) {
            DB::table('categories')->insertOrIgnore(array_merge($cat, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
