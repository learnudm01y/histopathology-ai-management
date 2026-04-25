<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StainSeeder extends Seeder
{
    public function run(): void
    {
        $stains = [
            // ── Routine ─────────────────────────────────────────────────────
            [
                'name'         => 'Hematoxylin & Eosin',
                'abbreviation' => 'H&E',
                'stain_type'   => 'routine',
                'marker'       => null,
                'description'  => 'The standard histological stain. Haematoxylin stains nuclei blue-purple; eosin stains cytoplasm and extracellular matrix pink. Used for virtually all WSI in TCGA/GDC datasets.',
            ],

            // ── Special stains ───────────────────────────────────────────────
            [
                'name'         => 'Masson\'s Trichrome',
                'abbreviation' => 'MT',
                'stain_type'   => 'special',
                'marker'       => null,
                'description'  => 'Differentiates collagen (blue/green) from muscle (red). Used for fibrosis assessment in liver, kidney, heart.',
            ],
            [
                'name'         => 'Periodic Acid-Schiff',
                'abbreviation' => 'PAS',
                'stain_type'   => 'special',
                'marker'       => null,
                'description'  => 'Highlights glycogen, glycoproteins, and mucins magenta. Key for kidney pathology and fungal organisms.',
            ],
            [
                'name'         => 'Periodic Acid-Schiff with Diastase',
                'abbreviation' => 'PAS-D',
                'stain_type'   => 'special',
                'marker'       => null,
                'description'  => 'PAS after diastase digestion — removes glycogen, leaving only glycoproteins/mucins. Distinguishes hepatocellular carcinoma from metastatic lesions.',
            ],
            [
                'name'         => 'Alcian Blue',
                'abbreviation' => 'AB',
                'stain_type'   => 'special',
                'marker'       => null,
                'description'  => 'Stains acidic mucins blue. Used for GI, lung, and salivary gland pathology.',
            ],
            [
                'name'         => 'Congo Red',
                'abbreviation' => 'CR',
                'stain_type'   => 'special',
                'marker'       => null,
                'description'  => 'Detects amyloid deposits (apple-green birefringence under polarised light). Used in kidney, heart, and soft tissue biopsies.',
            ],
            [
                'name'         => 'Silver (Reticulin) Stain',
                'abbreviation' => 'Reticulin',
                'stain_type'   => 'special',
                'marker'       => null,
                'description'  => 'Impregnates reticulin fibres (collagen type III) black. Used for liver architecture assessment and haematological tumours.',
            ],
            [
                'name'         => 'Gomori Methenamine Silver',
                'abbreviation' => 'GMS',
                'stain_type'   => 'special',
                'marker'       => null,
                'description'  => 'Stains fungal cell walls black. Used in lung and soft tissue infections.',
            ],
            [
                'name'         => 'Ziehl-Neelsen (AFB)',
                'abbreviation' => 'ZN',
                'stain_type'   => 'special',
                'marker'       => null,
                'description'  => 'Acid-fast bacilli stain — mycobacterial infections (TB, leprosy). Bacilli stain red on blue background.',
            ],
            [
                'name'         => 'Oil Red O',
                'abbreviation' => 'ORO',
                'stain_type'   => 'special',
                'marker'       => null,
                'description'  => 'Stains neutral lipids and triglycerides red. Used on frozen sections for hepatic steatosis and liposarcoma.',
            ],
            [
                'name'         => 'Movat Pentachrome',
                'abbreviation' => 'Movat',
                'stain_type'   => 'special',
                'marker'       => null,
                'description'  => 'Differentiates five tissue components simultaneously. Used in cardiovascular and connective tissue pathology.',
            ],

            // ── IHC — common panels ─────────────────────────────────────────
            [
                'name'         => 'Immunohistochemistry — ER',
                'abbreviation' => 'IHC-ER',
                'stain_type'   => 'IHC',
                'marker'       => 'ER',
                'description'  => 'Oestrogen Receptor. Key biomarker for breast cancer hormone receptor status.',
            ],
            [
                'name'         => 'Immunohistochemistry — PR',
                'abbreviation' => 'IHC-PR',
                'stain_type'   => 'IHC',
                'marker'       => 'PR',
                'description'  => 'Progesterone Receptor. Breast cancer biomarker, used alongside ER.',
            ],
            [
                'name'         => 'Immunohistochemistry — HER2',
                'abbreviation' => 'IHC-HER2',
                'stain_type'   => 'IHC',
                'marker'       => 'HER2',
                'description'  => 'Human Epidermal Growth Factor Receptor 2. Graded 0–3+ for breast and gastric cancer.',
            ],
            [
                'name'         => 'Immunohistochemistry — Ki67',
                'abbreviation' => 'IHC-Ki67',
                'stain_type'   => 'IHC',
                'marker'       => 'Ki67',
                'description'  => 'Proliferation index marker. High Ki67% correlates with aggressive tumours.',
            ],
            [
                'name'         => 'Immunohistochemistry — p53',
                'abbreviation' => 'IHC-p53',
                'stain_type'   => 'IHC',
                'marker'       => 'p53',
                'description'  => 'TP53 tumour suppressor protein. Aberrant p53 pattern suggests TP53 mutation.',
            ],
            [
                'name'         => 'Immunohistochemistry — PD-L1',
                'abbreviation' => 'IHC-PDL1',
                'stain_type'   => 'IHC',
                'marker'       => 'PD-L1',
                'description'  => 'Immune checkpoint marker. Used to select patients for immunotherapy (lung, bladder, cervix, etc.).',
            ],
            [
                'name'         => 'Immunohistochemistry — CD3',
                'abbreviation' => 'IHC-CD3',
                'stain_type'   => 'IHC',
                'marker'       => 'CD3',
                'description'  => 'Pan-T-cell marker. Used in lymphoma classification and tumour-infiltrating lymphocyte quantification.',
            ],
            [
                'name'         => 'Immunohistochemistry — CD20',
                'abbreviation' => 'IHC-CD20',
                'stain_type'   => 'IHC',
                'marker'       => 'CD20',
                'description'  => 'Pan-B-cell marker. Key for B-cell lymphoma diagnosis.',
            ],
            [
                'name'         => 'Immunohistochemistry — TTF-1',
                'abbreviation' => 'IHC-TTF1',
                'stain_type'   => 'IHC',
                'marker'       => 'TTF-1',
                'description'  => 'Thyroid Transcription Factor 1. Expressed in lung adenocarcinoma and thyroid carcinoma.',
            ],
            [
                'name'         => 'Immunohistochemistry — EGFR',
                'abbreviation' => 'IHC-EGFR',
                'stain_type'   => 'IHC',
                'marker'       => 'EGFR',
                'description'  => 'Epidermal Growth Factor Receptor. Overexpressed in lung, colorectal, and head & neck cancers.',
            ],

            // ── ISH ────────────────────────────────────────────────────────
            [
                'name'         => 'Fluorescence In-Situ Hybridisation',
                'abbreviation' => 'FISH',
                'stain_type'   => 'ISH',
                'marker'       => null,
                'description'  => 'Detects gene amplifications/deletions/translocations (e.g. HER2 amplification, ALK rearrangement). Requires fluorescent microscopy.',
            ],
            [
                'name'         => 'Chromogenic In-Situ Hybridisation',
                'abbreviation' => 'CISH',
                'stain_type'   => 'ISH',
                'marker'       => null,
                'description'  => 'Light-microscopy alternative to FISH. Used for HER2 and HPV gene copy number assessment.',
            ],

            // ── Cytology ────────────────────────────────────────────────────
            [
                'name'         => 'Papanicolaou',
                'abbreviation' => 'Pap',
                'stain_type'   => 'cytology',
                'marker'       => null,
                'description'  => 'Standard cervical cytology stain. Multi-colour differentiation of epithelial cell maturity. Also used in respiratory and urinary cytology.',
            ],
            [
                'name'         => 'Giemsa',
                'abbreviation' => 'Giemsa',
                'stain_type'   => 'cytology',
                'marker'       => null,
                'description'  => 'Blood and bone marrow smear stain. Differentiates cell types by colour. Used in haematological malignancies.',
            ],
            [
                'name'         => 'Diff-Quik',
                'abbreviation' => 'DQ',
                'stain_type'   => 'cytology',
                'marker'       => null,
                'description'  => 'Rapid Romanowsky-type stain for FNA cytology. Air-dried smears only.',
            ],
        ];

        foreach ($stains as $stain) {
            DB::table('stains')->updateOrInsert(
                ['abbreviation' => $stain['abbreviation']],
                array_merge($stain, [
                    'is_active'  => true,
                    'notes'      => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
