{{--
    One disease row of the case breakdown, plus everything nested under it.
    Diseases recurse ("Malignant" → "Infiltrating ductal carcinoma"), so this
    partial includes itself for each child.

    $subtype — the disease being rendered
    $depth   — 1 for a disease sitting directly under the clinical group
    $counts  — App\Support\CaseTaxonomyCounts
    $carry   — the non-taxonomy filters to keep when drilling in
--}}
@php
    $children = $subtype->relationLoaded('childrenRecursive')
        ? $subtype->childrenRecursive
        : $subtype->children;

    // A case is filed against the most specific disease its slides carry, so a
    // coarse disease answers for its whole branch. These are DISTINCT cases,
    // not a sum — one case may appear under several of the diseases below.
    $branchCases = $counts->diseaseBranch($subtype);
    $ownCases    = $counts->diseaseOwn($subtype->id);

    $isActive = (string) request('disease_subtype_id') === (string) $subtype->id;

    $link = route('admin.cases.index', $carry + array_filter([
        'organ_id'           => $subtype->organ_id,
        'category_id'        => $subtype->category_id,
        'disease_subtype_id' => $subtype->id,
        'subtree'            => $children->isNotEmpty() ? 1 : null,
    ]));
@endphp

<div class="tree-subtype-row {{ $depth > 1 ? 'tree-subtype-row-nested' : '' }} {{ $isActive ? 'tree-node-active' : '' }}">
    <i class="mdi {{ $children->isEmpty() ? 'mdi-circle-medium' : 'mdi-file-tree' }} tree-subtype-icon"></i>
    <span class="tree-subtype-name">{{ $subtype->name }}</span>

    @if($children->isNotEmpty())
        <span class="badge badge-light border mr-2" style="font-size:.7rem;"
              title="Finer diseases nested under this one">{{ $children->count() }}</span>
    @endif

    <a href="{{ $link }}"
       class="badge tree-count-badge {{ $branchCases > 0 ? 'badge-primary' : 'badge-light border text-muted' }} mr-1"
       title="{{ $children->isEmpty()
            ? $branchCases . ' case(s) have a slide filed under “' . $subtype->name . '”. Click to list them.'
            : $branchCases . ' case(s) in this branch — “' . $subtype->name . '” and every finer disease under it. Click to list them.' }}">
        <i class="mdi mdi-account-multiple-outline"></i> {{ number_format($branchCases) }}
    </a>

    {{-- Cases parked on a coarse disease that has since been refined: their
         slides are labelled less precisely than the taxonomy now allows. --}}
    @if($children->isNotEmpty() && $ownCases > 0)
        <a href="{{ route('admin.cases.index', $carry + array_filter([
                'organ_id'           => $subtype->organ_id,
                'category_id'        => $subtype->category_id,
                'disease_subtype_id' => $subtype->id,
           ])) }}"
           class="badge badge-warning tree-count-badge mr-1"
           title="{{ $ownCases }} case(s) stop at “{{ $subtype->name }}” even though finer diseases exist under it.">
            {{ number_format($ownCases) }} coarse
        </a>
    @endif
</div>

@if($children->isNotEmpty())
<div class="tree-subtypes-wrap tree-subtypes-wrap-nested">
    @foreach($children as $child)
        @include('admin.cases._disease-node', [
            'subtype' => $child, 'depth' => $depth + 1, 'counts' => $counts, 'carry' => $carry,
        ])
    @endforeach
</div>
@endif
