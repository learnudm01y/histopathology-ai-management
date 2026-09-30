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
            $t->unsignedBigInteger('category_id')->nullable(); $t->unsignedBigInteger('disease_subtype_id')->nullable();
            $t->string('entity_submitter_id')->nullable(); $t->string('file_name')->nullable();
            $t->string('entity_type')->nullable(); $t->string('data_format')->nullable();
            $t->string('storage_status')->nullable(); $t->string('wsi_remote_path')->nullable();
            $t->boolean('is_usable')->default(true); $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organ_id')->nullable(); $t->string('label_en'); $t->timestamps();
        });
        Schema::create('disease_subtypes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('category_id')->nullable(); $t->string('name'); $t->timestamps();
        });
        // The archive search reaches the case and its clinical record.
        Schema::create('cases', function (Blueprint $t) {
            $t->id(); $t->string('case_id')->nullable(); $t->string('submitter_id')->nullable();
            $t->string('project_id')->nullable(); $t->timestamps();
        });
        Schema::create('clinical_slide_case_information', function (Blueprint $t) {
            $t->id(); $t->string('case_id'); $t->string('primary_diagnosis')->nullable();
            $t->string('site_of_resection_or_biopsy')->nullable(); $t->timestamps();
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
        ])->assertSessionHasErrors(['sex']);
        // An archive slide takes its organ from the database; a new slide must name one.
        $this->post('/admin/v2-diagnose', ['source' => 'server_path', 'server_path' => '/var/www/HISTO_AI/x.svs'])
            ->assertSessionHasErrors(['organ']);
        $this->assertSame(0, V2Diagnosis::count());
    }

    public function test_a_run_can_be_deleted_with_its_files_but_not_while_running(): void
    {
        $s = $this->sample();
        $done = V2Diagnosis::create(['sample_id' => $s->id, 'organ' => 'Breast', 'status' => 'completed']);
        $dir = "{$this->runs}/{$done->id}";
        File::ensureDirectoryExists($dir);
        file_put_contents("{$dir}/final.json", '{}');
        $done->update(['run_dir' => $dir]);

        $this->actingAs($this->user)->delete("/admin/v2-diagnose/{$done->id}")
            ->assertRedirect('/admin/v2-diagnose')->assertSessionHas('success');
        $this->assertNull(V2Diagnosis::find($done->id));
        $this->assertDirectoryDoesNotExist($dir);

        $busy = V2Diagnosis::create(['sample_id' => $s->id, 'organ' => 'Breast', 'status' => 'analysing']);
        $this->delete("/admin/v2-diagnose/{$busy->id}")->assertSessionHasErrors('run');
        $this->assertNotNull(V2Diagnosis::find($busy->id));

        // A folder outside the runs directory is never removed.
        $outside = storage_path('framework/testing/v2_outside');
        File::ensureDirectoryExists($outside);
        $odd = V2Diagnosis::create(['sample_id' => $s->id, 'organ' => 'Breast', 'status' => 'failed', 'run_dir' => $outside]);
        $this->delete("/admin/v2-diagnose/{$odd->id}")->assertSessionHas('success');
        $this->assertDirectoryExists($outside);
        File::deleteDirectory($outside);
    }

    public function test_deleting_a_run_removes_its_claude_records_and_keeps_the_slide(): void
    {
        $home = storage_path('framework/testing/v2_home');
        config(['v2_diagnose.claude.home' => $home]);
        $s = $this->sample();
        $run = V2Diagnosis::create(['sample_id' => $s->id, 'organ' => 'Breast', 'status' => 'completed',
                                    'claude_session_id' => '11111111-2222-3333-4444-555555555555']);
        $other = V2Diagnosis::create(['sample_id' => $s->id, 'organ' => 'Breast', 'status' => 'completed']);
        $project = fn ($r) => "{$home}/.claude/projects/" . preg_replace('/[^a-zA-Z0-9]/', '-', "{$this->runs}/{$r->id}");
        foreach ([$run, $other] as $r) {
            File::ensureDirectoryExists("{$this->runs}/{$r->id}");
            $r->update(['run_dir' => "{$this->runs}/{$r->id}"]);
            File::ensureDirectoryExists($project($r));
        }
        // Two sessions for the run (a repair resumes into a new one), one for the other run.
        file_put_contents($project($run) . '/aaaaaaaa-0000-0000-0000-000000000001.jsonl', '{}');
        file_put_contents($project($other) . '/bbbbbbbb-0000-0000-0000-000000000002.jsonl', '{}');
        foreach (['11111111-2222-3333-4444-555555555555', 'aaaaaaaa-0000-0000-0000-000000000001',
                  'bbbbbbbb-0000-0000-0000-000000000002'] as $id) {
            File::ensureDirectoryExists("{$home}/.claude/session-env/{$id}");
        }

        $this->actingAs($this->user)->delete("/admin/v2-diagnose/{$run->id}")->assertSessionHas('success');

        $this->assertDirectoryDoesNotExist($project($run));
        $this->assertDirectoryDoesNotExist("{$home}/.claude/session-env/11111111-2222-3333-4444-555555555555");
        $this->assertDirectoryDoesNotExist("{$home}/.claude/session-env/aaaaaaaa-0000-0000-0000-000000000001");
        // Another run's records, and the slide, stay.
        $this->assertDirectoryExists($project($other));
        $this->assertDirectoryExists("{$home}/.claude/session-env/bbbbbbbb-0000-0000-0000-000000000002");
        $this->assertDirectoryExists("{$this->runs}/{$other->id}");
        $this->assertNotNull(Sample::find($s->id));
        $this->assertNotNull(V2Diagnosis::find($other->id));
        File::deleteDirectory($home);
    }

    public function test_the_archive_is_paged_and_filtered_on_the_server(): void
    {
        $organ = \App\Models\Organ::firstOrCreate(['name' => 'Breast']);
        $ids = collect(range(1, 30))->map(fn ($i) => Sample::forceCreate([
            'entity_submitter_id' => sprintf('TCGA-XX-%04d', $i), 'organ_id' => $organ->id,
            'storage_status' => 'available', 'wsi_remote_path' => "slides/{$i}.svs",
        ])->id);
        V2Diagnosis::create(['sample_id' => $ids[0], 'organ' => 'Breast', 'status' => 'completed']);
        $url = "/admin/v2-diagnose/archive?organ_id={$organ->id}";

        $this->actingAs($this->user)->getJson("{$url}&page=2&per_page=25")->assertOk()
            ->assertJsonPath('total', 30)->assertJsonPath('pages', 2)->assertJsonPath('used_total', 1)
            ->assertJsonCount(5, 'samples');
        $this->getJson("{$url}&unused=1")->assertJsonPath('total', 29);
        $this->getJson("{$url}&q=XX-0007")->assertJsonPath('total', 1)
            ->assertJsonPath('samples.0.id', $ids[6]);
    }

    public function test_an_archive_slide_brings_its_own_clinical_context(): void
    {
        Queue::fake();
        $organ = \App\Models\Organ::firstOrCreate(['name' => 'Breast']);
        $s = $this->sample();
        $s->update(['organ_id' => $organ->id]);

        $this->actingAs($this->user)->post('/admin/v2-diagnose', ['source' => 'existing', 'sample_id' => $s->id])
            ->assertSessionHasNoErrors();
        $this->assertSame('Breast', V2Diagnosis::latest('id')->first()->organ);

        $this->getJson("/admin/v2-diagnose/archive?organ_id={$organ->id}&stain=all")
            ->assertOk()->assertJsonPath('samples.0.id', $s->id)->assertJsonPath('samples.0.runs.0.status', 'queued');
    }

    public function test_a_completed_run_serves_its_result_and_records(): void
    {
        $s = $this->sample();
        $run = V2Diagnosis::create(['sample_id' => $s->id, 'organ' => 'Breast', 'status' => 'completed',
            'diagnosis_code' => 'IDC', 'diagnosis' => 'IDC — Invasive carcinoma of no special type', 'confidence' => 0.82,
            'summary' => "line one\nline two", 'regions_count' => 1]);
        $dir = "{$this->runs}/{$run->id}";
        File::ensureDirectoryExists("{$dir}/view");
        file_put_contents("{$dir}/final.json", json_encode(['slide_width' => 1000, 'regions' => [['id' => 'R1']],
            'density' => ['P001' => array_fill(0, 50, array_fill(0, 50, 0.5))]]));
        touch("{$dir}/final.json", time() - 60);
        file_put_contents("{$dir}/view.json", json_encode(['slide_width' => 1000, 'regions' => [['id' => 'R1']],
            'tumour_mask' => ['polygons' => []], 'coverage' => ['tissue_read' => 0.998, 'tumour_tiles' => 3]]));
        file_put_contents("{$dir}/heat.json", json_encode(['grid' => 50, 'model' => ['P001' => base64_encode(str_repeat("\x80", 2500))], 'density' => []]));
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
        $file = fn ($res) => file_get_contents($res->baseResponse->getFile()->getPathname());
        $plain = $this->get("/admin/v2-diagnose/{$run->id}/result")->assertOk();
        $this->assertSame('R1', json_decode($file($plain), true)['regions'][0]['id']);

        // What the viewer draws first is the small vector file, gzipped, never
        // the full record: final.json once took ~a minute to arrive and the
        // mask and regions showed nothing until it did.
        $res = $this->get("/admin/v2-diagnose/{$run->id}/result", ['Accept-Encoding' => 'gzip, deflate']);
        $res->assertOk()->assertHeader('Content-Encoding', 'gzip');
        $body = json_decode(gzdecode($file($res)), true);
        $this->assertSame('R1', $body['regions'][0]['id']);
        $this->assertArrayNotHasKey('density', $body, 'heat grids must not ride on the drawing payload');
        $heat = json_decode($file($this->get("/admin/v2-diagnose/{$run->id}/heat")->assertOk()), true);
        $this->assertSame(50, $heat['grid']);
        $page->assertSee('99.8% of the slide');
        $this->getJson("/admin/v2-diagnose/{$run->id}/status")->assertOk()->assertJsonPath('status', 'completed');
        $this->get("/admin/v2-diagnose/{$run->id}/download/prompt")->assertOk();
        $this->get("/admin/v2-diagnose/{$run->id}/asset/view/P001.jpg")->assertOk();

        // Only whitelisted names are served; nothing walks out of the run folder.
        $this->get("/admin/v2-diagnose/{$run->id}/asset/final.json")->assertNotFound();
        $this->get("/admin/v2-diagnose/{$run->id}/asset/view/..%2F..%2F.env")->assertNotFound();
        $this->get("/admin/v2-diagnose/{$run->id}/download/env")->assertNotFound();
        $this->get("/admin/v2-diagnose/{$run->id}/download/claude")->assertNotFound();
    }

    public function test_each_answer_is_judged_against_the_recorded_diagnosis(): void
    {
        $tumour = \Illuminate\Support\Facades\DB::table('categories')->insertGetId(['label_en' => 'tumor']);
        $normal = \Illuminate\Support\Facades\DB::table('categories')->insertGetId(['label_en' => 'normal']);
        $benign = \Illuminate\Support\Facades\DB::table('categories')->insertGetId(['label_en' => 'benign']);
        $idc = \Illuminate\Support\Facades\DB::table('disease_subtypes')->insertGetId(['category_id' => $tumour, 'name' => 'IDC']);
        $ilc = \Illuminate\Support\Facades\DB::table('disease_subtypes')->insertGetId(['category_id' => $tumour, 'name' => 'ILC']);
        $pb = \Illuminate\Support\Facades\DB::table('disease_subtypes')->insertGetId(['category_id' => $benign, 'name' => 'Pathological Benign (PB)']);

        $case = function (?int $cat, ?int $sub, ?string $code, string $status = 'completed') {
            $s = Sample::forceCreate(['entity_submitter_id' => 'S', 'category_id' => $cat, 'disease_subtype_id' => $sub]);
            return V2Diagnosis::create(['sample_id' => $s->id, 'organ' => 'Breast', 'status' => $status,
                'diagnosis_code' => $code, 'diagnosis' => "{$code} — x"]);
        };
        $expect = [
            [$tumour, $idc, 'IDC', 'correct'], [$tumour, $ilc, 'ILC', 'correct'], [$tumour, $ilc, 'IDC', 'wrong'],
            [$tumour, $idc, 'MIXED', 'partial'], [$tumour, $idc, 'NORMAL', 'wrong'],
            [$normal, null, 'NORMAL', 'correct'], [$normal, null, 'BENIGN', 'correct'],
            [$normal, null, 'NONDX', 'partial'], [$normal, null, 'IDC', 'wrong'],
            [$benign, $pb, 'FA', 'correct'], [$benign, $pb, 'NORMAL', 'partial'], [$benign, $pb, 'LCIS', 'wrong'],
            [$benign, $pb, 'SA', 'correct'], [$benign, $pb, 'ALH', 'partial'], [$benign, $pb, 'SUSP', 'partial'],
            [$tumour, $idc, 'SUSP', 'partial'], [$normal, null, 'SUSP', 'partial'],
        ];
        foreach ($expect as [$cat, $sub, $code, $result]) {
            $this->assertSame($result, $case($cat, $sub, $code)->fresh()->verdict()['result'], "{$code} on {$cat}/{$sub}");
        }
        // Nothing to judge: no recorded diagnosis, or not finished.
        $this->assertNull($case(null, null, 'IDC')->fresh()->verdict());
        $this->assertNull($case($tumour, $idc, null, 'analysing')->fresh()->verdict());

        $page = $this->actingAs($this->user)->get('/admin/v2-diagnose')->assertOk();
        $page->assertSee('Against the recorded diagnosis (17 runs)')->assertSee('6 correct')
             ->assertSee('7 partial')->assertSee('4 wrong');
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

    public function test_tool_calls_outside_the_run_folder_are_violations(): void
    {
        $runner = app(\App\Services\V2DiagnoseRunner::class);
        $dir = $this->runs . '/7';
        File::ensureDirectoryExists($dir);
        $py = $runner->pythonBin();
        $ok = [
            ['Read', ['file_path' => 'view/P001.jpg']], ['Read', ['file_path' => "{$dir}/sheets/sheet_01.jpg"]],
            ['Glob', ['pattern' => 'view/*.jpg']], ['Write', ['file_path' => 'result.json']],
            ['Edit', ['file_path' => "{$dir}/result.json"]],
            ['Bash', ['command' => "{$py} tools/v2_tools.py show . P007"]],
            ['Bash', ['command' => "{$py} tools/v2_tools.py contours . P007 --level 0.4"]],
            ['Bash', ['command' => "{$py} tools/v2_tools.py zoom . P007 420 610"]],
            ['Bash', ['command' => "{$py} tools/v2_tools.py validate ."]],
            ['Bash', ['command' => 'cat manifest.json && ls sheets view']],
            ['Bash', ['command' => 'cat manifest.json | head -c 3000 && ls sheets view']],
        ];
        foreach ($ok as [$tool, $in]) {
            $this->assertNull($runner->toolViolation($dir, $tool, $in), json_encode([$tool, $in]));
        }
        $bad = [
            ['Read', ['file_path' => '../6/result.json']], ['Read', ['file_path' => "{$dir}/../../../../.env"]],
            ['Read', ['file_path' => '/etc/passwd']], ['Glob', ['pattern' => '../**/result.json']],
            ['Glob', ['pattern' => '*.json', 'path' => '/var/www']],
            ['Write', ['file_path' => 'tools/v2_tools.py']], ['Edit', ['file_path' => 'manifest.json']],
            ['Bash', ['command' => "{$py} tools/v2_tools.py show ../6 P007"]],
            ['Bash', ['command' => "{$py} tools/v2_tools.py validate . ; cat /etc/passwd"]],
            ['Bash', ['command' => "{$py} tools/v2_tools.py finalize ."]],
            ['Bash', ['command' => 'ls ..']], ['Bash', ['command' => 'cat /var/www/html/app/.env']],
            ['Bash', ['command' => 'cat manifest.json && python3 -c "print(1)"']], ['Bash', ['command' => 'cat ~/.claude/x']],
            ['Grep', ['pattern' => 'IDC']], ['WebFetch', ['url' => 'x']],
        ];
        foreach ($bad as [$tool, $in]) {
            $this->assertNotNull($runner->toolViolation($dir, $tool, $in), json_encode([$tool, $in]));
        }
    }

    public function test_a_changed_helper_or_a_recorded_violation_fails_the_integrity_check(): void
    {
        $runner = app(\App\Services\V2DiagnoseRunner::class);
        $run = V2Diagnosis::create(['organ' => 'Breast', 'status' => 'analysing']);
        $dir = $runner->runDir($run);
        File::ensureDirectoryExists("{$dir}/tools");
        file_put_contents("{$dir}/tools/v2_tools.py", 'print(1)');
        file_put_contents("{$dir}/tools.sha256", hash('sha256', 'print(1)'));
        file_put_contents("{$dir}/tool_calls.jsonl", json_encode(['tool' => 'Read', 'violation' => null]) . "\n");
        $this->assertSame([], $runner->integrityViolations($run));

        file_put_contents("{$dir}/tool_calls.jsonl", json_encode(['tool' => 'Read', 'violation' => 'read outside']) . "\n", FILE_APPEND);
        $this->assertSame(['read outside'], $runner->integrityViolations($run));

        file_put_contents("{$dir}/tools/v2_tools.py", 'import os');
        $this->assertCount(2, $runner->integrityViolations($run));
    }

    public function test_a_refused_call_voids_the_run_only_when_it_reached_outside_the_folder(): void
    {
        $runner = app(\App\Services\V2DiagnoseRunner::class);
        $run = V2Diagnosis::create(['organ' => 'Breast', 'status' => 'analysing']);
        $dir = $runner->runDir($run);
        File::ensureDirectoryExists("{$dir}/tools");
        file_put_contents("{$dir}/tools/v2_tools.py", 'x');
        file_put_contents("{$dir}/tools.sha256", hash('sha256', 'x'));
        $py = $runner->pythonBin();
        $call = function (string $id, string $cmd) use ($runner, $dir) {
            $in = ['command' => $cmd];
            $v = $runner->toolViolation($dir, 'Bash', $in);
            return json_encode(['id' => $id, 'tool' => 'Bash', 'input' => $in, 'violation' => $v,
                                'outside' => $v !== null && $runner->reachesOutside($dir, 'Bash', $in)]) . "\n";
        };
        $log = fn (string ...$lines) => file_put_contents("{$dir}/tool_calls.jsonl", implode('', $lines));

        // Helpers chained with && are allowed (run #29).
        $this->assertNull($runner->toolViolation($dir, 'Bash',
            ['command' => "{$py} tools/v2_tools.py zoom . P030 350 300 && {$py} tools/v2_tools.py zoom . P016 450 550"]));
        $this->assertNotNull($runner->toolViolation($dir, 'Bash',
            ['command' => "{$py} tools/v2_tools.py zoom . P030 350 300 && cat /etc/passwd"]));

        // Refused, inside the folder (run #30's `ls && python -c` on manifest.json): no effect, run stands.
        $inside = "ls && {$py} -c \"import json;m=json.load(open('manifest.json'))\"";
        $log($call('a', $inside), json_encode(['denied' => 'a']) . "\n");
        $this->assertSame([], $runner->integrityViolations($run));

        // The same command, not refused: it ran, the run is void.
        $log($call('a', $inside));
        $this->assertCount(1, $runner->integrityViolations($run));

        // Refused, but aimed outside the folder: void all the same.
        foreach (['cat ../../../../.env', 'cat /etc/passwd', 'ls ~', 'cat $HOME/x',
                  "{$py} -c \"open('/var/www/html/app/.env')\""] as $cmd) {
            $log($call('b', $cmd), json_encode(['denied' => 'b']) . "\n");
            $this->assertCount(1, $runner->integrityViolations($run), $cmd);
        }
    }

    public function test_case_details_that_name_the_slide_or_its_diagnosis_are_refused(): void
    {
        $runner = app(\App\Services\V2DiagnoseRunner::class);
        $cat = \Illuminate\Support\Facades\DB::table('categories')->insertGetId(['label_en' => 'tumor']);
        $sub = \Illuminate\Support\Facades\DB::table('disease_subtypes')->insertGetId(['category_id' => $cat, 'name' => 'ILC']);
        $s = Sample::forceCreate(['entity_submitter_id' => 'TCGA-AC-A2FO', 'file_name' => 'TCGA-AC-A2FO-01Z-00-DX1.svs',
                                  'category_id' => $cat, 'disease_subtype_id' => $sub]);
        $mk = fn (?string $notes) => V2Diagnosis::create(['sample_id' => $s->id, 'organ' => 'Breast',
                                                          'clinical_notes' => $notes, 'status' => 'queued']);

        $this->assertSame([], $runner->blindingLeaks($mk(null)));
        $this->assertSame([], $runner->blindingLeaks($mk('mass 2.3 cm, core biopsy, oncology review')));
        $this->assertContains('ILC', $runner->blindingLeaks($mk('known ILC, re-excision')));
        $this->assertContains('BRACS', $runner->blindingLeaks($mk('from the BRACS set')));
        $this->assertNotEmpty($runner->blindingLeaks($mk('slide TCGA-AC-A2FO-01Z')));

        (new RunV2Diagnosis($mk('ILC')->id))->handle($runner);
        $this->assertSame('failed', V2Diagnosis::latest('id')->first()->status);
    }

    public function test_the_evaluation_batch_takes_only_unseen_slides_and_runs_them_blind(): void
    {
        Queue::fake();
        $db = \Illuminate\Support\Facades\DB::table(...);
        $breast = $db('organs')->insertGetId(['name' => 'Breast']);
        $he = $db('stains')->insertGetId(['name' => 'H&E']);
        $tumour = $db('categories')->insertGetId(['label_en' => 'tumor']);
        $ilc = $db('disease_subtypes')->insertGetId(['category_id' => $tumour, 'name' => 'ILC']);
        $mk = fn (string $id, int $organ = 0) => Sample::forceCreate(['entity_submitter_id' => $id,
            'organ_id' => $organ ?: $breast, 'stain_id' => $he, 'category_id' => $tumour, 'disease_subtype_id' => $ilc,
            'storage_status' => 'available', 'wsi_remote_path' => "s/{$id}.svs"]);
        $seen = $mk('SEEN');
        V2Diagnosis::create(['sample_id' => $seen->id, 'organ' => 'Breast', 'status' => 'completed']);
        $tuning = $mk('TUNING');
        config(['v2_diagnose.tuning_samples' => [$tuning->id]]);
        $fresh = [$mk('NEW1'), $mk('NEW2'), $mk('NEW3')];
        $mk('LUNG', $db('organs')->insertGetId(['name' => 'Lung']));

        $this->artisan('v2:evaluate', ['--queue' => true, '--per-class' => 2, '--classes' => 'ILC', '--seed' => 7])
             ->assertSuccessful();

        $runs = V2Diagnosis::where('status', 'queued')->get();
        $this->assertCount(2, $runs);
        $this->assertEmpty(array_diff($runs->pluck('sample_id')->all(), array_map(fn ($s) => $s->id, $fresh)),
            'only unseen, non-tuning breast slides are picked');
        foreach ($runs as $r) {
            $this->assertSame(['Breast', 'H&E', null, null, null, null],
                [$r->organ, $r->stain, $r->age, $r->sex, $r->race, $r->clinical_notes], 'run blind: organ and stain only');
        }
        Queue::assertPushed(RunV2Diagnosis::class, 2);

        // The same seed picks the same slides.
        V2Diagnosis::where('status', 'queued')->delete();
        $this->artisan('v2:evaluate', ['--queue' => true, '--per-class' => 2, '--classes' => 'ILC', '--seed' => 7]);
        $this->assertEqualsCanonicalizing($runs->pluck('sample_id')->all(),
            V2Diagnosis::where('status', 'queued')->pluck('sample_id')->all());
    }

    public function test_the_report_counts_tuning_slides_and_void_runs_apart(): void
    {
        $runner = app(\App\Services\V2DiagnoseRunner::class);
        $db = \Illuminate\Support\Facades\DB::table(...);
        $tumour = $db('categories')->insertGetId(['label_en' => 'tumor']);
        $ilc = $db('disease_subtypes')->insertGetId(['category_id' => $tumour, 'name' => 'ILC']);
        $run = function (string $code = null, string $error = null) use ($tumour, $ilc, $runner) {
            $s = Sample::forceCreate(['entity_submitter_id' => 'S', 'category_id' => $tumour, 'disease_subtype_id' => $ilc]);
            $r = V2Diagnosis::create(['sample_id' => $s->id, 'organ' => 'Breast', 'diagnosis_code' => $code,
                'status' => $error ? 'failed' : 'completed', 'error' => $error]);
            File::ensureDirectoryExists($runner->runDir($r));
            file_put_contents($runner->runDir($r) . '/prompt_version', $runner->promptVersion());
            return $r;
        };
        $run('ILC');
        $run('IDC');
        $run(null, 'Integrity check failed, result discarded: read outside the run folder');
        config(['v2_diagnose.tuning_samples' => [$run('ILC')->sample_id]]);

        $this->artisan('v2:evaluate')
             ->expectsOutputToContain('held-out ILC     correct 1, wrong 1, VOID 1')
             ->expectsOutputToContain('held-out ALL     correct 1, wrong 1, VOID 1 — 50% correct of 2 scored')
             ->expectsOutputToContain('tuning   ILC     correct 1')
             ->assertSuccessful();
    }
}
