{{-- Content safety reminder: compact, but the message itself is unchanged.
     The dismiss is Bootstrap's client-side close — it returns on the next
     page load on purpose, so the reminder can never be silenced for good. --}}
<div class="alert alert-warning alert-dismissible fade show d-flex align-items-center gap-2 py-1 px-3 mb-3" role="alert" style="font-size:.8rem;">
    <span aria-hidden="true">⚠️</span>
    <div class="flex-grow-1">
        <strong>Content safety reminder:</strong>
        Challenge images and data are stored in the database and <code>storage/app/public/</code>.
        Run <code>php artisan ballspot:backup-content</code> before any destructive database commands
        (<code>migrate:fresh</code>, <code>migrate:reset</code>, etc.) to avoid losing real content.
        <a href="/admin/diagnostics" class="alert-link">Learn more</a>
    </div>
    <button type="button" class="btn-close p-2 ms-2" data-bs-dismiss="alert" aria-label="Dismiss reminder for this page view"></button>
</div>
