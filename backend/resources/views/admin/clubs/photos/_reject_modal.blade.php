{{--
    Reject dialog shared by the review queue and the prepare page.
    Trigger: data-bs-toggle="modal" data-bs-target="#photo-reject-modal" data-url="…" data-photo="#12 · Club".
--}}
<div class="modal fade" id="photo-reject-modal" tabindex="-1" aria-labelledby="photo-reject-modal-title" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="#" class="modal-content" id="photo-reject-form">
            @csrf
            <div class="modal-header">
                <h2 class="modal-title h5" id="photo-reject-modal-title">Reject photo</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2 small" id="photo-reject-modal-text">The photo stays in the club's history as rejected.</p>
                <label for="photo-reject-reason" class="form-label fw-semibold">Reason <span class="text-danger">*</span></label>
                <textarea id="photo-reject-reason" name="reason" class="form-control" rows="3" minlength="3" maxlength="500" required></textarea>
                <div class="form-text">Visible to the club's owners and admins (e.g. "ball not visible", "blurry", "no rights").</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Reject photo</button>
            </div>
        </form>
    </div>
</div>
@push('scripts')
<script>
(function () {
    var modal = document.getElementById('photo-reject-modal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) return;
        document.getElementById('photo-reject-form').setAttribute('action', trigger.getAttribute('data-url'));
        document.getElementById('photo-reject-modal-text').textContent =
            'Reject photo ' + (trigger.getAttribute('data-photo') || '') + '. It stays in the club’s history as rejected.';
        document.getElementById('photo-reject-reason').value = '';
    });
}());
</script>
@endpush
