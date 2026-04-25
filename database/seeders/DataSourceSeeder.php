<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DataSourceSeeder extends Seeder
{
    public function run(): void
    {
        $breastOrganId = DB::table('organs')->where('name', 'Breast')->value('id');

        DB::table('data_sources')->insertOrIgnore([
            [
                'name'                   => 'TCGA-BRCA',
                'full_name'              => 'The Cancer Genome Atlas - Breast Invasive Carcinoma',
                'source_type'            => 'TCGA',
                'base_url'               => 'https://api.gdc.cancer.gov/',
                'access_type'            => 'open',
                'organ_id'               => $breastOrganId,
                'description'            => 'TCGA Breast cancer dataset — Normal and Tumor SVS slides',
                'total_slides_available' => 1098,
                'is_active'              => true,
                'created_at'             => now(),
                'updated_at'             => now(),
            ],
        ]);
    }
}
