<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DiseaseSubtype;
use App\Models\Organ;
use App\Models\ServerName;
use App\Models\Stain;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The taxonomy endpoints are what outside systems label slides against, so the
 * rules they enforce must be the ones the platform files slides by.
 *
 * The migration history does not replay onto an empty database (an early
 * migration drops columns the current create-table no longer makes), so this
 * runs inside a transaction on a MySQL database that already has the schema:
 *
 *   DB_CONNECTION=mysql DB_DATABASE=histo_api_test php artisan test --filter=TaxonomyApiTest
 */
class TaxonomyApiTest extends TestCase
{
    use DatabaseTransactions;

    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();

        ServerName::forceCreate(['name' => 'test-pod', 'api_key' => 'test-key', 'is_active' => true]);
        $this->auth = ['Authorization' => 'Bearer test-key', 'Accept' => 'application/json'];

        $breast = Organ::create(['name' => 'Breast', 'is_active' => true]);
        $tumor  = Category::create(['organ_id' => $breast->id, 'label_en' => 'Tumor', 'is_active' => true]);
        Category::create(['organ_id' => $breast->id, 'label_en' => 'Normal', 'is_active' => true]);

        $malignant = DiseaseSubtype::create(['category_id' => $tumor->id, 'name' => 'Malignant', 'is_active' => true]);
        DiseaseSubtype::create(['category_id' => $tumor->id, 'parent_id' => $malignant->id, 'name' => 'IDC', 'is_active' => true]);
        DiseaseSubtype::create(['category_id' => $tumor->id, 'parent_id' => $malignant->id, 'name' => 'ILC', 'is_active' => true]);

        Stain::create(['name' => 'Hematoxylin & Eosin', 'abbreviation' => 'H&E', 'stain_type' => 'routine', 'is_active' => true]);
    }

    public function test_every_taxonomy_endpoint_requires_a_server_key(): void
    {
        foreach (['', '/organs', '/stains', '/reference', '/resolve'] as $path) {
            $this->getJson("/api/v1/taxonomy{$path}")->assertStatus(401);
            $this->getJson("/api/v1/taxonomy{$path}", ['Authorization' => 'Bearer wrong'])->assertStatus(403);
        }
    }

    public function test_tree_nests_diseases_under_their_parent(): void
    {
        $res = $this->getJson('/api/v1/taxonomy', $this->auth)->assertOk();

        $tumor = collect($res->json('organs.0.categories'))->firstWhere('label', 'Tumor');
        $this->assertSame('Malignant', $tumor['diseases'][0]['name']);
        $this->assertFalse($tumor['diseases'][0]['is_leaf']);
        $this->assertSame(['IDC', 'ILC'], array_column($tumor['diseases'][0]['children'], 'name'));
        $this->assertSame(2, $tumor['diseases'][0]['children'][0]['depth']);
    }

    public function test_tree_filters_by_organ_and_rejects_an_unknown_one(): void
    {
        $this->getJson('/api/v1/taxonomy?organ=breast', $this->auth)->assertOk()->assertJsonCount(1, 'organs');
        $this->getJson('/api/v1/taxonomy?organ=Mars', $this->auth)->assertNotFound();
    }

    public function test_resolve_accepts_a_leaf_by_name_and_returns_ids(): void
    {
        $this->getJson('/api/v1/taxonomy/resolve?organ=breast&category=tumor&disease=idc&stain=' . urlencode('H&E'), $this->auth)
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('classification.disease.path', ['Malignant', 'IDC'])
            ->assertJsonPath('qualified_name', 'Breast › Tumor › Malignant › IDC');
    }

    public function test_resolve_refuses_a_disease_that_is_refined_further(): void
    {
        $res = $this->getJson('/api/v1/taxonomy/resolve?organ=Breast&category=Tumor&disease=Malignant', $this->auth)
            ->assertStatus(422)
            ->assertJsonPath('valid', false);

        $this->assertStringContainsString('IDC, ILC', $res->json('errors.0'));
    }

    public function test_resolve_refuses_a_disease_from_another_category(): void
    {
        $this->getJson('/api/v1/taxonomy/resolve?organ=Breast&category=Normal&disease=IDC', $this->auth)
            ->assertStatus(422)
            ->assertJsonPath('errors.0', 'Disease "IDC" belongs to category "Tumor", not "Normal".');
    }

    public function test_resolve_requires_organ_and_category(): void
    {
        $this->getJson('/api/v1/taxonomy/resolve', $this->auth)
            ->assertStatus(422)
            ->assertJsonPath('errors', ['organ is required.', 'category is required.']);
    }
}
