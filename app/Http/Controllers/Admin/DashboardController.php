<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSampleUpload;
use App\Models\Sample;
use App\Models\Organ;
use App\Models\DataSource;
use App\Models\Category;
use App\Models\DiseaseSubtype;
use App\Services\GoogleDriveService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard');
    }

    public function samples(Request $request): View
    {
        $query = Sample::with(['organ', 'dataSource', 'patientCase', 'category'])
            ->orderByDesc('created_at');

        // Filters
        if ($request->filled('organ_id')) {
            $query->where('organ_id', $request->organ_id);
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('storage_status')) {
            $query->where('storage_status', $request->storage_status);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('file_name', 'like', '%' . $request->search . '%')
                  ->orWhere('file_id', 'like', '%' . $request->search . '%')
                  ->orWhere('entity_submitter_id', 'like', '%' . $request->search . '%');
            });
        }

        $samples     = $query->paginate(20)->withQueryString();
        $organs      = Organ::where('is_active', true)->orderBy('name')->get();
        $categories  = Category::where('is_active', true)->orderBy('id')->get();
        $dataSources = DataSource::where('is_active', true)->orderBy('name')->get();

        // Group disease subtypes by category_id for JS-driven dropdown
        $diseaseSubtypesByCategory = DiseaseSubtype::orderBy('name')
            ->get(['id', 'category_id', 'name'])
            ->groupBy('category_id')
            ->map(fn ($group) => $group->values());

        $stats = [
            'total'           => Sample::count(),
            'available'       => Sample::where('storage_status', 'available')->count(),
            'not_downloaded'  => Sample::where('storage_status', 'not_downloaded')->count(),
            'tiling_done'     => Sample::where('tiling_status', 'done')->count(),
        ];

        return view('admin.samples', compact('samples', 'organs', 'categories', 'dataSources', 'diseaseSubtypesByCategory', 'stats'));
    }

    public function storeSample(Request $request): RedirectResponse
    {
        $uploadMethod = $request->input('upload_method', 'upload');

        // ── Validation ───────────────────────────────────────────────────────
        $rules = [
            'upload_method'        => ['required', 'in:upload,gdrive,bulk'],
            'organ_id'             => ['required', 'exists:organs,id'],
            'data_source_id'       => ['nullable', 'exists:data_sources,id'],
            'category_id'          => ['nullable', 'exists:categories,id'],
            'disease_subtype_id'   => ['nullable', 'exists:disease_subtypes,id'],
            'training_phase'       => ['nullable', 'integer', 'min:1', 'max:3'],
        ];

        // Method-specific validation
        if ($uploadMethod === 'upload') {
            if (!$request->hasFile('sample_file') || !$request->file('sample_file')) {
                return back()
                    ->withErrors(['sample_file' => 'Please select a file to upload. (Upload File tab)'])
                    ->withInput();
            }
            $rules['sample_file'] = ['required', 'file', 'max:5242880'];  // 5GB

        } elseif ($uploadMethod === 'gdrive') {
            if (!$request->filled('gdrive_link')) {
                return back()
                    ->withErrors(['gdrive_link' => 'Please provide a Google Drive sharing link. (Google Drive Link tab)'])
                    ->withInput();
            }
            $rules['gdrive_link'] = ['required', 'url', 'max:500'];

        } elseif ($uploadMethod === 'bulk') {
            if (!$request->filled('bulk_folder_path')) {
                return back()
                    ->withErrors(['bulk_folder_path' => 'Please enter the folder path. (Bulk Folder tab)'])
                    ->withInput();
            }
            $rules['bulk_folder_path'] = ['required', 'string', 'max:1000'];
        }

        $validated = $request->validate($rules, [
            'sample_file.required' => 'Please select a file to upload.',
            'sample_file.file' => 'The selected file must be a valid file.',
            'sample_file.max' => 'File size exceeds 5GB limit.',
            'gdrive_link.required' => 'Please provide a Google Drive link.',
            'gdrive_link.url' => 'Please provide a valid URL.',
            'bulk_folder.required' => 'Please select a folder to upload.',
            'organ_id.required' => 'Please select an Organ.',
        ]);

        // ── Resolve upload info ──────────────────────────────────────────────
        $tempFilePath    = null;
        $gdriveFileId    = null;
        $gdriveFileName  = null;
        $bulkFolderPath  = null;
        $bulkFolderName  = null;
        $initialName     = 'Processing…';
        $initialSize     = null;  // captured early to survive job failure

        if ($uploadMethod === 'upload' && $request->hasFile('sample_file')) {
            $file        = $request->file('sample_file');
            $initialName = $file->getClientOriginalName();
            $initialSize = $file->getSize() ?: null;  // bytes, known immediately

            // Save to temp folder — deleted by the job after Drive upload
            Storage::makeDirectory('temp');
            $tempName     = uniqid('sample_', true) . '.' . $file->getClientOriginalExtension();
            $file->move(storage_path('app/temp'), $tempName);
            $tempFilePath = storage_path('app/temp/' . $tempName);

        } elseif ($uploadMethod === 'gdrive') {
            /** @var GoogleDriveService $drive */
            $drive        = app(GoogleDriveService::class);
            $gdriveFileId = $drive->extractFileIdFromUrl($validated['gdrive_link']);

            if (!$gdriveFileId) {
                return back()
                    ->withErrors(['gdrive_link' => 'Invalid Google Drive link — could not extract a file ID.'])
                    ->withInput();
            }

            // Resolve filename and size from Drive upfront (best-effort)
            $fileInfo       = $drive->getFileInfoFromDriveId($gdriveFileId);
            $gdriveFileName = $fileInfo['name'];
            $initialName    = $gdriveFileName ?? 'Drive file: ' . $gdriveFileId;
            $initialSize    = $fileInfo['size'];

        } elseif ($uploadMethod === 'bulk') {
            // ── Bulk: one sample record per UUID sub-folder ──────────────────
            // Strip ALL control characters (0x00–0x1F, 0x7F) to handle pasted paths
            // that may contain embedded newlines, tabs, or other invisible bytes.
            $rawInput   = $request->input('bulk_folder_path', '');
            $folderPath = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', $rawInput));

            if (empty($folderPath)) {
                return back()
                    ->withErrors(['bulk_folder_path' => 'Please enter the folder path.'])
                    ->withInput();
            }

            // Normalize to the native OS separator
            $folderPathNative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $folderPath);

            // Resolve to an absolute canonical path (also validates existence)
            $realPath = realpath($folderPathNative);
            if ($realPath === false || !is_dir($realPath)) {
                return back()
                    ->withErrors(['bulk_folder_path' => 'Folder not found: "' . $folderPathNative . '". Please check the path and try again.'])
                    ->withInput();
            }
            $folderPathNative = $realPath;

            $wsiExtensions = ['svs', 'tiff', 'tif', 'ndpi', 'scn', 'mrxs', 'vsi'];

            // Resolve source/category labels for Slide ID generation
            $sourceName    = isset($validated['data_source_id'])
                ? (DataSource::find($validated['data_source_id'])?->name ?? '')
                : '';
            $categoryLabel = isset($validated['category_id'])
                ? (Category::find($validated['category_id'])?->label_en ?? '')
                : '';
            $subtypeName   = isset($validated['disease_subtype_id'])
                ? (DiseaseSubtype::find($validated['disease_subtype_id'])?->name ?? '')
                : '';
            $tissueName    = ($sourceName || $categoryLabel)
                ? '/' . implode('/', array_filter([$sourceName, $categoryLabel])) . '/'
                : null;

            // Enumerate immediate sub-folders — each represents one independent slide.
            // Use scandir() instead of glob() to avoid glob interpreting \n/\t in paths.
            $subFolderPaths = [];
            foreach (scandir($folderPathNative) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $fullEntry = $folderPathNative . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($fullEntry)) {
                    $subFolderPaths[] = $fullEntry;
                }
            }

            $samplesQueued = 0;
            $skipped       = 0;

            if (!empty($subFolderPaths)) {
                // ── Multi-sample mode: each sub-folder = one TCGA slide ──────
                foreach ($subFolderPaths as $subFolderPath) {
                    $slideFolderName = basename($subFolderPath);

                    // Find the WSI file directly inside this sub-folder (skip logs/)
                    $wsiFile = null;
                    foreach (new \DirectoryIterator($subFolderPath) as $fi) {
                        if ($fi->isFile() && in_array(strtolower($fi->getExtension()), $wsiExtensions)) {
                            $wsiFile = $fi->getFilename();
                            break;
                        }
                    }

                    if (!$wsiFile) {
                        $skipped++;
                        \Illuminate\Support\Facades\Log::warning("[DashboardController] ⏭ Skipped sub-folder '{$slideFolderName}' — no WSI file at root.");
                        continue;
                    }

                    // Slide ID = {source}-{category}-{actual_uuid_folder_name}
                    $entitySubmitterId = implode('-', array_filter([$sourceName, $categoryLabel, $subtypeName, $slideFolderName]));

                    $sample = Sample::create([
                        'organ_id'            => $validated['organ_id'],
                        'data_source_id'      => $validated['data_source_id'] ?? null,
                        'category_id'         => $validated['category_id'] ?? null,
                        'disease_subtype'     => $subtypeName ?: null,
                        'tissue_name'         => $tissueName ? $tissueName . $entitySubmitterId . '/' : null,
                        'training_phase'      => $validated['training_phase'] ?? null,
                        'entity_submitter_id' => $entitySubmitterId,
                        'file_name'           => $wsiFile,
                        'data_format'         => strtoupper(pathinfo($wsiFile, PATHINFO_EXTENSION)) ?: 'SVS',
                        'storage_status'      => 'downloading',
                        'is_usable'           => true,
                    ]);

                    ProcessSampleUpload::dispatch(
                        $sample->id,
                        null,             // tempFilePath
                        null,             // gdriveFileId
                        null,             // gdriveFileName
                        $subFolderPath,   // bulkFolderPath = the UUID sub-folder
                        $slideFolderName, // bulkFolderName = UUID folder name
                    );

                    $samplesQueued++;
                    \Illuminate\Support\Facades\Log::info("[DashboardController] Sample #{$sample->id} queued — slide: {$slideFolderName}, file: {$wsiFile}");
                }
            } else {
                // ── Single-folder mode: the folder itself is the slide ───────
                $wsiFile = null;
                foreach (new \DirectoryIterator($folderPathNative) as $fi) {
                    if ($fi->isFile() && in_array(strtolower($fi->getExtension()), $wsiExtensions)) {
                        $wsiFile = $fi->getFilename();
                        break;
                    }
                }

                if (!$wsiFile) {
                    return back()
                        ->withErrors(['bulk_folder_path' => 'No WSI files (.svs, .tiff, .tif, etc.) found in "' . basename($folderPathNative) . '". Ensure WSI files are present at the root of the folder.'])
                        ->withInput();
                }

                $slideFolderName   = basename($folderPathNative);
                $entitySubmitterId = implode('-', array_filter([$sourceName, $categoryLabel, $subtypeName, $slideFolderName]));

                $sample = Sample::create([
                    'organ_id'            => $validated['organ_id'],
                    'data_source_id'      => $validated['data_source_id'] ?? null,
                    'category_id'         => $validated['category_id'] ?? null,
                    'disease_subtype'     => $subtypeName ?: null,
                    'tissue_name'         => $tissueName ? $tissueName . $entitySubmitterId . '/' : null,
                    'training_phase'      => $validated['training_phase'] ?? null,
                    'entity_submitter_id' => $entitySubmitterId,
                    'file_name'           => $wsiFile,
                    'data_format'         => strtoupper(pathinfo($wsiFile, PATHINFO_EXTENSION)) ?: 'SVS',
                    'storage_status'      => 'downloading',
                    'is_usable'           => true,
                ]);

                ProcessSampleUpload::dispatch(
                    $sample->id,
                    null,
                    null,
                    null,
                    $folderPathNative,
                    $slideFolderName,
                );

                $samplesQueued = 1;
                \Illuminate\Support\Facades\Log::info("[DashboardController] Sample #{$sample->id} queued — slide: {$slideFolderName}, file: {$wsiFile}");
            }

            if ($samplesQueued === 0) {
                return back()
                    ->withErrors(['bulk_folder_path' => 'No WSI files found at the root of any sub-folder inside "' . basename($folderPathNative) . '". Each UUID folder must contain a .svs (or similar) file directly at its root.'])
                    ->withInput();
            }

            $suffix = $skipped > 0 ? " ({$skipped} sub-folder(s) had no WSI file and were skipped)" : '';
            return redirect()->route('admin.samples')
                ->with('success', "{$samplesQueued} slide(s) have been queued for upload from '" . basename($folderPathNative) . "'{$suffix}.");
        }

        // ── Auto-generate tissue_name & entity_submitter_id ─────────────────
        $sourceName    = isset($validated['data_source_id'])
            ? (DataSource::find($validated['data_source_id'])?->name ?? '')
            : '';
        $categoryLabel = isset($validated['category_id'])
            ? (Category::find($validated['category_id'])?->label_en ?? '')
            : '';
        $subtypeName   = isset($validated['disease_subtype_id'])
            ? (DiseaseSubtype::find($validated['disease_subtype_id'])?->name ?? '')
            : '';

        $uuid              = (string) \Illuminate\Support\Str::uuid();
        $entitySubmitterId = implode('-', array_filter([$sourceName, $categoryLabel, $subtypeName, $uuid]));
        $tissueName        = ($sourceName || $categoryLabel || $subtypeName)
            ? '/' . implode('/', array_filter([$sourceName, $categoryLabel])) . '/' . $entitySubmitterId . '/'
            : null;

        // ── Create sample record ─────────────────────────────────────────────
        $sample = Sample::create([
            'organ_id'            => $validated['organ_id'],
            'data_source_id'      => $validated['data_source_id'] ?? null,
            'category_id'         => $validated['category_id'] ?? null,
            'disease_subtype'     => $subtypeName ?: null,
            'tissue_name'         => $tissueName,
            'training_phase'      => $validated['training_phase'] ?? null,
            'entity_submitter_id' => $entitySubmitterId ?: null,
            'file_name'           => $initialName,
            'data_format'         => strtoupper(pathinfo($initialName, PATHINFO_EXTENSION)) ?: 'UNKNOWN',
            'file_size_bytes'     => $initialSize,
            'file_size_gb'        => $initialSize ? round($initialSize / 1_073_741_824, 3) : null,
            'storage_status'      => 'downloading',   // will become 'available' after job
            'gdrive_source_id'    => $gdriveFileId,   // stored for retry capability
            'is_usable'           => true,
        ]);

        // ── Dispatch background job ──────────────────────────────────────────
        try {
            ProcessSampleUpload::dispatch(
                $sample->id,
                $tempFilePath,
                $gdriveFileId,
                $gdriveFileName,
                $bulkFolderPath,
                $bulkFolderName,
            );

            \Illuminate\Support\Facades\Log::info("[DashboardController] Sample #{$sample->id} queued for {$uploadMethod} upload: {$initialName}");
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("[DashboardController] Failed to queue sample #{$sample->id}: " . $e->getMessage());
            throw $e;
        }

        return redirect()->route('admin.samples')
            ->with('success', 'Sample "' . $initialName . '" has been queued — it will appear as Available on Drive once the transfer completes.');
    }

    public function showSample(Sample $sample): View
    {
        $sample->load(['organ', 'dataSource', 'category', 'patientCase']);
        return view('admin.sample-show', compact('sample'));
    }

    public function editSample(Sample $sample): View
    {
        $sample->load(['organ', 'dataSource', 'category']);
        $organs      = Organ::where('is_active', true)->orderBy('name')->get();
        $dataSources = DataSource::where('is_active', true)->orderBy('name')->get();
        $categories  = Category::where('is_active', true)->orderBy('id')->get();
        $diseaseSubtypesByCategory = DiseaseSubtype::orderBy('name')
            ->get(['id', 'category_id', 'name'])
            ->groupBy('category_id')
            ->map(fn ($g) => $g->values());

        return view('admin.sample-edit', compact('sample', 'organs', 'dataSources', 'categories', 'diseaseSubtypesByCategory'));
    }

    public function updateSample(Request $request, Sample $sample): RedirectResponse
    {
        $request->validate([
            'organ_id'               => 'required|exists:organs,id',
            'data_source_id'         => 'nullable|exists:data_sources,id',
            'category_id'            => 'nullable|exists:categories,id',
            'disease_subtype'        => 'nullable|string|max:200',
            'entity_submitter_id'    => 'nullable|string|max:300',
            'file_name'              => 'nullable|string|max:500',
            'data_format'            => 'nullable|string|max:50',
            'training_phase'         => 'nullable|integer|min:1|max:3',
            'storage_status'         => 'required|in:not_downloaded,downloading,verifying,available,corrupted,missing',
            'quality_status'         => 'nullable|in:pending,passed,rejected',
            'quality_rejection_reason' => 'nullable|string|max:500',
            'is_usable'              => 'boolean',
            'notes'                  => 'nullable|string',
        ]);

        $sample->update([
            'organ_id'                 => $request->organ_id,
            'data_source_id'           => $request->data_source_id,
            'category_id'              => $request->category_id,
            'disease_subtype'          => $request->disease_subtype,
            'entity_submitter_id'      => $request->entity_submitter_id,
            'file_name'                => $request->file_name,
            'data_format'              => $request->data_format,
            'training_phase'           => $request->training_phase,
            'storage_status'           => $request->storage_status,
            'quality_status'           => $request->quality_status,
            'quality_rejection_reason' => $request->quality_rejection_reason,
            'is_usable'                => $request->boolean('is_usable'),
        ]);

        return redirect()->route('admin.samples.show', $sample)
            ->with('success', 'Sample updated successfully.');
    }

    public function destroySample(Sample $sample): RedirectResponse
    {
        $sample->delete();

        return redirect()->route('admin.samples')
            ->with('success', 'Sample #' . $sample->id . ' deleted.');
    }

    public function retrySample(Sample $sample): RedirectResponse
    {
        // Only retry samples that failed (corrupted) and were uploaded via Google Drive
        if ($sample->storage_status !== 'corrupted') {
            return back()->with('error', 'Only samples with status "corrupted" can be retried.');
        }

        if (!$sample->gdrive_source_id) {
            return back()->with('error', 'Cannot retry: no Google Drive source ID stored for this sample. Please re-upload manually.');
        }

        // Resolve original filename from Drive (best-effort)
        /** @var \App\Services\GoogleDriveService $drive */
        $drive          = app(\App\Services\GoogleDriveService::class);
        $gdriveFileName = $drive->getFileNameFromDriveId($sample->gdrive_source_id);

        $sample->update(['storage_status' => 'downloading']);

        ProcessSampleUpload::dispatch(
            $sample->id,
            null,
            $sample->gdrive_source_id,
            $gdriveFileName,
        );

        return redirect()->route('admin.samples.show', $sample)
            ->with('success', 'Upload retry started for sample #' . $sample->id . '.');
    }

    public function workflow(): View
    {
        return view('admin.workflow');
    }

    public function output(): View
    {
        return view('admin.output');
    }
}
