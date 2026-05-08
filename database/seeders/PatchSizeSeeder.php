<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\PatchSize;
use Illuminate\Database\Seeder;

class PatchSizeSeeder extends Seeder
{
    public function run(): void
    {
        // Resolve AI model IDs for FK references (nullable if not seeded yet)
        $titanId  = AiModel::where('name', 'TITAN')->value('id');
        $conchId  = AiModel::where('name', 'CONCH')->value('id');
        $unifId   = AiModel::where('name', 'UNI')->value('id');

        $sizes = [
            [
                'size_px'     => 224,
                'label'       => '224×224 — ViT Standard (TITAN / CONCH / UNI)',
                'wsi_level'   => 0,
                'overlap_px'  => 0,
                'ai_model_id' => $titanId,
                'notes'       => 'Standard patch size for Vision Transformer models. '
                               . 'Compatible with TITAN, CONCH, UNI and most ViT-based encoders.',
                'is_active'   => true,
            ],
            [
                'size_px'     => 256,
                'label'       => '256×256 — Classic CNN / ResNet',
                'wsi_level'   => 0,
                'overlap_px'  => 0,
                'ai_model_id' => null,
                'notes'       => 'Classic patch size used with ResNet / DenseNet CNN encoders.',
                'is_active'   => true,
            ],
            [
                'size_px'     => 512,
                'label'       => '512×512 — High Resolution',
                'wsi_level'   => 0,
                'overlap_px'  => 0,
                'ai_model_id' => null,
                'notes'       => 'Larger context window for models that benefit from high-resolution input.',
                'is_active'   => true,
            ],
            [
                'size_px'     => 256,
                'label'       => '256×256 — Overlapping 64px',
                'wsi_level'   => 0,
                'overlap_px'  => 64,
                'ai_model_id' => null,
                'notes'       => '25% overlap to reduce boundary artefacts during feature aggregation.',
                'is_active'   => true,
            ],
        ];

        foreach ($sizes as $row) {
            PatchSize::updateOrCreate(
                [
                    'size_px'    => $row['size_px'],
                    'wsi_level'  => $row['wsi_level'],
                    'overlap_px' => $row['overlap_px'],
                ],
                $row
            );
        }
    }
}
