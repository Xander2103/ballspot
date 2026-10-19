{{--
    Shared confirm dialogs for club status actions (index rows + detail header).
    Trigger: a button with data-bs-toggle="modal" data-bs-target="#club-status-modal"
    data-action="suspend|archive|reactivate|delete" data-url="…" data-club="Name".
    The form posts to data-url; suspend/archive require a reason.
--}}
<div class="modal fade" id="club-status-modal" tabindex="-1" aria-labelledby="club-status-modal-title" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="#" class="modal-content" id="club-status-form">
            @csrf
            <input type="hidden" name="_method" value="POST" id="club-status-method">
            <div class="modal-header">
                <h2 class="modal-title h5" id="club-status-modal-title">Confirm</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2" id="club-status-modal-text"></p>
                <div id="club-status-reason-wrap">
                    <label for="club-status-reason" class="form-label fw-semibold">Reason <span class="text-danger">*</span></label>
                    <textarea id="club-status-reason" name="reason" class="form-control" rows="2" maxlength="200" minlength="3"></textarea>
                    <div class="form-text">Stored on the club and in the audit log. Visible to platform admins only.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="club-status-submit">Confirm</button>
            </div>
        </form>
    </div>
</div>
@push('scripts')
<script>
(function () {
    var modal = document.getElementById('club-status-modal');
    if (!modal) return;
    var copy = {
        suspend:    { title: 'Suspend club', button: 'Suspend', cls: 'btn-warning', reason: true,
                      text: 'Members keep seeing “{club}” as temporarily unavailable. Nobody can play, join or start competition attempts until it is reactivated.' },
        archive:    { title: 'Archive club', button: 'Archive', cls: 'btn-danger', reason: true,
                      text: '“{club}” disappears from every member’s My Clubs. All history is kept and the club can be reactivated later.' },
        reactivate: { title: 'Reactivate club', button: 'Reactivate', cls: 'btn-success', reason: false,
                      text: '“{club}” becomes active again: members can play and join with valid codes.' },
        'delete':   { title: 'Delete club permanently', button: 'Delete permanently', cls: 'btn-danger', reason: false,
                      text: 'Only possible for a club created by mistake (no packs, competitions, photos or joined members). “{club}” and its codes are removed for good.' }
    };
    modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) return;
        var c = copy[trigger.getAttribute('data-action')] || copy.reactivate;
        var name = trigger.getAttribute('data-club') || 'this club';
        document.getElementById('club-status-form').setAttribute('action', trigger.getAttribute('data-url'));
        document.getElementById('club-status-method').value = trigger.getAttribute('data-action') === 'delete' ? 'DELETE' : 'POST';
        document.getElementById('club-status-modal-title').textContent = c.title;
        document.getElementById('club-status-modal-text').textContent = c.text.replace('{club}', name);
        var wrap = document.getElementById('club-status-reason-wrap');
        var reason = document.getElementById('club-status-reason');
        wrap.style.display = c.reason ? '' : 'none';
        reason.required = c.reason;
        reason.value = '';
        var btn = document.getElementById('club-status-submit');
        btn.textContent = c.button;
        btn.className = 'btn ' + c.cls;
    });
}());
</script>
@endpush
