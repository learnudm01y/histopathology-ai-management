<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiModelsController extends Controller
{
    private const MODEL_TYPES = [
        'foundation'     => 'Foundation',
        'classification' => 'Classification',
        'segmentation'   => 'Segmentation',
        'detection'      => 'Detection',
        'multimodal'     => 'Multimodal',
        'other'          => 'Other',
    ];

    private const LEVELS = [
        'patch'  => 'Patch / Tile-level',
        'slide'  => 'Slide-level (WSI)',
        'region' => 'Region-level',
        'other'  => 'Other',
    ];

    public function index(): View
    {
        $models = AiModel::orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('admin.settings.ai-models.index', [
            'models'     => $models,
            'modelTypes' => self::MODEL_TYPES,
            'levels'     => self::LEVELS,
        ]);
    }

    public function create(): View
    {
        return view('admin.settings.ai-models.create', [
            'modelTypes' => self::MODEL_TYPES,
            'levels'     => self::LEVELS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateData($request);

        $isDefault = $request->boolean('is_default');
        if ($isDefault) {
            AiModel::where('is_default', true)->update(['is_default' => false]);
        }

        AiModel::create([
            ...$validated,
            'is_active'  => $request->boolean('is_active'),
            'is_default' => $isDefault,
        ]);

        return redirect()->route('admin.settings.ai-models.index')
            ->with('success', 'AI model "' . $validated['name'] . '" added.');
    }

    public function edit(AiModel $aiModel): View
    {
        return view('admin.settings.ai-models.edit', [
            'model'      => $aiModel,
            'modelTypes' => self::MODEL_TYPES,
            'levels'     => self::LEVELS,
        ]);
    }

    public function update(Request $request, AiModel $aiModel): RedirectResponse
    {
        $validated = $this->validateData($request, $aiModel->id);

        $isDefault = $request->boolean('is_default');
        if ($isDefault) {
            AiModel::where('id', '!=', $aiModel->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $aiModel->update([
            ...$validated,
            'is_active'  => $request->boolean('is_active'),
            'is_default' => $isDefault,
        ]);

        return redirect()->route('admin.settings.ai-models.index')
            ->with('success', 'AI model "' . $aiModel->name . '" updated.');
    }

    public function destroy(AiModel $aiModel): RedirectResponse
    {
        $aiModel->delete();

        return redirect()->route('admin.settings.ai-models.index')
            ->with('success', 'AI model deleted.');
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $unique = 'unique:ai_models,name' . ($ignoreId ? ',' . $ignoreId : '');

        return $request->validate([
            'name'             => 'required|string|max:100|' . $unique,
            'full_name'        => 'nullable|string|max:200',
            'provider'         => 'nullable|string|max:100',
            'version'          => 'nullable|string|max:50',
            'model_type'       => 'required|in:' . implode(',', array_keys(self::MODEL_TYPES)),
            'level'            => 'required|in:' . implode(',', array_keys(self::LEVELS)),
            'huggingface_url'  => 'nullable|url|max:500',
            'paper_url'        => 'nullable|url|max:500',
            'repo_url'         => 'nullable|url|max:500',
            'input_resolution' => 'nullable|string|max:50',
            'embedding_dim'    => 'nullable|string|max:30',
            'parameters'       => 'nullable|string|max:30',
            'license'          => 'nullable|string|max:100',
            'description'      => 'nullable|string',
            'notes'            => 'nullable|string',
        ]);
    }
}
