{{--
    One disease row plus everything nested under it. Diseases recurse
    ("Malignant" → "Infiltrating ductal carcinoma"), so this partial includes
    itself for each child.

    $cat     — the clinical group the whole branch belongs to
    $subtype — the disease being rendered
    $depth   — 1 for a disease sitting directly under the group
--}}
@php
    $children = $subtype->relationLoaded('childrenRecursive')
        ? $subtype->childrenRecursive
        : $subtype->children;
    $canNest  = $depth < \App\Models\DiseaseSubtype::MAX_DEPTH;

    // A slide is filed against the most specific disease available, so a coarse
    // disease owns its branch's slides through its refinements. Leaves report
    // their own row; parents report the whole subtree and flag anything still
    // sitting on the coarse node itself.
    $ownSlides    = (int) ($subtype->samples_count ?? 0);
    $branchSlides = $subtype->branchCount('samples_count');
    $branchStored = $subtype->branchCount('stored_samples_count');

    $slideFilter = array_filter([
        'organ_id'           => $subtype->organ_id,
        'disease_subtype_id' => $subtype->id,
        'subtree'            => $children->isNotEmpty() ? 1 : null,
    ]);
    $slideLink   = route('admin.samples', $slideFilter);
    $storedLink  = route('admin.samples', $slideFilter + ['storage_status' => 'available']);
@endphp

<div class="tree-subtype-row {{ $depth > 1 ? 'tree-subtype-row-nested' : '' }}">
    <i class="mdi {{ $children->isEmpty() ? 'mdi-circle-medium' : 'mdi-file-tree' }} tree-subtype-icon"
       title="{{ $children->isEmpty()
            ? 'Disease — a leaf, used directly as a training class'
            : 'Disease — refined by ' . $children->count() . ' finer disease(s)' }}"></i>
    <span class="tree-subtype-name">{{ $subtype->name }}</span>

    @if($children->isNotEmpty())
        <span class="badge badge-light border mr-2" style="font-size:.7rem;"
              title="Finer diseases nested under this one">{{ $children->count() }}</span>
    @endif

    {{-- ── Slides filed under this disease ── --}}
    <a href="{{ $slideLink }}"
       class="badge tree-count-badge {{ $branchSlides > 0 ? 'badge-primary' : 'badge-light border text-muted' }} mr-1"
       title="{{ $children->isEmpty()
            ? $branchSlides . ' slide(s) are filed under “' . $subtype->name . '”. Click to open them.'
            : $branchSlides . ' slide(s) in this branch — “' . $subtype->name . '” and every finer disease under it. Click to open them.' }}">
        <i class="mdi mdi-image-multiple"></i> {{ number_format($branchSlides) }}
    </a>

    {{-- How many of those actually reached storage — the number that decides
         whether the class is trainable today. --}}
    <a href="{{ $storedLink }}"
       class="badge tree-count-badge {{ $branchStored > 0 ? 'badge-success' : 'badge-light border text-muted' }} mr-2"
       title="{{ $branchStored }} of {{ $branchSlides }} slide(s) are downloaded and stored on Drive. Click to open them.">
        <i class="mdi mdi-cloud-check"></i> {{ number_format($branchStored) }}
    </a>

    {{-- Slides parked on a coarse disease that has since been refined: they are
         labelled less precisely than the taxonomy now allows and need re-filing. --}}
    @if($children->isNotEmpty() && $ownSlides > 0)
        <a href="{{ route('admin.samples', ['organ_id' => $subtype->organ_id, 'disease_subtype_id' => $subtype->id]) }}"
           class="badge badge-warning mr-2 tree-count-badge"
           title="{{ $ownSlides }} slide(s) stop at “{{ $subtype->name }}” even though finer diseases exist under it — re-file them against the exact disease.">
            {{ number_format($ownSlides) }} coarse
        </a>
    @endif

    @if($subtype->is_active)
        <span class="badge badge-success mr-2" style="font-size:.7rem;">Active</span>
    @else
        <span class="badge badge-secondary mr-2" style="font-size:.7rem;">Inactive</span>
    @endif

    @if($canNest)
        <button type="button" class="btn btn-outline-secondary btn-sm mr-1" style="padding:.2rem .5rem;"
                title="Add a finer disease under “{{ $subtype->name }}”"
                onclick="toggleAddForm('sub-{{ $subtype->id }}', this)">
            <i class="mdi mdi-plus" style="font-size:.85rem;"></i>
        </button>
    @endif

    <a href="{{ route('admin.settings.subtypes.edit', [$cat, $subtype]) }}"
       class="btn btn-outline-primary btn-sm mr-1" style="padding:.2rem .5rem;">
        <i class="mdi mdi-pencil" style="font-size:.85rem;"></i>
    </a>

    <form action="{{ route('admin.settings.subtypes.destroy', [$cat, $subtype]) }}"
          method="POST" class="d-inline"
          onsubmit="return confirm('Delete disease \'{{ addslashes($subtype->name) }}\'?')">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-outline-danger btn-sm" style="padding:.2rem .5rem;"
                {{ $children->isNotEmpty() ? 'disabled title="Has finer diseases nested under it"' : '' }}>
            <i class="mdi mdi-delete" style="font-size:.85rem;"></i>
        </button>
    </form>
</div>

{{-- Opened by the "+" on the row above; takes no space while closed. --}}
@if($canNest)
    @include('admin.settings.categories._disease-add', ['cat' => $cat, 'parent' => $subtype])
@endif

@if($children->isNotEmpty())
<div class="tree-subtypes-wrap tree-subtypes-wrap-nested">
    @foreach($children as $child)
        @include('admin.settings.categories._disease-node', [
            'cat' => $cat, 'subtype' => $child, 'depth' => $depth + 1,
        ])
    @endforeach
</div>
@endif
