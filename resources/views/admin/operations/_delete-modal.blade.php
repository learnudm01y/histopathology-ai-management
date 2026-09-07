{{-- Shared by the audit list and an operation page: deleting the record and
     deleting the files it produced are separate decisions, so they are
     separate controls, and the irreversible half is guarded by a typed
     confirmation rather than a single click. --}}
{{-- ── Delete dialog ─────────────────────────────────────────────────────────
     One modal reused by every row. Deleting the record and deleting the files
     are separate decisions, so they are separate controls: the checkbox is off
     by default, and the typed confirmation guards the irreversible half. --}}
<div class="modal fade" id="opDeleteModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form method="POST" id="opDeleteForm">
            @csrf
            @method('DELETE')
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="mdi mdi-alert-outline mr-1"></i>Delete operation
                    </h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        <strong id="opDeleteName" class="d-block text-break"></strong>
                    </p>

                    <div class="custom-control custom-checkbox mb-3">
                        <input type="checkbox" class="custom-control-input" id="opDeleteFiles" name="delete_files" value="1">
                        <label class="custom-control-label" for="opDeleteFiles">
                            <span class="font-weight-medium text-danger">Also delete the files this operation produced</span>
                            <small class="d-block text-muted" id="opDeleteFilesHint"></small>
                        </label>
                    </div>

                    <div class="alert alert-light border py-2 px-3 mb-3" style="font-size:.82rem;">
                        <i class="mdi mdi-shield-check-outline mr-1 text-success"></i>
                        <strong>Your slides are never deleted.</strong>
                        Only the output of this run is removed — the whole-slide images stay exactly where they are,
                        and the slides go back to being untiled so you can run them again.
                    </div>

                    <label class="small text-muted mb-1">Type <strong>DELETE</strong> to confirm</label>
                    <input type="text" name="confirm" class="form-control" placeholder="DELETE" autocomplete="off" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="mdi mdi-delete-outline mr-1"></i>Delete
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    var form  = document.getElementById('opDeleteForm');
    var name  = document.getElementById('opDeleteName');
    var files = document.getElementById('opDeleteFiles');
    var hint  = document.getElementById('opDeleteFilesHint');
    if (!form) return;

    var WHAT = {
        patch_extraction:   'Purges the patch archives this run uploaded to Drive and marks its slides untiled.',
        feature_extraction: 'Purges the feature files this run produced and marks its slides un-extracted.',
        training:           'This run produced no per-slide files, so nothing is purged.'
    };

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.op-delete-btn');
        if (!btn) return;

        form.setAttribute('action', btn.dataset.opUrl);
        name.textContent = btn.dataset.opName;

        // Every dialog opens with the destructive option off and the box empty,
        // so a previous deletion can never pre-arm the next one.
        files.checked = false;
        form.querySelector('[name="confirm"]').value = '';
        hint.textContent = WHAT[btn.dataset.opType] || '';

        if (window.jQuery) {
            window.jQuery('#opDeleteModal').modal('show');
        }
    });
}());
</script>
@endpush
