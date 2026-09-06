{{--
    Inline "add a disease" form. Used at the clinical-group level ($parent null)
    and under any disease, which is the only difference between creating a
    top-level disease and refining an existing one.

    The form stays collapsed and is opened by the "+" button on the row it
    belongs to — left permanently open it reserved a full row of blank space
    under every disease, which pushed the tree apart far more than the diseases
    themselves did. It re-opens by itself when its own submission failed, so a
    validation message is never hidden behind a closed panel.

    $cat    — the clinical group the new disease lands in
    $parent — DiseaseSubtype it refines, or null
--}}
@php
    $formKey = $parent ? 'sub-' . $parent->id : 'cat-' . $cat->id;
    $failed  = $errors->any() && old('form_key') === $formKey;
@endphp
<div class="tree-add-slot {{ $parent ? 'tree-add-slot-nested' : '' }}"
     id="add-{{ $formKey }}" @if(! $failed) hidden @endif>
    <div class="tree-add-row">
        <form action="{{ route('admin.settings.subtypes.store', $cat) }}"
              method="POST"
              class="d-flex align-items-center w-100">
            @csrf
            <input type="hidden" name="form_key" value="{{ $formKey }}">
            <input type="hidden" name="category_id" value="{{ $cat->id }}">
            @if($parent)
                <input type="hidden" name="parent_id" value="{{ $parent->id }}">
            @endif
            <i class="mdi mdi-plus-circle-outline mr-2 text-muted"></i>
            <input type="text" name="name"
                   class="form-control form-control-sm mr-2 {{ $failed ? 'is-invalid' : '' }}"
                   placeholder="{{ $parent ? 'Finer disease under “' . $parent->name . '”…' : 'New disease name…' }}"
                   style="max-width:300px;"
                   value="{{ $failed ? old('name') : '' }}">
            @if($failed)
                <span class="text-danger small mr-2">{{ $errors->first('name') ?: $errors->first('parent_id') }}</span>
            @endif
            <button type="submit" class="btn btn-outline-primary btn-sm">
                <i class="mdi mdi-plus mr-1"></i>Add
            </button>
            <button type="button" class="btn btn-link btn-sm text-muted"
                    onclick="toggleAddForm('{{ $formKey }}')">Cancel</button>
        </form>
    </div>
</div>
