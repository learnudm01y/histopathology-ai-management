<?php

namespace Database\Seeders;

use App\Models\AiModel;
use Illuminate\Database\Seeder;

class AiModelSeeder extends Seeder
{
    public function run(): void
    {
        AiModel::updateOrCreate(
            ['name' => 'TITAN'],
            [
                'full_name'        => 'Multimodal Whole Slide Foundation Model for Pathology',
                'provider'         => 'MahmoodLab',
                'version'          => 'v1',
                'model_type'       => 'multimodal',
                'level'            => 'slide',
                'huggingface_url'  => 'https://huggingface.co/MahmoodLab/TITAN',
                'paper_url'        => 'https://arxiv.org/abs/2411.19666',
                'repo_url'         => 'https://github.com/mahmoodlab/TITAN',
                'input_resolution' => '512x512',
                'embedding_dim'    => '768',
                'parameters'       => '~1.1B',
                'license'          => 'CC-BY-NC-ND-4.0',
                'description'      => 'TITAN is a slide-level multimodal foundation model from MahmoodLab '
                                    . '(Harvard / BWH) for whole-slide pathology imaging. It combines a '
                                    . 'CONCH-based patch encoder with a Transformer slide aggregator and is '
                                    . 'pre-trained on 335,645 WSIs paired with synthetic captions and '
                                    . '423,122 pathology reports, enabling slide-level classification, '
                                    . 'retrieval, captioning and report generation.',
                'notes'            => 'Reference model selected for the initial training pipeline. '
                                    . 'Non-commercial license — research use only.',
                'is_active'        => true,
                'is_default'       => true,
            ]
        );
    }
}
