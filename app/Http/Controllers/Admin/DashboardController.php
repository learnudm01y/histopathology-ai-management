<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSampleUpload;
use App\Models\Sample;
use App\Models\Organ;
use App\Models\PatientCase;
use App\Models\DataSource;
use App\Models\Category;
use App\Models\DiseaseSubtype;
use App\Models\Stain;
use App\Services\CaseLinker;
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
        $sampleStats = [
            'total'           => Sample::count(),
            'available'       => Sample::where('storage_status', 'available')->count(),
            'not_downloaded'  => Sample::where('storage_status', 'not_downloaded')->count(),
            'downloading'     => Sample::where('storage_status', 'downloading')->count(),
            'corrupted'       => Sample::where('storage_status', 'corrupted')->count(),
            'missing'         => Sample::where('storage_status', 'missing')->count(),
            'tiling_done'     => Sample::where('tiling_status', 'done')->count(),
            'tiling_failed'   => Sample::where('tiling_status', 'failed')->count(),
            'tiling_pending'  => Sample::where('tiling_status', 'pending')->count(),
            'quality_passed'  => Sample::where('quality_status', 'passed')->count(),
            'quality_rejected'=> Sample::where('quality_status', 'rejected')->count(),
            'quality_review'  => Sample::where('quality_status', 'needs_review')->count(),
            'quality_pending' => Sample::where('quality_status', 'pending')->count(),
            'not_usable'      => Sample::where('is_usable', false)->count(),
        ];

        $caseStats = [
            'total'         => \App\Models\PatientCase::count(),
            'with_clinical' => \App\Models\PatientCase::whereHas('clinicalInfo')->count(),
            'with_slides'   => \App\Models\PatientCase::has('samples')->count(),
            'fully_linked'  => \App\Models\PatientCase::has('samples')->whereHas('clinicalInfo')->count(),
        ];

        // Slide verification stats
        $verifStats = [
            'failed'  => \App\Models\SlideVerification::where('verification_status', 'failed')->count(),
            'passed'  => \App\Models\SlideVerification::where('verification_status', 'passed')->count(),
            'pending' => \App\Models\SlideVerification::where('verification_status', 'pending')->count(),
            'total'   => \App\Models\SlideVerification::count(),
        ];

        $failedVerifications = \App\Models\SlideVerification::where('verification_status', 'failed')
            ->with('sample:id,file_name,storage_status')
            ->select('id', 'sample_id', 'notes', 'verified_at', 'verification_status')
            ->orderByDesc('verified_at')
            ->get();

        $rejectedSamples = Sample::where('quality_status', 'rejected')
            ->select('id', 'file_name', 'quality_rejection_reason', 'storage_status')
            ->orderByDesc('id')
            ->get();

        return view('admin.dashboard', compact('sampleStats', 'caseStats', 'verifStats', 'failedVerifications', 'rejectedSamples'));
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
        $stains      = Stain::where('is_active', true)->orderBy('stain_type')->orderBy('name')->get();

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

        return view('admin.samples', compact('samples', 'organs', 'categories', 'dataSources', 'stains', 'diseaseSubtypesByCategory', 'stats'));
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
            'stain_id'             => ['nullable', 'exists:stains,id'],
            'stain_marker'         => ['nullable', 'string', 'max:100'],
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
            $queuedItems   = [];
            $skippedItems  = [];

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
                        $skippedItems[] = [
                            'name'   => $slideFolderName,
                            'reason' => 'No WSI file found at folder root',
                        ];
                        \Illuminate\Support\Facades\Log::warning("[DashboardController] ⏭ Skipped sub-folder '{$slideFolderName}' — no WSI file at root.");
                        continue;
                    }

                    // If folder name is a GDC UUID, store it as file_id for clinical linking.
                    $gdcFileUuid = $this->extractGdcUuid($slideFolderName);

                    // Extract TCGA entity submitter from the WSI filename (e.g. TCGA-BH-A203-11A-04-TSD)
                    // rather than constructing a synthetic ID from the folder path.
                    $entitySubmitterId = $this->extractTcgaSubmitterId($wsiFile)
                        ?? implode('-', array_filter([$sourceName, $categoryLabel, $subtypeName, $slideFolderName]));

                    // Duplicate check: skip slides already in the DB (by GDC file_id, then by file_name).
                    $samplePayload = [
                        'organ_id'            => $validated['organ_id'],
                        'data_source_id'      => $validated['data_source_id'] ?? null,
                        'category_id'         => $validated['category_id'] ?? null,
                        'disease_subtype'     => $subtypeName ?: null,
                        'tissue_name'         => $tissueName ? $tissueName . $entitySubmitterId . '/' : null,
                        'training_phase'      => $validated['training_phase'] ?? null,
                        'stain_id'            => $validated['stain_id'] ?? null,
                        'stain_marker'        => $validated['stain_marker'] ?? null,
                        'entity_submitter_id' => $entitySubmitterId,
                        'file_name'           => $wsiFile,
                        'data_format'         => strtoupper(pathinfo($wsiFile, PATHINFO_EXTENSION)) ?: 'SVS',
                        'storage_status'      => 'downloading',
                        'is_usable'           => true,
                    ];
                    if ($gdcFileUuid) {
                        $samplePayload['file_id'] = $gdcFileUuid;
                    }

                    $existing = $gdcFileUuid
                        ? Sample::where('file_id', $gdcFileUuid)->first()
                        : Sample::where('file_name', $wsiFile)->first();

                    if ($existing) {
                        $skippedItems[] = [
                            'name'   => $wsiFile,
                            'folder' => $slideFolderName,
                            'reason' => 'Already exists as Sample #' . $existing->id,
                        ];
                        \Illuminate\Support\Facades\Log::info("[DashboardController] ⏭ Duplicate skipped — slide: {$slideFolderName}, file: {$wsiFile}, existing Sample #{$existing->id}");
                        continue;
                    }

                    $sample = Sample::create($samplePayload);
                    $this->linkSampleToCase($sample);

                    ProcessSampleUpload::dispatch(
                        $sample->id,
                        null,             // tempFilePath
                        null,             // gdriveFileId
                        null,             // gdriveFileName
                        $subFolderPath,   // bulkFolderPath = the UUID sub-folder
                        $slideFolderName, // bulkFolderName = UUID folder name
                    );

                    $samplesQueued++;
                    $queuedItems[] = ['name' => $wsiFile, 'folder' => $slideFolderName];
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

                $slideFolderName = basename($folderPathNative);
                $gdcFileUuid     = $this->extractGdcUuid($slideFolderName);
                $entitySubmitterId = $this->extractTcgaSubmitterId($wsiFile)
                    ?? implode('-', array_filter([$sourceName, $categoryLabel, $subtypeName, $slideFolderName]));

                $singlePayload = [
                    'organ_id'            => $validated['organ_id'],
                    'data_source_id'      => $validated['data_source_id'] ?? null,
                    'category_id'         => $validated['category_id'] ?? null,
                    'disease_subtype'     => $subtypeName ?: null,
                    'tissue_name'         => $tissueName ? $tissueName . $entitySubmitterId . '/' : null,
                    'training_phase'      => $validated['training_phase'] ?? null,
                    'stain_id'            => $validated['stain_id'] ?? null,
                    'stain_marker'        => $validated['stain_marker'] ?? null,
                    'entity_submitter_id' => $entitySubmitterId,
                    'file_name'           => $wsiFile,
                    'data_format'         => strtoupper(pathinfo($wsiFile, PATHINFO_EXTENSION)) ?: 'SVS',
                    'storage_status'      => 'downloading',
                    'is_usable'           => true,
                ];
                if ($gdcFileUuid) {
                    $singlePayload['file_id'] = $gdcFileUuid;
                }

                $existing = $gdcFileUuid
                    ? Sample::where('file_id', $gdcFileUuid)->first()
                    : Sample::where('file_name', $wsiFile)->first();

                if ($existing) {
                    $skippedItems[] = [
                        'name'   => $wsiFile,
                        'folder' => $slideFolderName,
                        'reason' => 'Already exists as Sample #' . $existing->id,
                    ];
                    \Illuminate\Support\Facades\Log::info("[DashboardController] ⏭ Duplicate skipped — slide: {$slideFolderName}, file: {$wsiFile}, existing Sample #{$existing->id}");
                } else {
                    $sample = Sample::create($singlePayload);
                    $this->linkSampleToCase($sample);

                    ProcessSampleUpload::dispatch(
                        $sample->id,
                        null,
                        null,
                        null,
                        $folderPathNative,
                        $slideFolderName,
                    );

                    $samplesQueued = 1;
                    $queuedItems[] = ['name' => $wsiFile, 'folder' => $slideFolderName];
                    \Illuminate\Support\Facades\Log::info("[DashboardController] Sample #{$sample->id} queued — slide: {$slideFolderName}, file: {$wsiFile}");
                }
            }

            if ($samplesQueued === 0 && empty($queuedItems)) {
                if (!empty($skippedItems)) {
                    return redirect()->route('admin.samples')
                        ->with('upload_report', [
                            'queued'  => [],
                            'skipped' => $skippedItems,
                        ]);
                }
                return back()
                    ->withErrors(['bulk_folder_path' => 'No WSI files found at the root of any sub-folder inside "' . basename($folderPathNative) . '". Each UUID folder must contain a .svs (or similar) file directly at its root.'])
                    ->withInput();
            }

            return redirect()->route('admin.samples')
                ->with('upload_report', [
                    'queued'  => $queuedItems,
                    'skipped' => $skippedItems,
                ]);
        }

        // ── Duplicate check for upload / gdrive methods ──────────────────────
        $duplicate = null;
        if ($uploadMethod === 'upload') {
            $duplicate = Sample::where('file_name', $initialName)->first();
        } elseif ($uploadMethod === 'gdrive' && $gdriveFileId) {
            $duplicate = Sample::where('file_id', $gdriveFileId)->first();
        }
        if ($duplicate) {
            if ($tempFilePath && file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }
            return redirect()->route('admin.samples')
                ->with('upload_report', [
                    'queued'  => [],
                    'skipped' => [['name' => $initialName, 'reason' => 'Already exists as Sample #' . $duplicate->id]],
                ]);
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
            'stain_id'            => $validated['stain_id'] ?? null,
            'stain_marker'        => $validated['stain_marker'] ?? null,
            'entity_submitter_id' => $entitySubmitterId ?: null,
            'file_name'           => $initialName,
            'data_format'         => strtoupper(pathinfo($initialName, PATHINFO_EXTENSION)) ?: 'UNKNOWN',
            'file_size_bytes'     => $initialSize,
            'file_size_gb'        => $initialSize ? round($initialSize / 1_073_741_824, 3) : null,
            'storage_status'      => 'downloading',   // will become 'available' after job
            'gdrive_source_id'    => $gdriveFileId,   // stored for retry capability
            'is_usable'           => true,
        ]);

        // Link to a clinical case immediately if the entity_submitter_id / file_name
        // matches a PatientCase.submitter_id (TCGA-BH-A203-…  →  TCGA-BH-A203).
        $this->linkSampleToCase($sample);

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
            ->with('upload_report', [
                'queued'  => [['name' => $initialName]],
                'skipped' => [],
            ]);
    }

    public function showSample(Sample $sample): View
    {
        $sample->load(['organ', 'dataSource', 'category', 'patientCase.clinicalInfo', 'stain', 'slideVerification']);
        return view('admin.sample-show', compact('sample'));
    }

    public function editSample(Sample $sample): View
    {
        $sample->load(['organ', 'dataSource', 'category', 'stain']);
        $organs      = Organ::where('is_active', true)->orderBy('name')->get();
        $dataSources = DataSource::where('is_active', true)->orderBy('name')->get();
        $categories  = Category::where('is_active', true)->orderBy('id')->get();
        $stains      = Stain::where('is_active', true)->orderBy('stain_type')->orderBy('name')->get();
        $diseaseSubtypesByCategory = DiseaseSubtype::orderBy('name')
            ->get(['id', 'category_id', 'name'])
            ->groupBy('category_id')
            ->map(fn ($g) => $g->values());

        return view('admin.sample-edit', compact('sample', 'organs', 'dataSources', 'categories', 'stains', 'diseaseSubtypesByCategory'));
    }

    public function updateSample(Request $request, Sample $sample): RedirectResponse
    {
        $request->validate([
            'organ_id'               => 'required|exists:organs,id',
            'data_source_id'         => 'nullable|exists:data_sources,id',
            'category_id'            => 'nullable|exists:categories,id',
            'disease_subtype'        => 'nullable|string|max:200',
            'stain_id'               => 'nullable|exists:stains,id',
            'stain_marker'           => 'nullable|string|max:100',
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
            'stain_id'                 => $request->stain_id ?: null,
            'stain_marker'             => $request->stain_marker,
            'entity_submitter_id'      => $request->entity_submitter_id,
            'file_name'                => $request->file_name,
            'data_format'              => $request->data_format,
            'training_phase'           => $request->training_phase,
            'storage_status'           => $request->storage_status,
            'quality_status'           => $request->quality_status,
            'quality_rejection_reason' => $request->quality_rejection_reason,
            'is_usable'                => $request->boolean('is_usable'),
        ]);

        // Re-link to case if entity_submitter_id / file_name was edited.
        // No-op if already linked or no matching case.
        app(CaseLinker::class)->linkSampleToCase($sample->fresh());

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

    /**
     * Manually run the slide-verification pipeline for a single sample.
     * Triggered by the "Verify Slide" button on the sample-show page.
     *
     * PHASE 1 (synchronous, fast ≈ <1 s):
     *   Runs all metadata-based checks that don't require the WSI file.
     *   Saves results immediately to slide_verifications.
     *
     * PHASE 2 (async, slow — can take minutes):
     *   Dispatches WsiPreviewJob to download the slide from Google Drive,
     *   run OpenSlide + Python, and save the deep WSI checks.
     *   The frontend polls /wsi-preview/status while the job is running.
     *
     * Returns JSON when the request includes Accept: application/json,
     * otherwise falls back to a plain redirect (legacy form POST).
     */
    public function verifySample(
        Sample $sample,
        \App\Services\SlideVerificationService $service,
    ): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse {
        $wantsJson = request()->expectsJson();

        // ── Force-link slide to its clinical case (TCGA submitter matching).
        //    Runs on every Verify-Slide click so manually-fixed entity_submitter_id
        //    or newly-imported clinical records get reconciled retroactively.
        $this->linkSampleToCase($sample);

        // ── Phase 1: metadata-based checks (no file download) ────────────────
        try {
            $verification = $service->verify($sample);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error(
                "[verifySample] Sample #{$sample->id} Phase 1 failed: " . $e->getMessage()
            );
            if ($wantsJson) {
                return response()->json(['error' => 'Phase 1 failed: ' . $e->getMessage()], 500);
            }
            return back()->with('error', 'Verification failed: ' . $e->getMessage());
        }

        // ── Phase 2: deep WSI inspection via queue job ────────────────────────
        $hasDriveSource = $sample->file_id || $sample->wsi_remote_path || $sample->storage_path;
        $phase2Queued   = false;

        if ($hasDriveSource) {
            $cacheKey = "wsi_preview:{$sample->id}";
            // Reset cache so the frontend shows "pending" immediately
            \Illuminate\Support\Facades\Cache::put($cacheKey, [
                'status' => 'pending',
                'error'  => null,
            ], 7200);

            \App\Jobs\WsiPreviewJob::dispatch($sample->id);
            $phase2Queued = true;
        }

        if ($wantsJson) {
            return response()->json([
                'success'      => true,
                'phase1_done'  => true,
                'phase2_queued'=> $phase2Queued,
                'status_url'   => $phase2Queued
                    ? route('admin.samples.wsi-preview.status', $sample)
                    : null,
                'message'      => $phase2Queued
                    ? 'Phase 1 complete. Deep WSI analysis queued (download → OpenSlide → Python).'
                    : 'Verification complete (metadata only — no Drive source found).',
            ]);
        }

        $msg = $phase2Queued
            ? 'Phase 1 complete. Deep WSI analysis has been queued and will run in the background.'
            : match ($verification->verification_status) {
                'passed'  => 'All metadata checks passed.',
                'failed'  => 'Verification failed — see the details below.',
                default   => 'Verification ran — some checks are still pending.',
            };

        return redirect()->route('admin.samples.show', $sample)->with('success', $msg);
    }

    /**
     * Inline-edit a single field on the slide_verifications record.
     * Called by AJAX PATCH from the sample-show verification card.
     */
    public function updateVerification(
        \Illuminate\Http\Request $request,
        Sample $sample
    ): \Illuminate\Http\JsonResponse {
        $verification = $sample->slideVerification;

        if (! $verification) {
            return response()->json(['error' => 'No verification record found'], 404);
        }

        $field = $request->input('field');
        $value = $request->input('value');
        if ($value === '' || $value === null) {
            $value = null;
        }

        $editableFields = [
            'file_path', 'slide_id', 'patient_id', 'case_id', 'project_id',
            'file_extension', 'file_size_mb',
            'open_slide_status', 'file_integrity_status', 'read_test_status',
            'level_count', 'slide_width', 'slide_height',
            'mpp_x', 'mpp_y', 'magnification_power',
            'sample_type', 'stain_type', 'gender', 'age_at_index',
            'label', 'label_status',
            'tissue_area_percent', 'tissue_patch_count',
            'artifact_score', 'blur_score', 'background_ratio',
            'notes',
        ];

        if (! in_array($field, $editableFields, true)) {
            return response()->json(['error' => 'Field not editable'], 422);
        }

        $verification->update([$field => $value]);

        /** @var \App\Services\SlideVerificationService $svc */
        $svc = app(\App\Services\SlideVerificationService::class);
        $svc->recomputeStatus($verification);

        return response()->json([
            'success'             => true,
            'verification_status' => $verification->fresh()->verification_status,
        ]);
    }

    public function output(): View
    {
        return view('admin.output');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Private helpers for GDC linkage during bulk upload
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns the string as-is if it looks like a RFC-4122 UUID, otherwise null.
     * Used to detect GDC file UUID folder names (e.g. 1f7901ec-dc4c-471a-bbc6-753e1a4c969b).
     */
    private function extractGdcUuid(?string $value): ?string
    {
        if (!$value) return null;
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)
            ? strtolower($value)
            : null;
    }

    /**
     * Extracts a TCGA slide entity submitter ID from a WSI filename.
     * e.g. "TCGA-BH-A203-11A-04-TSD.45eca4c3-....svs" → "TCGA-BH-A203-11A-04-TSD"
     * Returns null if the filename doesn't follow the TCGA pattern.
     */
    private function extractTcgaSubmitterId(?string $filename): ?string
    {
        if (!$filename) return null;
        if (!preg_match('/^([A-Z0-9]+-[A-Z0-9]+-[A-Z0-9]+(?:-[A-Z0-9]+(?:-\d+)?(?:-[A-Z0-9]+)?)?)/i', $filename, $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * Reduces "TCGA-BH-A203-11A-04-TSD" → "TCGA-BH-A203" (first 3 hyphen segments).
     * Returns null when the value is not a TCGA-style identifier with at least 3 parts.
     */
    private function extractPatientSubmitter(?string $entitySub): ?string
    {
        if (!$entitySub) return null;
        $parts = explode('-', $entitySub);
        if (count($parts) < 3) return null;
        return implode('-', array_slice($parts, 0, 3));
    }

    /**
     * Force-links a Sample (slide) to a PatientCase (clinical case).
     * Thin wrapper around CaseLinker::linkSampleToCase to keep the
     * existing call sites within this controller untouched.
     */
    private function linkSampleToCase(Sample $sample): bool
    {
        return app(CaseLinker::class)->linkSampleToCase($sample);
    }
}
