<?php

namespace Tests\Feature;

use App\Jobs\RunV2Diagnosis;
use App\Models\Sample;
use App\Models\User;
use App\Models\V2Diagnosis;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The V2 Diagnose portal around the Claude run: intake, the record, and the
 * endpoints the live viewer draws from.
 *
 * The full migration history does not replay onto an empty database (see
 * TaxonomyApiTest), so the few tables these pages touch are created here and
 * the V2 migration itself is run on top of them.
 */
class V2DiagnoseTest extends TestCase
{
    private User $user;
    private string $runs;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email'); $t->string('password');
            $t->rememberToken(); $t->timestamps();
        });
        foreach (['organs', 'stains'] as $tbl) {
            Schema::create($tbl, function (Blueprint $t) {
                $t->id(); $t->string('name'); $t->boolean('is_active')->default(true); $t->timestamps();
            });
        }
        Schema::create('samples', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organ_id')->nullable(); $t->unsignedBigInteger('stain_id')->nullable();
            $t->unsignedBigInteger('case_id')->nullable();
            $t->string('entity_submitter_id')->nullable(); $t->string('file_name')->nullable();
            $t->string('entity_type')->nullable(); $t->string('data_format')->nullable();
            $t->string('storage_status')->nullable(); $t->string('wsi_remote_path')->nullable();
            $t->boolean('is_usable')->default(true); $t->timestamps();
        });
        foreach (['2026_09_27_000001_create_v2_diagnoses_table', '2026_09_27_000002_add_usage_to_v2_diagnoses_table',
                  '2026_09_28_000001_add_diagnosis_code_to_v2_diagnoses_table'] as $m) {
            (require database_path("migrations/{$m}.php"))->up();
        }

        $this->user = User::forceCreate(['name' => 'Tester', 'email' => 't@example.com', 'password' => 'x']);
        $this->runs = storage_path('framework/testing/v2_runs');
        config(['v2_diagnose.runs_dir' => $this->runs]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runs);
        parent::tearDown();
    }

    private function sample(): Sample
    {
        return Sample::forceCreate(['entity_submitter_id' => 'TCGA-AN-A046', 'storage_status' => 'available',
                                    'wsi_remote_path' => 'slides/a.svs']);
    }

    public function test_pages_require_login(): void
    {
        $this->get('/admin/v2-diagnose')->assertRedirect();
    }

    public function test_index_lists_the_form_and_runs(): void
    {
        $this->actingAs($this->user)->get('/admin/v2-diagnose')
            ->assertOk()->assertSee('Run V2 Diagnose')->assertSee('No runs yet.');
    }

    public function test_a_run_on_an_archived_slide_is_recorded_and_queued(): void
    {
        Queue::fake();
        $s = $this->sample();

        $res = $this->actingAs($this->user)->post('/admin/v2-diagnose', [
            'source' => 'existing', 'sample_id' => $s->id,
            'organ' => 'Breast', 'stain' => 'H&E', 'age' => 58, 'sex' => 'female', 'race' => 'white',
        ]);

        $run = V2Diagnosis::sole();
        $res->assertRedirect("/admin/v2-diagnose/{$run->id}");
        $this->assertSame(['queued', 'Breast', 58, 'female', $this->user->id],
            [$run->status, $run->organ, $run->age, $run->sex, $run->user_id]);
        $this->assertCount(1, $run->events);
        Queue::assertPushed(RunV2Diagnosis::class, fn ($j) => $j->runId === $run->id
            && $j->connection === 'database_long' && $j->queue === 'v2');
    }

    public function test_organ_is_required_and_sex_is_constrained(): void
    {
        $s = $this->sample();
        $this->actingAs($this->user)->post('/admin/v2-diagnose', [
            'source' => 'existing', 'sample_id' => $s->id, 'sex' => 'unknown',
        ])->assertSessionHasErrors(['organ', 'sex']);
        $this->assertSame(0, V2Diagnosis::count());
    }

    public function test_a_completed_run_serves_its_result_and_records(): void
    {
        $s = $this->sample();
        $run = V2Diagnosis::create(['sample_id' => $s->id, 'organ' => 'Breast', 'status' => 'completed',
            'diagnosis_code' => 'IDC', 'diagnosis' => 'IDC — Invasive carcinoma of no special type', 'confidence' => 0.82,
            'summary' => "line one\nline two", 'regions_count' => 1]);
        $dir = "{$this->runs}/{$run->id}";
        File::ensureDirectoryExists("{$dir}/view");
        file_put_contents("{$dir}/final.json", json_encode(['slide_width' => 1000, 'regions' => [['id' => 'R1']]]));
        file_put_contents("{$dir}/prompt.md", 'the prompt');
        file_put_contents("{$dir}/view/P001.jpg", 'jpg');
        $run->update(['run_dir' => $dir]);

        $this->actingAs($this->user);
        $page = $this->get("/admin/v2-diagnose/{$run->id}")->assertOk()
            ->assertSee('Invasive carcinoma of no special type')->assertSee('82%')->assertSee('line two')
            ->assertSee('<span class="v2-code">IDC</span>', false);
        // The model vendor is named nowhere a user can read, markup and scripts included.
        $this->assertStringNotContainsStringIgnoringCase('claude', $page->getContent());
        $this->assertStringNotContainsStringIgnoringCase('claude',
            $this->get('/admin/v2-diagnose')->getContent());
        $this->getJson("/admin/v2-diagnose/{$run->id}/result")->assertOk()->assertJsonPath('regions.0.id', 'R1');
        $this->getJson("/admin/v2-diagnose/{$run->id}/status")->assertOk()->assertJsonPath('status', 'completed');
        $this->get("/admin/v2-diagnose/{$run->id}/download/prompt")->assertOk();
        $this->get("/admin/v2-diagnose/{$run->id}/asset/view/P001.jpg")->assertOk();

        // Only whitelisted names are served; nothing walks out of the run folder.
        $this->get("/admin/v2-diagnose/{$run->id}/asset/final.json")->assertNotFound();
        $this->get("/admin/v2-diagnose/{$run->id}/asset/view/..%2F..%2F.env")->assertNotFound();
        $this->get("/admin/v2-diagnose/{$run->id}/download/env")->assertNotFound();
        $this->get("/admin/v2-diagnose/{$run->id}/download/claude")->assertNotFound();
    }

    public function test_rerun_copies_the_inputs_into_a_new_run(): void
    {
        Queue::fake();
        $s = $this->sample();
        $old = V2Diagnosis::create(['sample_id' => $s->id, 'organ' => 'Breast', 'age' => 40,
            'status' => 'failed', 'error' => 'x']);

        $this->actingAs($this->user)->post("/admin/v2-diagnose/{$old->id}/rerun")->assertRedirect();

        $new = V2Diagnosis::latest('id')->first();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(['queued', 40, 'failed'], [$new->status, $new->age, $old->fresh()->status]);
        Queue::assertPushed(RunV2Diagnosis::class, 1);
    }

    public function test_a_duplicate_delivery_does_not_start_the_run_twice(): void
    {
        $run = V2Diagnosis::create(['organ' => 'Breast', 'status' => 'analysing']);
        (new RunV2Diagnosis($run->id))->handle(app(\App\Services\V2DiagnoseRunner::class));
        $this->assertSame('analysing', $run->fresh()->status);
    }

    public function test_the_prompt_carries_the_context_and_the_five_line_limit(): void
    {
        $run = V2Diagnosis::create(['organ' => 'Breast', 'stain' => 'H&E', 'age' => 58, 'sex' => 'female',
            'patches' => 73, 'patch_size' => 1904, 'target_mpp' => 0.5, 'slide_width' => 103584,
            'slide_height' => 42719, 'status' => 'queued']);
        $p = app(\App\Services\V2DiagnoseRunner::class)->buildPrompt($run);

        foreach (['Organ: Breast', 'Stain: H&E', 'Age: 58 years', 'Sex: female', 'Race / ancestry: not provided',
                  '73 tiles', '952 µm', 'at most 5 lines', '1000 × 1000', 'diagnosis_code', 'in English'] as $needle) {
            $this->assertStringContainsString($needle, $p);
        }
        $this->assertDoesNotMatchRegularExpression('/\{\{[A-Z_]+\}\}/', $p, 'a placeholder was left unfilled');
    }
}
