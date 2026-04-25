<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrganSeeder extends Seeder
{
    public function run(): void
    {
        $organs = [
            ['name' => 'Breast'],
            ['name' => 'Lung'],
            ['name' => 'Kidney'],
            ['name' => 'Brain'],
            ['name' => 'Liver'],
            ['name' => 'Stomach'],
            ['name' => 'Bowel'],
            ['name' => 'Pancreas'],
            ['name' => 'Skin'],
            ['name' => 'Thyroid'],
            ['name' => 'Bladder'],
            ['name' => 'Prostate'],
            ['name' => 'Ovary'],
            ['name' => 'Uterus'],
            ['name' => 'Cervix'],
            ['name' => 'Head & Neck'],
            ['name' => 'Lymph'],
            ['name' => 'Adrenal gland'],
            ['name' => 'Thymus'],
            ['name' => 'Testis'],
            ['name' => 'Soft tissue'],
            ['name' => 'Pleura'],
            ['name' => 'Eye'],
        ];

        foreach ($organs as $organ) {
            DB::table('organs')->insertOrIgnore(array_merge($organ, [
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
