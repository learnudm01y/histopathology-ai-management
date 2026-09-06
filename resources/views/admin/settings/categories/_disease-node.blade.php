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

    @if($subtype->is_active)
        <span class="badge badge-success mr-2" style="font-size:.7rem;">Active</span>
    @else
        <span class="badge badge-secondary mr-2" style="font-size:.7rem;">Inactive</span>
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

@if($children->isNotEmpty() || $canNest)
<div class="tree-subtypes-wrap tree-subtypes-wrap-nested">
    @foreach($children as $child)
        @include('admin.settings.categories._disease-node', [
            'cat' => $cat, 'subtype' => $child, 'depth' => $depth + 1,
        ])
    @endforeach

    @if($canNest)
        @include('admin.settings.categories._disease-add', ['cat' => $cat, 'parent' => $subtype])
    @endif
</div>
@endif
